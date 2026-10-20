<?php
namespace App\Services\Feed;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FeedService
{
    private const COLUMNS = ['id','document_id','feed_author_id','feed_owner_id','feed_page_id','feed_created_at_ms','feed_tags','feed_revision','feed_popularity'];

    public function items(User $user, array $ids): array
    {
        $rows=(new FeedAudience($user))->query()->whereIn('document_id',$ids)->get(self::COLUMNS)->all();
        $objects=(new FeedObjectCache)->get($rows);
        return array_values(array_filter(array_map(fn($id)=>$objects[$id] ?? null,$ids)));
    }

    public function page(User $user, ?string $cursor, int $limit): array
    {
        $audience=new FeedAudience($user);

        $profile=DB::table('feed_affinities')
            ->where('user_id',$user->id)
            ->first(['tag_scores','author_scores']);

        $tagAffinity=json_decode($profile->tag_scores ?? '{}',true) ?: [];
        $authorAffinity=json_decode($profile->author_scores ?? '{}',true) ?: [];

        $result=DB::transaction(function() use(
            $user,
            $cursor,
            $limit,
            $audience,
            $tagAffinity,
            $authorAffinity
        ): array {
            if (!$cursor) {
                $cursor=(string)Str::uuid();
                $state=['snapshot_id'=>(int)DB::table('firebase_documents')->max('id'),'snapshot_ms'=>now()->getTimestampMs(),'after'=>null,'pending'=>[],'ahead'=>[],'ended'=>false,'position'=>0,'limit'=>$limit];
                DB::table('feed_cursors')->insert(['id'=>$cursor,'user_id'=>$user->id,'state'=>json_encode($state),'expires_at'=>now()->addMinutes(config('feed.cursor_ttl_minutes',120))->toIso8601String()]);
            }
            $record=DB::table('feed_cursors')->where('id',$cursor)->where('user_id',$user->id)->lockForUpdate()->first();
            abort_unless($record && now()->lt($record->expires_at),410,'Feed cursor expired. Refresh the feed.');
            if ($record->result) return json_decode($record->result,true);
            $state=json_decode($record->state,true);
            $limit=(int)$state['limit'];
            $pending=$state['pending'];
            $pool=max($limit*5,(int)config('feed.candidate_pool',200));
            $room=$pool-count($pending);
            if (!$state['ended'] && $room>0) {
                $query=$audience->query()->where('id','<=',$state['snapshot_id'])->where('feed_created_at_ms','<=',$state['snapshot_ms']);
                if ($state['ahead']) $query->whereNotIn('document_id',array_keys($state['ahead']));
                if ($state['after']) {
                    [$time,$id]=$state['after'];
                    $query->where(fn($q)=>$q->where('feed_created_at_ms','<',$time)->orWhere(fn($q)=>$q->where('feed_created_at_ms',$time)->where('document_id','<',$id)));
                }
                // Composite keyset scan, with per-author rank available to the
                // diversity stage. Never discard rows ranked beyond the cap.
                $rows=$query->select(self::COLUMNS)->selectRaw("CASE WHEN COALESCE(NULLIF(payload->>'commentCount',''),NULLIF(payload->>'comments_count',''),NULLIF(payload#>>'{metrics,comments}',''),NULLIF(payload->>'comments',''),'0') ~ '^[0-9]{1,12}$' THEN COALESCE(NULLIF(payload->>'commentCount',''),NULLIF(payload->>'comments_count',''),NULLIF(payload#>>'{metrics,comments}',''),NULLIF(payload->>'comments',''),'0')::numeric ELSE 0 END AS feed_comment_count")->selectRaw('ROW_NUMBER() OVER (PARTITION BY feed_author_id ORDER BY feed_created_at_ms DESC, document_id DESC) AS author_rank')
                    ->orderByDesc('feed_created_at_ms')->orderByDesc('document_id')->limit($room+1)->get();
                $state['ended']=$rows->count()<=$room;
                $scanned=$rows->take($room);
                if ($last=$scanned->last()) $state['after']=[(int)$last->feed_created_at_ms,(string)$last->document_id];
                $pending=array_values(array_unique([...$pending,...$scanned->pluck('document_id')->all()]));
                if ($state['after']) foreach ($state['ahead'] as $id=>$time) {
                    if ($time>$state['after'][0] || ($time===$state['after'][0] && strcmp((string)$id,$state['after'][1])>=0)) unset($state['ahead'][$id]);
                }
            }
            $rows=$audience->query()->whereIn('document_id',$pending)->select(self::COLUMNS)->selectRaw("CASE WHEN COALESCE(NULLIF(payload->>'commentCount',''),NULLIF(payload->>'comments_count',''),NULLIF(payload#>>'{metrics,comments}',''),NULLIF(payload->>'comments',''),'0') ~ '^[0-9]{1,12}$' THEN COALESCE(NULLIF(payload->>'commentCount',''),NULLIF(payload->>'comments_count',''),NULLIF(payload#>>'{metrics,comments}',''),NULLIF(payload->>'comments',''),'0')::numeric ELSE 0 END AS feed_comment_count")->get();
            // Reach past a burst from one author without dropping its deferred
            // posts. Ahead IDs stay excluded from the chronological scan until
            // that scan passes their composite timestamp/ID position.
            $dominant=$rows->groupBy('feed_author_id')->filter(fn($group)=>$group->count()>3)->keys()->all();
            $extraRoom=min(60,$pool+60-$rows->count(),2000-count($state['ahead']));
            if ($dominant && $extraRoom>0 && !$state['ended'] && $state['after']) {
                [$time,$id]=$state['after'];
                $lookahead=$audience->query()->where('id','<=',$state['snapshot_id'])->whereNotIn('feed_author_id',$dominant)
                    ->whereNotIn('document_id',[...$pending,...array_keys($state['ahead'])])
                    ->where(fn($q)=>$q->where('feed_created_at_ms','<',$time)->orWhere(fn($q)=>$q->where('feed_created_at_ms',$time)->where('document_id','<',$id)))
                    ->select(self::COLUMNS)->selectRaw("CASE WHEN COALESCE(NULLIF(payload->>'commentCount',''),NULLIF(payload->>'comments_count',''),NULLIF(payload#>>'{metrics,comments}',''),NULLIF(payload->>'comments',''),'0') ~ '^[0-9]{1,12}$' THEN COALESCE(NULLIF(payload->>'commentCount',''),NULLIF(payload->>'comments_count',''),NULLIF(payload#>>'{metrics,comments}',''),NULLIF(payload->>'comments',''),'0')::numeric ELSE 0 END AS feed_comment_count")->selectRaw('ROW_NUMBER() OVER (PARTITION BY feed_author_id ORDER BY feed_created_at_ms DESC, document_id DESC) AS author_rank');
                $extra=DB::query()->fromSub($lookahead,'ranked')->where('author_rank','<=',3)
                    ->orderByDesc('feed_created_at_ms')->orderByDesc('document_id')->limit($extraRoom)->get();
                foreach ($extra as $row) $state['ahead'][$row->document_id]=(int)$row->feed_created_at_ms;
                $rows=$rows->concat($extra);
            }
            // Add a bounded worldwide discovery pool beyond the latest local
            // burst. Track ahead IDs using the existing cursor deduplication.
            $globalRoom=min(24,2000-count($state['ahead']));
            if (!$state['ended'] && $globalRoom>0) {
                $global=$audience->query()->where('feed_audience','public')
                    ->where('id','<=',$state['snapshot_id'])->where('feed_created_at_ms','<=',$state['snapshot_ms'])
                    ->whereNotIn('document_id',[...$rows->pluck('document_id')->all(),...array_keys($state['ahead'])]);
                if ($state['after']) {
                    [$time,$id]=$state['after'];
                    $global->where(fn($q)=>$q->where('feed_created_at_ms','<',$time)->orWhere(fn($q)=>$q->where('feed_created_at_ms',$time)->where('document_id','<',$id)));
                }
                $global->select(self::COLUMNS)->selectRaw("CASE WHEN COALESCE(NULLIF(payload->>'commentCount',''),NULLIF(payload->>'comments_count',''),NULLIF(payload#>>'{metrics,comments}',''),NULLIF(payload->>'comments',''),'0') ~ '^[0-9]{1,12}$' THEN COALESCE(NULLIF(payload->>'commentCount',''),NULLIF(payload->>'comments_count',''),NULLIF(payload#>>'{metrics,comments}',''),NULLIF(payload->>'comments',''),'0')::numeric ELSE 0 END AS feed_comment_count")->selectRaw("ROW_NUMBER() OVER (PARTITION BY COALESCE(NULLIF(payload->>'country',''), (SELECT p.payload->>'country' FROM firebase_documents p WHERE p.collection_path='user_data' AND p.document_id=firebase_documents.feed_owner_id LIMIT 1),feed_author_id) ORDER BY feed_created_at_ms DESC, document_id DESC) AS country_rank");
                $extra=DB::query()->fromSub($global,'worldwide')->where('country_rank','<=',2)->orderBy('country_rank')->orderByDesc('feed_created_at_ms')->limit($globalRoom)->get();
                foreach ($extra as $row) $state['ahead'][$row->document_id]=(int)$row->feed_created_at_ms;
                $rows=$rows->concat($extra);
            }
            $candidates=[];

            foreach ($rows as $row) {
                $tags=json_decode($row->feed_tags ?: '[]',true) ?: [];
                $reason=$audience->reason($row);
                $author=(string)($row->feed_author_id ?? '');

                /*
                 * TAG AFFINITY
                 *
                 * Unlike the previous algorithm we keep negative scores.
                 * Repeatedly skipped subjects therefore lose ranking strength.
                 */
                $tagSignal=0.0;

                foreach ($tags as $tag) {
                    $key=mb_strtolower(trim((string)$tag));

                    if ($key==='') continue;

                    $tagSignal+=(float)($tagAffinity[$key] ?? 0);
                }

                if (count($tags)>0) {
                    $tagSignal=$tagSignal/max(1,sqrt(count($tags)));
                }

                $tagSignal=max(-2.5,min(3.0,$tagSignal));

                /*
                 * AUTHOR AFFINITY
                 *
                 * This is what makes the feed learn which people the user
                 * actually watches, likes, comments on, saves and shares.
                 */
                $authorRaw=(float)($authorAffinity[$author] ?? 0);
                $authorSignal=max(-3.0,min(4.0,$authorRaw/4));

                /*
                 * Relationship is important but does not force an entirely
                 * chronological friends-only feed.
                 */
                $relationship=match($reason) {
                    'self' => 4.8,
                    'friend' => 4.5,
                    'following' => 3.2,
                    default => 0.0,
                };

                $ageHours=max(
                    0,
                    ($state['snapshot_ms']-(int)$row->feed_created_at_ms)/3600000
                );
                $ageDays=$ageHours/24;

                // Engagement is reactions plus weighted comments. Affinity
                // combines the followed/friend relationship with learned author
                // and topic preferences, all clamped to keep scores bounded.
                $interactionWeight=max(0,(float)$row->feed_popularity)
                    +2*max(0,(float)($row->feed_comment_count ?? 0));
                $relationshipAffinity=match($reason) {
                    'friend','following' => 2.5,
                    'tagged' => 1.5,
                    default => 1.0,
                };
                $affinity=max(0.25,min(8.0,
                    $relationshipAffinity+$authorSignal+$tagSignal
                ));
                $score=$interactionWeight*$affinity/($ageHours+2);

                // Recent friend and followed-page posts lead the feed, while
                // the blueprint's relevance score orders each remaining tier.
                $recentConnection=in_array($reason,['friend','following'],true) && $ageDays<=7;

                $candidates[]=[
                    'id'=>(string)$row->document_id,
                    'author'=>$author,
                    'timestamp'=>(int)$row->feed_created_at_ms,
                    'reason'=>$reason,
                    'suggestion'=>$reason==='discovery',
                    'recent_connection'=>$recentConnection,
                    'score'=>$score,
                ];
            }
            $mixed=(new FeedMixer)->page($candidates,$limit,$state);
            $nextState=array_merge($state,array_diff_key($mixed,['items'=>true]));
            $hasMore=boolval($mixed['pending']) || !$state['ended'];
            $next=$hasMore ? (string)Str::uuid() : null;
            if ($next) DB::table('feed_cursors')->insert(['id'=>$next,'user_id'=>$user->id,'state'=>json_encode($nextState),'expires_at'=>$record->expires_at]);
            $result=['items'=>$mixed['items'],'nextCursor'=>$next,'hasMore'=>$hasMore,'snapshotAt'=>$state['snapshot_ms']];
            DB::table('feed_cursors')->where('id',$cursor)->update(['result'=>json_encode($result)]);
            return $result;
        },3);
        // Recheck visibility even on cursor replay. Deletion/private edits must
        // not leak through the shared Redis cache or a previously ranked page.
        $rows=$audience->query()->whereIn('document_id',array_column($result['items'],'id'))->get(self::COLUMNS)->all();
        $objects=(new FeedObjectCache)->get($rows);
        $allowed=array_column($rows,null,'document_id');
        $items=[];
        foreach ($result['items'] as $entry) {
            if (!isset($objects[$entry['id']],$allowed[$entry['id']])) continue;
            $items[]=array_merge($objects[$entry['id']],$entry,['createdAtMs'=>(int)$allowed[$entry['id']]->feed_created_at_ms]);
        }
        return ['items'=>$items,'nextCursor'=>$result['nextCursor'],'hasMore'=>$result['hasMore'],'snapshotAt'=>$result['snapshotAt']];
    }
}
