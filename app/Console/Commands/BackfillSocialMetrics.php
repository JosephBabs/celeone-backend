<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\SocialContent;
use App\Services\SocialMetrics;
use App\Services\SocialNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillSocialMetrics extends Command
{
    protected $signature = 'social:backfill-metrics';

    protected $description = 'Recover known social viewers and historical counters without counting post/reel mirrors twice. Safe to rerun.';

    public function handle(SocialContent $content, SocialMetrics $metrics): int
    {
        $users = User::get(['id', 'firebase_uid', 'legacy_firebase_uid']);
        $userMap = [];
        foreach ($users as $user) {
            foreach (SocialNotification::keys($user) as $key) {
                $userMap[$key] = $user;
            }
        }
        $all = [];
        foreach (DB::table('firebase_documents')->whereIn('collection_path', ['posts', 'reels', 'social_live_sessions'])->get(['collection_path', 'document_id']) as $row) {
            $item = $content->find($row->collection_path === 'social_live_sessions' ? 'lives' : $row->collection_path, $row->document_id);
            if ($item && $content->published($item)) {
                $all[$item['key']] = $item;
            }
        }
        $processed = 0;
        foreach ($all as $item) {
            DB::transaction(function () use ($item, $content, $metrics, $userMap) {
                // Never re-import counters after they have become authoritative.
                if (DB::table('social_content_metrics')->where('content_key', $item['key'])->exists()) {
                    return;
                }
                $aliases = [$item['id']];
                $legacy = 0;
                $data = $item['data'];
                $documents = DB::table('firebase_documents')->where(fn ($q) => $q->where(fn ($q) => $q->where('collection_path', $item['collection'])->where('document_id', $item['id']))->when($item['collection'] === 'posts', fn ($q) => $q->orWhere(fn ($q) => $q->where('collection_path', 'reels')->where(fn ($q) => $q->where('document_id', $item['id'])->orWhere('payload->postId', $item['id'])->orWhere('payload->sourcePostId', $item['id'])))))->get();
                foreach ($documents as $document) {
                    $payload = $content->payload($document);
                    $aliases[] = $document->document_id;
                    $legacy = max($legacy, (int) ($payload['metrics']['views'] ?? 0), (int) ($payload['views_count'] ?? 0), (int) ($payload['viewCount'] ?? 0), (int) ($payload['views'] ?? 0));
                }
                if ($item['collection'] === 'posts') {
                    $post = DB::table('posts')->leftJoin('post_metrics', 'posts.id', '=', 'post_metrics.post_id')->where('firebase_id', $item['id'])->select('posts.id', 'post_metrics.views_count')->first();
                    if ($post) {
                        $aliases[] = (string) $post->id;
                        $legacy = max($legacy, (int) $post->views_count);
                    }
                }
                $aliases = array_values(array_unique($aliases));
                $viewers = [];
                $historicalFeed = [];
                foreach (DB::table('post_views')->whereIn('parent_id', $aliases)->whereIn('parent_type', $item['collection'] === 'social_live_sessions' ? ['lives'] : ['posts', 'reels'])->get() as $view) {
                    $viewers[(string) $view->user_id] = true;
                }
                $collections = [];
                foreach ($aliases as $alias) {
                    foreach ($item['collection'] === 'social_live_sessions' ? ['social_live_sessions'] : ['posts', 'reels'] as $type) {
                        $collections[] = $type.'/'.$alias.'/views';
                    }
                }
                foreach (DB::table('firebase_documents')->whereIn('collection_path', $collections)->get(['document_id', 'payload']) as $view) {
                    $d = $content->payload($view);
                    $viewers[(string) ($d['userId'] ?? $view->document_id)] = true;
                }
                if ($item['collection'] !== 'social_live_sessions') {
                    foreach (DB::table('feed_interactions')->whereIn('post_id', $aliases)->whereIn('type', ['VIEW', 'WATCH'])->get() as $event) {
                        $historicalFeed[] = $event;
                        if ($event->type === 'VIEW' || $event->duration_ms >= 2000) {
                            $viewers[(string) $event->user_id] = true;
                        }
                    }
                }
                $ids = [];
                foreach (array_keys($viewers) as $uid) {
                    if (isset($userMap[$uid]) && ! $metrics->isOwner($userMap[$uid], $item)) {
                        $ids[$userMap[$uid]->id] = true;
                    }
                }
                $legacy = max($legacy, count($ids));
                $metrics->ensure($item, $legacy);
                DB::table('social_content_metrics')->where('content_key', $item['key'])->lockForUpdate()->first();
                foreach (array_keys($ids) as $uid) {
                    DB::table('social_content_viewers')->insertOrIgnore(['content_key' => $item['key'], 'user_id' => $uid, 'first_seen_at' => now()]);
                    DB::table('social_metric_events')->insertOrIgnore(['user_id' => $uid, 'event_id' => 'legacy-view:'.sha1($item['key']), 'content_key' => $item['key'], 'event_type' => 'HISTORICAL_VIEW', 'created_at' => now()]);
                }
                foreach ($historicalFeed as $event) {
                    $user = $userMap[(string) $event->user_id] ?? null;
                    if (! $user || $metrics->isOwner($user, $item)) {
                        continue;
                    }
                    DB::table('social_metric_events')->insertOrIgnore(['user_id' => $user->id, 'event_id' => 'feed:'.$event->event_id, 'content_key' => $item['key'], 'event_type' => $event->type === 'VIEW' ? 'IMPRESSION' : 'WATCH', 'duration_ms' => $event->duration_ms, 'created_at' => $event->created_at]);
                }
                DB::table('social_content_metrics')->where('content_key', $item['key'])->update(['reach_count' => DB::table('social_content_viewers')->where('content_key', $item['key'])->count(), 'impressions_count' => DB::table('social_metric_events')->where('content_key', $item['key'])->where('event_type', 'IMPRESSION')->count(), 'watch_ms' => DB::table('social_metric_events')->where('content_key', $item['key'])->where('event_type', 'WATCH')->sum('duration_ms'), 'updated_at' => now()]);
                $metrics->sync($item);
            }, 3);
            $processed++;
        }
        $this->info('Reconciled '.$processed.' canonical content records. Mirrored reels were not counted twice.');

        return self::SUCCESS;
    }
}
