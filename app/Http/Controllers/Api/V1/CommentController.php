<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Models\Comment;
use App\Models\Post;
use App\Services\Outbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CommentController extends Controller
{
    use ApiResponses;

    public function index(Request $request, string $post)
    {
        $limit = min(100, max(1, $request->integer('limit', 60)));
        $record = $this->post($post);
        if ($record) {
            $rows = Comment::with('user:id,name,avatar_url,firebase_uid,legacy_firebase_uid')
                ->where('post_id', $record->id)
                ->orderBy('id')
                ->limit($limit + 1)
                ->get();
            $more = $rows->count() > $limit;
            $items = $rows->take($limit)->map(fn (Comment $comment) => $this->commentData($comment))->values();
            return $this->page($items, $more ? base64_encode((string) $rows[$limit - 1]->id) : null, $more, $limit);
        }

        abort_unless($this->importedPost($post), 404, 'Post not found.');
        $collection = 'posts/' . $post . '/comments';
        $rows = DB::table('firebase_documents')
            ->where('collection_path', $collection)
            ->orderBy('firebase_updated_at')
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();
        $more = $rows->count() > $limit;
        $items = $rows->take($limit)->map(function ($row): array {
            $payload = $this->payload($row);
            return ['id' => (string) $row->document_id, ...$payload];
        })->values();
        return $this->page($items, null, $more, $limit);
    }

    public function store(Request $request, string $post, Outbox $outbox)
    {
        $request->merge([
            'body' => $request->input('body', $request->input('text')),
            'parent_id' => $request->input('parent_id', $request->input('parentId')),
        ]);
        $data = $request->validate([
            'body' => 'required|string|max:10000',
            'parent_id' => 'nullable|string|max:191',
            'actorType' => 'nullable|string|max:30',
            'actorId' => 'nullable|string|max:191',
            'actorName' => 'nullable|string|max:255',
            'actorAvatar' => 'nullable|string|max:2000',
            'actorOwnerId' => 'nullable|string|max:191',
            'userName' => 'nullable|string|max:255',
            'userProfileImage' => 'nullable|string|max:2000',
            'mentions' => 'nullable|array',
            'mentionedUserIds' => 'nullable|array',
            'hashtags' => 'nullable|array',
            'tags' => 'nullable|array',
        ]);

        $record = $this->post($post);
        if ($record) {
            $parent = $this->resolveParent($record, $data['parent_id'] ?? null);
            $comment = DB::transaction(function () use ($request, $record, $parent, $data, $outbox): Comment {
                $firebaseId = (string) Str::ulid();
                $legacy = $this->legacyPayload($request, $data, $firebaseId, $parent?->firebase_id ?: ($parent?->id ? (string) $parent->id : null));
                $comment = Comment::create([
                    'firebase_id' => $firebaseId,
                    'post_id' => $record->id,
                    'user_id' => $request->user()->id,
                    'parent_id' => $parent?->id,
                    'body' => $data['body'],
                    'legacy_data' => $legacy,
                ]);
                $count = Comment::where('post_id', $record->id)->count();
                DB::table('post_metrics')->updateOrInsert(
                    ['post_id' => $record->id],
                    ['comments_count' => $count, 'updated_at' => now(), 'created_at' => now()],
                );
                $this->setImportedPostComments((string) ($record->firebase_id ?: $record->id), $count);
                $outbox->record('comment.created', 'post', (string) ($record->firebase_id ?: $record->id), [
                    'post_id' => $record->firebase_id ?: $record->id,
                    'comment_id' => $firebaseId,
                    'comment' => $legacy,
                ]);
                return $comment;
            });
            return $this->success($this->commentData($comment->load('user:id,name,avatar_url,firebase_uid,legacy_firebase_uid')), 201);
        }

        $postRow = $this->importedPost($post);
        abort_unless($postRow, 404, 'Post not found.');
        $id = (string) Str::ulid();
        $payload = $this->legacyPayload($request, $data, $id, $data['parent_id'] ?? null);
        $now = now();
        DB::transaction(function () use ($postRow, $post, $id, $payload, $now, $outbox): void {
            DB::table('firebase_documents')->insert([
                'collection_path' => 'posts/' . $post . '/comments',
                'document_id' => $id,
                'parent_document_path' => 'posts/' . $post,
                'payload' => json_encode($payload),
                'firebase_updated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->incrementImportedPostComments($postRow, 1);
            $outbox->record('comment.created', 'post', $post, ['post_id' => $post, 'comment_id' => $id, 'comment' => $payload]);
        });
        return $this->success(['id' => $id, ...$payload], 201);
    }

    public function update(Request $request, string $comment, Outbox $outbox)
    {
        $request->merge(['body' => $request->input('body', $request->input('text'))]);
        $data = $request->validate(['body' => 'required|string|max:10000']);
        $record = Comment::where('id', ctype_digit($comment) ? (int) $comment : -1)->orWhere('firebase_id', $comment)->first();
        if ($record) {
            $this->authorizeComment($request, $record);
            $legacy = (array) ($record->legacy_data ?? []);
            $legacy['text'] = $data['body'];
            $legacy['updatedAt'] = now()->toISOString();
            $legacy['updatedAtMs'] = now()->getTimestampMs();
            $legacy['editedAt'] = now()->toISOString();
            $legacy['editedAtMs'] = now()->getTimestampMs();
            $legacy['isEdited'] = true;
            $record->update(['body' => $data['body'], 'legacy_data' => $legacy]);
            $postId = $record->post?->firebase_id ?: $record->post_id;
            $outbox->record('comment.updated', 'post', (string) $postId, ['comment_id' => $record->firebase_id ?: $record->id]);
            return $this->success($this->commentData($record->fresh('user:id,name,avatar_url,firebase_uid,legacy_firebase_uid')));
        }

        $row = DB::table('firebase_documents')->where('document_id', $comment)->where('collection_path', 'like', 'posts/%/comments')->first();
        abort_unless($row, 404, 'Comment not found.');
        $payload = $this->payload($row);
        $this->authorizeImportedPayload($request, $payload);
        $payload['text'] = $data['body'];
        $payload['updatedAt'] = now()->toISOString();
        $payload['updatedAtMs'] = now()->getTimestampMs();
        $payload['editedAt'] = now()->toISOString();
        $payload['editedAtMs'] = now()->getTimestampMs();
        $payload['isEdited'] = true;
        DB::table('firebase_documents')->where('id', $row->id)->update(['payload' => json_encode($payload), 'firebase_updated_at' => now(), 'updated_at' => now()]);
        $parts = explode('/', (string) $row->collection_path);
        $postId = (string) ($parts[1] ?? '');
        if ($postId !== '') {
            $outbox->record('comment.updated', 'post', $postId, ['comment_id' => $comment]);
        }
        return $this->success(['id' => $comment, ...$payload]);
    }

    public function destroy(Request $request, string $comment, Outbox $outbox)
    {
        $record = Comment::with('post')->where('id', ctype_digit($comment) ? (int) $comment : -1)->orWhere('firebase_id', $comment)->first();
        if ($record) {
            $this->authorizeComment($request, $record);
            $post = $record->post;
            $record->delete();
            if ($post) {
                $count = Comment::where('post_id', $post->id)->count();
                DB::table('post_metrics')->updateOrInsert(
                    ['post_id' => $post->id],
                    ['comments_count' => $count, 'updated_at' => now(), 'created_at' => now()],
                );
                $this->setImportedPostComments((string) ($post->firebase_id ?: $post->id), $count);
                $outbox->record('comment.deleted', 'post', (string) ($post->firebase_id ?: $post->id), ['comment_id' => $comment]);
            }
            return $this->success(['deleted' => true]);
        }

        $row = DB::table('firebase_documents')->where('document_id', $comment)->where('collection_path', 'like', 'posts/%/comments')->first();
        abort_unless($row, 404, 'Comment not found.');
        $payload = $this->payload($row);
        $this->authorizeImportedPayload($request, $payload);
        $parts = explode('/', (string) $row->collection_path);
        $postId = $parts[1] ?? '';
        DB::transaction(function () use ($row, $postId, $comment, $outbox): void {
            DB::table('firebase_documents')->where('id', $row->id)->delete();
            DB::table('firebase_documents')->where('collection_path', $row->collection_path . '/' . $comment . '/reactions')->delete();
            if ($postId !== '') {
                $postRow = $this->importedPost($postId);
                if ($postRow) $this->incrementImportedPostComments($postRow, -1);
                $outbox->record('comment.deleted', 'post', $postId, ['comment_id' => $comment]);
            }
        });
        return $this->success(['deleted' => true]);
    }

    public function react(Request $request, string $comment)
    {
        $data = $request->validate(['reaction' => 'nullable|in:like,love,haha,wow,sad,care,pray']);
        $reaction = $data['reaction'] ?? 'like';
        [$postId, $publicCommentId, $payload] = $this->commentContext($comment);
        $actorId = $this->actorKey($request);
        $collection = 'posts/' . $postId . '/comments/' . $publicCommentId . '/reactions';
        $now = now();
        DB::table('firebase_documents')->updateOrInsert(
            ['collection_path' => $collection, 'document_id' => $actorId],
            ['parent_document_path' => 'posts/' . $postId . '/comments/' . $publicCommentId, 'payload' => json_encode(['userId' => $request->user()->firebase_uid ?: (string) $request->user()->id, 'reaction' => $reaction, 'updatedAtMs' => $now->getTimestampMs()]), 'firebase_updated_at' => $now, 'created_at' => $now, 'updated_at' => $now],
        );
        $count = DB::table('firebase_documents')->where('collection_path', $collection)->count();
        $this->updateCommentLikes($comment, $payload, $count);
        return $this->success(['reaction' => $reaction, 'likes' => $count]);
    }

    public function unreact(Request $request, string $comment)
    {
        [$postId, $publicCommentId, $payload] = $this->commentContext($comment);
        $collection = 'posts/' . $postId . '/comments/' . $publicCommentId . '/reactions';
        DB::table('firebase_documents')->where(['collection_path' => $collection, 'document_id' => $this->actorKey($request)])->delete();
        $count = DB::table('firebase_documents')->where('collection_path', $collection)->count();
        $this->updateCommentLikes($comment, $payload, $count);
        return $this->success(['reaction' => null, 'likes' => $count]);
    }

    private function post(string $id): ?Post
    {
        return Post::where('id', ctype_digit($id) ? (int) $id : -1)->orWhere('firebase_id', $id)->first();
    }

    private function importedPost(string $id): ?object
    {
        return DB::table('firebase_documents')->where(['collection_path' => 'posts', 'document_id' => $id])->first();
    }

    private function resolveParent(Post $post, ?string $id): ?Comment
    {
        if (! $id) return null;
        return Comment::where('post_id', $post->id)->where(fn ($q) => $q->where('id', ctype_digit($id) ? (int) $id : -1)->orWhere('firebase_id', $id))->first();
    }

    private function legacyPayload(Request $request, array $data, string $id, ?string $parentId): array
    {
        $user = $request->user();
        $actorType = (string) ($data['actorType'] ?? 'user');
        $actorId = (string) ($data['actorId'] ?? ($user->firebase_uid ?: $user->id));
        return [
            'id' => $id,
            'parentId' => $parentId,
            'text' => $data['body'],
            'userId' => (string) ($user->firebase_uid ?: $user->legacy_firebase_uid ?: $user->id),
            'userEmail' => (string) ($user->email ?? ''),
            'userName' => (string) ($data['userName'] ?? $data['actorName'] ?? $user->name ?? 'Member'),
            'userProfileImage' => (string) ($data['userProfileImage'] ?? $data['actorAvatar'] ?? $user->avatar_url ?? ''),
            'actorType' => $actorType,
            'actorId' => $actorId,
            'actorName' => (string) ($data['actorName'] ?? $data['userName'] ?? $user->name ?? 'Member'),
            'actorAvatar' => (string) ($data['actorAvatar'] ?? $data['userProfileImage'] ?? $user->avatar_url ?? ''),
            'actorOwnerId' => $data['actorOwnerId'] ?? ($actorType === 'page' ? (string) ($user->firebase_uid ?: $user->id) : null),
            'mentions' => $data['mentions'] ?? [],
            'mentionedUserIds' => $data['mentionedUserIds'] ?? [],
            'hashtags' => $data['hashtags'] ?? [],
            'tags' => $data['tags'] ?? [],
            'metrics' => ['likes' => 0],
            'createdAt' => now()->toISOString(),
            'createdAtMs' => now()->getTimestampMs(),
            'updatedAt' => now()->toISOString(),
            'updatedAtMs' => now()->getTimestampMs(),
        ];
    }

    private function commentData(Comment $comment): array
    {
        $legacy = (array) ($comment->legacy_data ?? []);
        $parent = $comment->parent_id ? Comment::find($comment->parent_id) : null;
        return array_merge($legacy, [
            'id' => (string) ($comment->firebase_id ?: $comment->id),
            'parentId' => $parent ? (string) ($parent->firebase_id ?: $parent->id) : ($legacy['parentId'] ?? null),
            'text' => $comment->body,
            'userId' => (string) ($legacy['userId'] ?? $comment->user?->firebase_uid ?? $comment->user?->legacy_firebase_uid ?? $comment->user_id ?? ''),
            'userName' => (string) ($legacy['userName'] ?? $comment->user?->name ?? 'Member'),
            'userProfileImage' => (string) ($legacy['userProfileImage'] ?? $comment->user?->avatar_url ?? ''),
            'createdAtMs' => (int) ($legacy['createdAtMs'] ?? $comment->created_at?->getTimestampMs() ?? 0),
            'updatedAtMs' => (int) ($legacy['updatedAtMs'] ?? $comment->updated_at?->getTimestampMs() ?? 0),
            'editedAt' => $legacy['editedAt'] ?? null,
            'editedAtMs' => (int) ($legacy['editedAtMs'] ?? 0),
            'isEdited' => (bool) ($legacy['isEdited'] ?? (($legacy['updatedAtMs'] ?? 0) > ($legacy['createdAtMs'] ?? PHP_INT_MAX))),
        ]);
    }

    private function incrementImportedPostComments(object $row, int $delta): void
    {
        $payload = $this->payload($row);
        $current = max(0, (int) ($payload['metrics']['comments'] ?? $payload['commentCount'] ?? $payload['comments'] ?? 0));
        $next = max(0, $current + $delta);
        $payload['commentCount'] = $next;
        $payload['comments'] = $next;
        $payload['metrics'] = array_merge((array) ($payload['metrics'] ?? []), ['comments' => $next]);
        $payload['comments_count'] = $next;
        $payload['backendMetricsUpdatedAtMs'] = now()->getTimestampMs();
        $payload['updatedAtMs'] = now()->getTimestampMs();
        DB::table('firebase_documents')->where('id', $row->id)->update(['payload' => json_encode($payload), 'firebase_updated_at' => now(), 'updated_at' => now()]);
    }

    private function setImportedPostComments(string $postId, int $count): void
    {
        $row = $this->importedPost($postId);
        if (! $row) return;
        $payload = $this->payload($row);
        $payload['commentCount'] = max(0, $count);
        $payload['comments'] = max(0, $count);
        $payload['comments_count'] = max(0, $count);
        $payload['metrics'] = array_merge((array) ($payload['metrics'] ?? []), ['comments' => max(0, $count)]);
        $payload['backendMetricsUpdatedAtMs'] = now()->getTimestampMs();
        $payload['updatedAtMs'] = max((int) ($payload['updatedAtMs'] ?? 0), now()->getTimestampMs());
        DB::table('firebase_documents')->where('id', $row->id)->update([
            'payload' => json_encode($payload),
            'firebase_updated_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function authorizeComment(Request $request, Comment $comment): void
    {
        abort_unless($request->user()->isAdmin() || $request->user()->id === $comment->user_id, 403, 'You cannot modify this comment.');
    }

    private function authorizeImportedPayload(Request $request, array $payload): void
    {
        if ($request->user()->isAdmin()) return;
        $keys = array_filter([(string) $request->user()->id, (string) $request->user()->firebase_uid, (string) $request->user()->legacy_firebase_uid, (string) $request->user()->email]);
        $owners = array_filter([(string) ($payload['userId'] ?? ''), (string) ($payload['actorOwnerId'] ?? ''), (string) (($payload['actorType'] ?? '') === 'user' ? ($payload['actorId'] ?? '') : '')]);
        abort_unless((bool) array_intersect($keys, $owners), 403, 'You cannot modify this comment.');
    }

    private function actorKey(Request $request): string
    {
        return (string) ($request->user()->firebase_uid ?: $request->user()->legacy_firebase_uid ?: $request->user()->id);
    }

    private function commentContext(string $comment): array
    {
        $record = Comment::with('post')->where('id', ctype_digit($comment) ? (int) $comment : -1)->orWhere('firebase_id', $comment)->first();
        if ($record && $record->post) return [(string) ($record->post->firebase_id ?: $record->post->id), (string) ($record->firebase_id ?: $record->id), (array) ($record->legacy_data ?? [])];
        $row = DB::table('firebase_documents')->where('document_id', $comment)->where('collection_path', 'like', 'posts/%/comments')->first();
        abort_unless($row, 404, 'Comment not found.');
        $parts = explode('/', (string) $row->collection_path);
        return [(string) ($parts[1] ?? ''), $comment, $this->payload($row)];
    }

    private function updateCommentLikes(string $comment, array $legacy, int $count): void
    {
        $record = Comment::where('id', ctype_digit($comment) ? (int) $comment : -1)->orWhere('firebase_id', $comment)->first();
        if ($record) {
            $legacy = (array) ($record->legacy_data ?? $legacy);
            $legacy['metrics'] = array_merge((array) ($legacy['metrics'] ?? []), ['likes' => $count]);
            $record->update(['legacy_data' => $legacy]);
            return;
        }
        $row = DB::table('firebase_documents')->where('document_id', $comment)->where('collection_path', 'like', 'posts/%/comments')->first();
        if (! $row) return;
        $payload = $this->payload($row);
        $payload['metrics'] = array_merge((array) ($payload['metrics'] ?? []), ['likes' => $count]);
        DB::table('firebase_documents')->where('id', $row->id)->update(['payload' => json_encode($payload), 'firebase_updated_at' => now(), 'updated_at' => now()]);
    }

    private function payload(object $row): array
    {
        return is_array($row->payload) ? $row->payload : (json_decode((string) $row->payload, true) ?: []);
    }
}
