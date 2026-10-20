<?php
namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\Outbox;

class PageFollowController
{
    private function keys(Request $request): array
    {
        $u=$request->user();
        return array_values(array_unique(array_filter([(string)$u->firebase_uid,(string)$u->legacy_firebase_uid,(string)$u->id])));
    }

    private function page(string $id): object
    {
        $page=DB::table('firebase_documents')->where(['collection_path'=>'pages','document_id'=>$id])->first();
        abort_unless($page,404,'Page not found.');
        return $page;
    }

    private function actor(Request $r): array
    {
        $data=$r->validate(['actor_type'=>'sometimes|in:user,page','actor_id'=>'sometimes|string|max:191']);
        $type=$data['actor_type'] ?? 'user';
        $keys=$this->keys($r);
        if ($type==='user') return [$type,$keys[0]];
        $id=$data['actor_id'] ?? '';
        $page=json_decode($this->page($id)->payload,true) ?: [];
        abort_unless(array_intersect($keys,array_filter([$page['ownerId'] ?? null,$page['userId'] ?? null,$page['createdBy'] ?? null])),403,'You do not manage this page.');
        return [$type,$id];
    }

    private function put(string $collection,string $id,array $data): void
    {
        $now=now()->toISOString();
        $row=DB::table('firebase_documents')->where(['collection_path'=>$collection,'document_id'=>$id])->first();
        DB::table('firebase_documents')->updateOrInsert(['collection_path'=>$collection,'document_id'=>$id],['parent_document_path'=>str_contains($collection,'/') ? substr($collection,0,strrpos($collection,'/')) : null,'payload'=>json_encode(array_merge($row ? (json_decode($row->payload,true) ?: []) : [],$data)),'firebase_updated_at'=>$now,'updated_at'=>$now,'created_at'=>$row?->created_at ?? $now]);
    }

    public function follow(Request $r,string $id)
    {
        return $this->change($r,$id,true);
    }

    public function unfollow(Request $r,string $id)
    {
        return $this->change($r,$id,false);
    }

    private function change(Request $r,string $id,bool $enabled)
    {
        $this->page($id);
        [$type,$actor]=$this->actor($r);
        abort_if($type==='page' && $actor===$id,422,'A page cannot follow itself.');
        return DB::transaction(function() use($r,$id,$enabled,$type,$actor) {
            DB::select('select pg_advisory_xact_lock(hashtextextended(?,0))',['page-follow:'.$id]);
            $keys=$this->keys($r);
            $now=now()->toISOString();
            $data=['targetId'=>$id,'targetType'=>'page','followerId'=>$actor,'followerType'=>$type,'managedBy'=>$keys[0],'status'=>$enabled ? 'active' : 'inactive','updatedAt'=>$now,'updatedAtMs'=>now()->getTimestampMs()];
            // Retire alternate identifiers so an old numeric UID cannot count twice.
            if ($type==='user') foreach (array_diff($keys,[$actor]) as $alias) {
                foreach (['following','followers'] as $collection) {
                    $rows=DB::table('firebase_documents')->where('collection_path',$collection)->where('payload->targetId',$id)->where('payload->followerId',$alias)->get();
                    foreach ($rows as $row) $this->put($collection,$row->document_id,['status'=>'inactive']);
                }
            }
            $this->put('followers',$id.'_'.$actor,$data);
            $this->put('following',$actor.'_'.$id,$data);
            if ($type==='user') DB::table('following')->updateOrInsert(['public_id'=>$r->user()->id.':page:'.$id],['user_id'=>$r->user()->id,'parent_type'=>'page','parent_id'=>$id,'status'=>$enabled ? 'active':'inactive','data'=>json_encode($data),'created_at'=>$now,'updated_at'=>$now]);
            if (!$enabled) foreach ($keys as $key) $this->put('users/'.$key.'/post_alerts',$id,['targetId'=>$id,'targetType'=>'page','enabled'=>false,'updatedAt'=>$now]);
            $count=DB::table('firebase_documents')->where('collection_path','followers')->where('payload->targetId',$id)->where('payload->targetType','page')->where('payload->status','active')->selectRaw("COUNT(DISTINCT COALESCE(payload->>'followerType','user') || ':' || (payload->>'followerId')) AS total")->value('total');
            $page=json_decode($this->page($id)->payload,true) ?: [];
            $this->put('pages',$id,['followersCount'=>(int)$count,'analytics'=>array_merge((array)($page['analytics'] ?? []),['followers'=>(int)$count]),'updatedAt'=>$now]);
            app(Outbox::class)->record('follow.updated','page',$id,['user_id'=>$r->user()->id,'following'=>$enabled,'followersCount'=>(int)$count]);
            return response()->json(['following'=>$enabled,'followersCount'=>(int)$count]);
        });
    }

    public function notifications(Request $r,string $id)
    {
        $enabled=$r->validate(['enabled'=>'required|boolean'])['enabled'];
        $this->page($id);
        $keys=$this->keys($r);
        return DB::transaction(function() use($r,$id,$enabled,$keys) {
            DB::select('select pg_advisory_xact_lock(hashtextextended(?,0))',['page-follow:'.$id]);
            $following=DB::table('firebase_documents')->where('collection_path','following')->where('payload->targetId',$id)->whereIn('payload->followerId',$keys)->where('payload->status','active')->exists();
            abort_if($enabled && !$following,422,'Follow this page before enabling notifications.');
            $this->put('users/'.$keys[0].'/post_alerts',$id,['targetId'=>$id,'targetType'=>'page','enabled'=>$enabled,'updatedAt'=>now()->toISOString()]);
            return response()->json(['enabled'=>$enabled]);
        });
    }
}
