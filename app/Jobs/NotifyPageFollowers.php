<?php
namespace App\Jobs;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class NotifyPageFollowers implements ShouldQueue
{
    use Queueable;
    public int $tries=5;
    public int $timeout=120;
    public function backoff(): array { return [30,120,300,900]; }
    public function __construct(public string $postId, public int $publisherId) {}

    public function handle(): void
    {
        $post=DB::table('firebase_documents')->where(['collection_path'=>'posts','document_id'=>$this->postId])->first();
        if (!$post || $post->feed_status!=='published' || $post->feed_audience!=='public') return;
        $publisher=User::find($this->publisherId); if (!$publisher) return;
        $isPage=(bool)$post->feed_page_id;
        $target=(string)($isPage ? $post->feed_page_id : $post->feed_author_id);
        $publisherKeys=\App\Services\SocialNotification::keys($publisher);
        if ($isPage) {
            $page=\App\Services\SocialNotification::payload(DB::table('firebase_documents')->where(['collection_path'=>'pages','document_id'=>$target])->first());
            $owners=array_filter([$page['ownerId'] ?? null,$page['userId'] ?? null,$page['createdBy'] ?? null]);
            foreach ($publisherKeys as $key) {
                if (in_array($page['roles'][$key] ?? null, ['owner', 'admin', 'editor'], true)) {
                    $owners[] = $key;
                }
            }
            if (!$publisher->isAdmin() && !array_intersect($owners,$publisherKeys)) return;
            $name=$page['name'] ?? 'A page you follow';
        } else {
            if (!in_array($target,$publisherKeys,true)) return;
            $name=$publisher->name;
        }
        $targetKeys=$isPage?[$target]:$publisherKeys;
        $alerts=DB::table('firebase_documents')->where('collection_path','like','users/%/post_alerts')->whereIn('document_id',$targetKeys)->where('payload->enabled',true)->get();
        foreach ($alerts as $alert) {
            $uid=explode('/',$alert->collection_path)[1] ?? '';
            $user=\App\Services\SocialNotification::user($uid);
            if (!$user || $user->firebase_disabled || $user->id===$publisher->id) continue;
            $keys=\App\Services\SocialNotification::keys($user);
            $follows=DB::table('following')->where('user_id',$user->id)->whereIn('parent_id',$targetKeys)->where('status','active')->exists()
                || DB::table('firebase_documents')->where('collection_path','following')->whereIn('payload->targetId',$targetKeys)->whereIn('payload->followerId',$keys)->where('payload->status','active')->exists();
            if (!$follows) continue;
            $kind=$isPage?'page_post':'user_post';
            app(\App\Services\SocialNotification::class)->deliver($user,['id'=>$kind.'_'.hash('sha256',$this->postId.':'.$user->id),'type'=>$kind,'title'=>$name.' published a post','description'=>'Tap to see the new post.','sourceId'=>$this->postId,'postId'=>$this->postId,'pageId'=>$isPage?$target:'']);
        }
    }

    public static function changed(string $collection,string $id,?object $before,int $publisher): void
    {
        if ($collection!=='posts') return;
        $old=$before ? (json_decode($before->payload,true) ?: []) : [];
        // Only first publication, never metric changes or edits of existing posts.
        if ($before && !in_array($old['status'] ?? 'published',['draft','scheduled'],true)) return;
        $after=DB::table('firebase_documents')->where(['collection_path'=>'posts','document_id'=>$id])->first();
        if ($after && $after->feed_status==='published') self::dispatch($id,$publisher)->afterCommit();
    }
}
