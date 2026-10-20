<?php
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\User;
use Illuminate\Support\Facades\{DB,Cache};
$prefix='feed-http-'.bin2hex(random_bytes(6));$user=null;$cacheKeys=[];
function requestFeed($method,$path,$token,$body=null) {
    $curl=curl_init('https://api.celeonetv.com/api/v1'.$path);
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>['Accept: application/json','Content-Type: application/json','Authorization: Bearer '.$token]]);
    if ($body!==null) curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($body));
    $text=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
    if ($status!==200) throw new RuntimeException('HTTP '.$status.' '.$path.' '.substr((string)$text,0,200));
    return json_decode($text,true,512,JSON_THROW_ON_ERROR);
}
try {
    $user=User::create(['name'=>'Feed HTTP verification','email'=>$prefix.'@example.invalid','firebase_uid'=>$prefix,'password'=>bin2hex(random_bytes(32))]);
    $token=$user->createToken($prefix)->plainTextToken;
    DB::table('firebase_documents')->insert(['collection_path'=>'posts','document_id'=>$prefix,'payload'=>json_encode(['userId'=>$prefix,'authorId'=>$prefix,'audience'=>'private','createdAtMs'=>now()->getTimestampMs()-1000,'text'=>'verification','tags'=>['music']]),'created_at'=>now(),'updated_at'=>now()]);
    $page=requestFeed('GET','/feed?limit=10',$token);
    if (!isset($page['data'],$page['hasMore']) || !array_key_exists('nextCursor',$page) || !in_array($prefix,array_column($page['data'],'id'))) throw new RuntimeException('Feed envelope or owner visibility incorrect');
    if ($page['nextCursor']) {
        $next=requestFeed('GET','/feed?cursor='.urlencode($page['nextCursor']),$token);
        $retry=requestFeed('GET','/feed?cursor='.urlencode($page['nextCursor']),$token);
        if(array_column($next['data'],'id')!==array_column($retry['data'],'id')) throw new RuntimeException('HTTP cursor retry unstable');
    }
    $items=requestFeed('POST','/feed/items',$token,['ids'=>[$prefix]]);
    if(count($items['data'])!==1) throw new RuntimeException('Visible card refresh failed');
    $event=['actorUid'=>$prefix,'events'=>[['eventId'=>$prefix,'postId'=>$prefix,'type'=>'VIEW','durationMs'=>2000]]];
    if(requestFeed('POST','/feed/interactions',$token,$event)['accepted']!==1 || requestFeed('POST','/feed/interactions',$token,$event)['accepted']!==0) throw new RuntimeException('HTTP interaction dedupe failed');
    echo "PASS public HTTPS Laravel auth, feed envelope, cursor retry, visible-card refresh and interaction tracking.\n";
} finally {
    foreach(DB::table('firebase_documents')->where('document_id',$prefix)->get(['document_id','feed_revision']) as $entry) Cache::store(config('feed.cache_store'))->forget('feed:post:'.$entry->document_id.':'.$entry->feed_revision);
    DB::table('firebase_documents')->where('document_id',$prefix)->delete();
    if ($user) { $user->tokens()->delete();$user->delete(); }
    echo "Synthetic HTTP account, tokens, post and feed records removed.\n";
}
