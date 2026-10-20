<?php
// Production smoke test. Synthetic data is rolled back; shared cache entries
// created by the test are explicitly removed. No notifications are sent.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\{DB,Cache};
use App\Models\User;
use App\Services\Feed\{FeedService,FeedInteractions};
function check($condition,$message) { if (!$condition) throw new RuntimeException($message); }
$prefix='feed-check-'.bin2hex(random_bytes(6)); $keys=[];
$cache=Cache::store(config('feed.cache_store'));
$cache->put($prefix,'connected',30); check($cache->get($prefix)==='connected','Redis unavailable'); $cache->forget($prefix);
DB::beginTransaction();
try {
    $viewer=User::create(['name'=>'Feed verification','email'=>$prefix.'@example.invalid','firebase_uid'=>$prefix,'password'=>bin2hex(random_bytes(32))]);
    $other=User::create(['name'=>'Feed verification other','email'=>$prefix.'-other@example.invalid','firebase_uid'=>$prefix.'-other','password'=>bin2hex(random_bytes(32))]);
    $time=now()->getTimestampMs()-10000;
    $seed=function($id,$author,$timestamp) use($prefix) {
        DB::table('firebase_documents')->insert(['collection_path'=>'posts','document_id'=>$id,'payload'=>json_encode(['authorId'=>$author,'userId'=>$prefix,'audience'=>'private','tags'=>['#Music','music'],'text'=>'verification','createdAtMs'=>$timestamp]),'created_at'=>now(),'updated_at'=>now()]);
    };
    for($i=0;$i<230;$i++) $seed($prefix.sprintf('-%03d',$i),$prefix.'-flood',$time);
    foreach(['a','b','c'] as $author) $seed($prefix.'-'.$author,$prefix.'-'.$author,$time-1);
    $row=DB::table('firebase_documents')->where('document_id',$prefix.'-000')->first();
    check($row->feed_owner_id===$prefix && json_decode($row->feed_tags,true)===['music'],'PostgreSQL metadata trigger failed');
    $service=new FeedService;
    $page=$service->page($viewer,null,20);
    $ids=array_column($page['items'],'id');
    foreach(['a','b','c'] as $author) check(in_array($prefix.'-'.$author,$ids),'Diversity lookahead failed');
    $seed($prefix.'-new',$prefix.'-new',$time+1000);
    $next=$page['nextCursor'];$count=0;
    while($next && $count++<100) {
        $page=$service->page($viewer,$next,20);
        check(array_column($page['items'],'id')===array_column($service->page($viewer,$next,20)['items'],'id'),'Cursor replay changed');
        $ids=[...$ids,...array_column($page['items'],'id')];$next=$page['nextCursor'];
    }
    $mine=array_values(array_filter($ids,fn($id)=>str_starts_with($id,$prefix)));
    check(!$next && count($mine)===233 && count(array_unique($mine))===233,'Cursor omitted or duplicated posts');
    check($service->items($other,[$prefix.'-000'])===[],'Private post leaked');
    $cached=$service->items($viewer,[$prefix.'-000']);check(count($cached)===1,'Cache read failed');
    $keys[]='feed:post:'.$row->document_id.':'.$row->feed_revision;
    $payload=json_decode($row->payload,true);$payload['text']='edited';
    DB::table('firebase_documents')->where('id',$row->id)->update(['payload'=>json_encode($payload)]);
    check($service->items($viewer,[$prefix.'-000'])[0]['text']==='edited','Cached edit stale');
    $tracker=new FeedInteractions;
    $event=['eventId'=>$prefix,'postId'=>$prefix.'-000','type'=>'VIEW','durationMs'=>2000];
    check($tracker->record($viewer,[$event])===1 && $tracker->record($viewer,[$event])===0,'Interaction deduplication failed');
    check((json_decode(DB::table('feed_affinities')->where('user_id',$viewer->id)->value('tag_scores'),true)['music'] ?? 0)==1,'Tag affinity failed');
    foreach(DB::table('firebase_documents')->where('document_id','like',$prefix.'%')->get(['document_id','feed_revision']) as $entry) $keys[]='feed:post:'.$entry->document_id.':'.$entry->feed_revision;
    DB::table('firebase_documents')->where('document_id',$prefix.'-000')->delete();
    check($service->items($viewer,[$prefix.'-000'])===[],'Deleted post leaked from cache');
    echo "PASS PostgreSQL trigger, Redis, diversity, 233 unique posts, cursor replay/snapshot, privacy, edit/delete invalidation, interaction deduplication and tag affinity.\n";
} finally {
    try { foreach(DB::table('firebase_documents')->where('document_id','like',$prefix.'%')->get(['document_id','feed_revision']) as $entry) $keys[]='feed:post:'.$entry->document_id.':'.$entry->feed_revision; }
    catch (Throwable) {} finally { DB::rollBack(); }
    foreach(array_unique($keys) as $key) $cache->forget($key);
}
