<?php
namespace App\Services;

use App\Models\Comment;
use App\Models\Post;
use Illuminate\Support\Facades\DB;

class SocialCounts
{
    public function comments(string $postId): int
    {
        return DB::transaction(function () use ($postId): int {
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['comments:'.$postId]);
            $post = Post::where('firebase_id', $postId)->orWhere('id', ctype_digit($postId) ? (int)$postId : -1)->first();
            $publicId = (string)($post?->firebase_id ?: $postId);
            $ids = DB::table('firebase_documents')->where('collection_path', 'posts/'.$publicId.'/comments')->pluck('document_id');
            if ($post) {
                $ids = $ids->concat(Comment::where('post_id', $post->id)->get(['id','firebase_id'])->map(fn($c) => (string)($c->firebase_id ?: $c->id)));
            }
            $count = $ids->unique()->count();
            if ($post) DB::table('post_metrics')->updateOrInsert(['post_id'=>$post->id], ['comments_count'=>$count,'updated_at'=>now()->toISOString(),'created_at'=>now()->toISOString()]);
            $row = DB::table('firebase_documents')->where(['collection_path'=>'posts','document_id'=>$publicId])->lockForUpdate()->first();
            if ($row) {
                $data = json_decode($row->payload, true) ?: [];
                $data['commentCount'] = $data['commentsCount'] = $data['comments'] = $count;
                $data['metrics'] = array_merge((array)($data['metrics'] ?? []), ['comments'=>$count]);
                DB::table('firebase_documents')->where('id',$row->id)->update(['payload'=>json_encode($data),'firebase_updated_at'=>now()->toISOString(),'updated_at'=>now()->toISOString()]);
            }
            return $count;
        });
    }

    public function changed(string $collection): void
    {
        if (preg_match('~^posts/([^/]+)/comments$~', $collection, $match)) $this->comments($match[1]);
    }
}
