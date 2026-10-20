<?php
namespace App\Jobs;
use App\Models\User;
use App\Services\SocialNotification as N;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
class NotifyCommentActivity implements ShouldQueue {
    use Queueable;
    public int $tries=5;
    public int $timeout=120;
    public function backoff(): array { return [30,120,300,900]; }
    public function __construct(public string $postId, public string $commentId, public int $actorId) {}
    public function handle(): void {
        $actor=User::find($this->actorId); if (!$actor) return;
        $row=DB::table('firebase_documents')->where('collection_path','posts')->where('document_id',$this->postId)->first();
        $post=N::payload($row);
        if (!$row || in_array($post['status'] ?? 'published',['draft','deleted','hidden','scheduled'],true)) return;
        $comment=N::payload(DB::table('firebase_documents')->where('collection_path','posts/'.$this->postId.'/comments')->where('document_id',$this->commentId)->first());
        if (!$comment) return;
        $targets=[];
        $parentId=(string)($comment['parentId'] ?? '');
        if ($parentId) {
            $parent=N::payload(DB::table('firebase_documents')->where('collection_path','posts/'.$this->postId.'/comments')->where('document_id',$parentId)->first());
            $recipient=N::user((string)($parent['userId'] ?? $parent['authorId'] ?? ''));
            if ($recipient) $targets[$recipient->id]=[$recipient,'reply'];
        }
        $owner=(string)($post['authorOwnerId'] ?? $post['ownerId'] ?? $row->feed_owner_id ?? $post['authorId'] ?? $post['userId'] ?? '');
        $pageId=(string)($row->feed_page_id ?? $post['pageId'] ?? '');
        if ($pageId) { $page=N::payload(DB::table('firebase_documents')->where('collection_path','pages')->where('document_id',$pageId)->first()); $owner=(string)($page['ownerId'] ?? $page['userId'] ?? $page['createdBy'] ?? $owner); }
        $recipient=N::user($owner);
        if ($recipient && !isset($targets[$recipient->id])) $targets[$recipient->id]=[$recipient,'comment'];
        foreach($targets as [$recipient,$kind]) {
            if ($recipient->id===$actor->id) continue;
            $uid=(string)($recipient->firebase_uid ?: $recipient->legacy_firebase_uid ?: $recipient->id);
            app(N::class)->deliver($recipient,['id'=>$kind.'_'.$this->postId.'_'.$this->commentId.'_'.$uid,'type'=>$kind,'title'=>$actor->name.($kind==='reply'?' replied to your comment':' commented on your post'),'description'=>mb_substr((string)($comment['text'] ?? $comment['body'] ?? ''),0,220),'sourceId'=>$this->commentId,'postId'=>$this->postId,'commentId'=>$this->commentId,'parentId'=>$parentId,'actorUid'=>(string)($actor->firebase_uid ?: $actor->id)]);
        }
    }
    public static function changed(string $collection,string $id,?object $before,int $actor): void {
        if (!$before && preg_match('~^posts/([^/]+)/comments$~',$collection,$m)) self::dispatch($m[1],$id,$actor)->afterCommit();
    }
}
