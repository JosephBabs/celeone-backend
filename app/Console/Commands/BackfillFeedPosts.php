<?php
namespace App\Console\Commands;
use App\Models\Post;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillFeedPosts extends Command
{
    protected $signature='feed:backfill';
    protected $description='Add missing SQL post mirrors without replacing imported post payloads';
    public function handle(): int
    {
        $count=0;
        Post::with(['user','page','media'])->chunkById(200,function($posts) use(&$count): void {
            foreach($posts as $post) {
                $id=(string)($post->firebase_id ?: $post->id);
                if(DB::table('firebase_documents')->where('collection_path','posts')->where('document_id',$id)->exists()) continue;
                $owner=(string)($post->user?->firebase_uid ?: $post->user?->legacy_firebase_uid ?: $post->user_id);
                $page=$post->page_id ? (string)($post->page?->firebase_id ?: $post->page_id) : null;
                $payload=array_merge((array)$post->legacy_data,[
                    'id'=>$id,'authorId'=>$page ?: $owner,'userId'=>$owner,'actorOwnerId'=>$owner,'pageId'=>$page,
                    'authorName'=>$post->page?->name ?: $post->user?->name,'authorAvatar'=>$post->page?->avatar_url ?: $post->user?->avatar_url,
                    'text'=>$post->text_content,'type'=>$post->post_type,'audience'=>$post->audience,'status'=>$post->status,
                    'media'=>$post->media->map(fn($media)=>array_merge((array)$media->metadata,['url'=>$media->url,'mime'=>$media->mime_type,'type'=>$media->type]))->all(),
                    'createdAtMs'=>$post->created_at->getTimestampMs(),'updatedAtMs'=>$post->updated_at->getTimestampMs(),
                ]);
                DB::table('firebase_documents')->insertOrIgnore(['collection_path'=>'posts','document_id'=>$id,'parent_document_path'=>null,'payload'=>json_encode($payload),'firebase_updated_at'=>$post->updated_at,'created_at'=>$post->created_at,'updated_at'=>$post->updated_at]);
                $count++;
            }
        });
        $this->info("Added {$count} missing post mirrors.");
        return self::SUCCESS;
    }
}
