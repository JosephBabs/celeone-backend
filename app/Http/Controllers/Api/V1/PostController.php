<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Models\Post;
use App\Services\Outbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PostController extends Controller
{
    use ApiResponses;

    public function index(Request $request)
    {
        $limit = min(50, max(1, $request->integer('limit', 20)));
        $query = Post::with(['user:id,name,avatar_url', 'page:id,name,avatar_url'])->latest('id');
        if ($request->filled('cursor')) $query->where('id', '<', (int) base64_decode((string) $request->input('cursor')));
        if ($request->filled('author_id')) $query->where('user_id', $request->integer('author_id'));
        if ($request->filled('page_id')) $query->where('page_id', $request->integer('page_id'));
        $rows = $query->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rawItems = $rows->take($limit)->values();
        $items = $rawItems->map(fn (Post $post) => $this->postData($post))->values();
        return $this->page($items, $more ? base64_encode((string) $rawItems->last()->id) : null, $more, $limit);
    }

    public function show(string $post)
    {
        $record = $this->post($post);
        if ($record) {
            $record->load(['user:id,name,avatar_url', 'page:id,name,avatar_url', 'media', 'comments.user:id,name,avatar_url']);
            return $this->success($this->postData($record));
        }
        $row = $this->imported($post);
        abort_unless($row, 404, 'Post not found.');
        return $this->success(['id' => $post, ...$this->payload($row)]);
    }

    public function store(Request $request, Outbox $outbox)
    {
        $data = $request->validate([
            'text' => 'nullable|string|max:30000', 'type' => 'nullable|string|max:50',
            'audience' => 'nullable|in:public,friends,private', 'page_id' => 'nullable|exists:pages,id',
            'media' => 'nullable|array|max:10', 'media.*.url' => 'required_with:media|url', 'media.*.type' => 'required_with:media|string|max:30',
        ]);
        $post = DB::transaction(function () use ($data, $request, $outbox) {
            $firebaseId = (string) Str::ulid();
            $post = Post::create([
                'firebase_id' => $firebaseId, 'user_id' => $request->user()->id, 'page_id' => $data['page_id'] ?? null,
                'text_content' => $data['text'] ?? null, 'post_type' => $data['type'] ?? 'status',
                'audience' => $data['audience'] ?? 'public', 'status' => 'published',
            ]);
            foreach ($data['media'] ?? [] as $media) $post->media()->create(['user_id' => $request->user()->id, 'type' => $media['type'], 'url' => $media['url'], 'metadata' => $media]);
            DB::table('post_metrics')->insertOrIgnore(['post_id' => $post->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->syncMirror($post, $request);
            $outbox->record('feed.item.created', 'post', $firebaseId, ['post_id' => $firebaseId]);
            return $post;
        });
        return $this->success($post->load('media'), 201);
    }

    public function update(Request $request, string $post, Outbox $outbox)
    {
        $record = $this->post($post);
        if ($record) {
            abort_unless($request->user()->id === $record->user_id || $request->user()->isAdmin(), 403);
            $record->update($request->validate(['text_content' => 'sometimes|nullable|string|max:30000', 'audience' => 'sometimes|in:public,friends,private', 'status' => 'sometimes|string|max:30']));
            $this->syncMirror($record->fresh(), $request);
            $publicId = (string) ($record->firebase_id ?: $record->id);
            $outbox->record('feed.item.updated', 'post', $publicId, ['post_id' => $publicId]);
            return $this->success($record->fresh());
        }

        $row = $this->imported($post);
        abort_unless($row, 404, 'Post not found.');
        $payload = $this->payload($row);
        $this->authorizeImported($request, $payload);
        foreach ($request->only(['text_content', 'text', 'content', 'audience', 'status']) as $key => $value) $payload[$key] = $value;
        $payload['updatedAtMs'] = now()->getTimestampMs();
        DB::table('firebase_documents')->where('id', $row->id)->update(['payload' => json_encode($payload), 'firebase_updated_at' => now(), 'updated_at' => now()]);
        $outbox->record('feed.item.updated', 'post', $post, ['post_id' => $post]);
        return $this->success(['id' => $post, ...$payload]);
    }

    public function destroy(Request $request, string $post, Outbox $outbox)
    {
        $record = $this->post($post);
        if ($record) {
            abort_unless($request->user()->id === $record->user_id || $request->user()->isAdmin(), 403);
            $publicId = (string) ($record->firebase_id ?: $record->id);
            $record->delete();
            DB::table('firebase_documents')->where(['collection_path' => 'posts', 'document_id' => $publicId])->delete();
            $outbox->record('feed.item.removed', 'post', $publicId, ['post_id' => $publicId]);
            return $this->success(['deleted' => true]);
        }
        $row = $this->imported($post);
        abort_unless($row, 404, 'Post not found.');
        $this->authorizeImported($request, $this->payload($row));
        DB::table('firebase_documents')->where('id', $row->id)->delete();
        DB::table('firebase_documents')->where('collection_path', 'like', 'posts/' . $post . '/%')->delete();
        $outbox->record('feed.item.removed', 'post', $post, ['post_id' => $post]);
        return $this->success(['deleted' => true]);
    }

    /**
     * Always expose SQL engagement metrics as the authoritative counters.
     * Legacy Firebase fields remain available for compatibility, but can no
     * longer overwrite newer Laravel counts in the mobile feed.
     */
    private function postData(Post $post): array
    {
        $legacy = (array) ($post->legacy_data ?? []);
        $base = $post->toArray();
        $metric = DB::table('post_metrics')->where('post_id', $post->id)->first();
        $legacyMetrics = is_array($legacy['metrics'] ?? null) ? $legacy['metrics'] : [];
        $reactions = (int) ($metric?->reactions_count ?? $legacyMetrics['reactions'] ?? $legacy['reactions_count'] ?? $legacy['likes'] ?? 0);
        $comments = (int) ($metric?->comments_count ?? $legacyMetrics['comments'] ?? $legacy['comments_count'] ?? $legacy['commentCount'] ?? $legacy['comments'] ?? 0);
        $shares = (int) ($metric?->shares_count ?? $legacyMetrics['shares'] ?? $legacy['shares_count'] ?? $legacy['shares'] ?? 0);
        $views = (int) ($metric?->views_count ?? $legacyMetrics['views'] ?? $legacy['views_count'] ?? $legacy['viewCount'] ?? $legacy['views'] ?? 0);
        $publicId = (string) ($post->firebase_id ?: $post->id);

        return array_merge($legacy, $base, [
            'id' => $publicId,
            'firebase_id' => $post->firebase_id,
            'server_numeric_id' => $post->id,
            'text' => $post->text_content ?? ($legacy['text'] ?? $legacy['content'] ?? ''),
            'content' => $post->text_content ?? ($legacy['content'] ?? $legacy['text'] ?? ''),
            'type' => $post->post_type ?? ($legacy['type'] ?? 'text'),
            'audience' => $post->audience ?? ($legacy['audience'] ?? 'public'),
            'likes' => $reactions,
            'reactions_count' => $reactions,
            'commentCount' => $comments,
            'comments_count' => $comments,
            'shares' => $shares,
            'shares_count' => $shares,
            'viewCount' => $views,
            'views_count' => $views,
            'metrics' => array_merge($legacyMetrics, [
                'reactions' => $reactions,
                'comments' => $comments,
                'shares' => $shares,
                'views' => $views,
            ]),
        ]);
    }

    private function post(string $id): ?Post { return Post::where('id', ctype_digit($id) ? (int) $id : -1)->orWhere('firebase_id', $id)->first(); }
    private function imported(string $id): ?object { return DB::table('firebase_documents')->where(['collection_path' => 'posts', 'document_id' => $id])->first(); }
    private function payload(object $row): array { return is_array($row->payload) ? $row->payload : (json_decode((string) $row->payload, true) ?: []); }

    private function authorizeImported(Request $request, array $payload): void
    {
        if ($request->user()->isAdmin()) return;
        $keys = array_filter([(string) $request->user()->id, (string) $request->user()->firebase_uid, (string) $request->user()->legacy_firebase_uid, (string) $request->user()->email]);
        $owners = array_filter([(string) ($payload['userId'] ?? ''), (string) ($payload['authorId'] ?? ''), (string) ($payload['createdBy'] ?? ''), (string) ($payload['actorOwnerId'] ?? '')]);
        abort_unless((bool) array_intersect($keys, $owners), 403, 'You cannot modify this post.');
    }

    private function syncMirror(Post $post, Request $request): void
    {
        $id = (string) ($post->firebase_id ?: $post->id);
        $existing = $this->imported($id);
        $old = $existing ? $this->payload($existing) : [];
        $metric = DB::table('post_metrics')->where('post_id', $post->id)->first();
        $reactions = (int) ($metric?->reactions_count ?? $old['likes'] ?? 0);
        $comments = (int) ($metric?->comments_count ?? $old['commentCount'] ?? $old['comments'] ?? 0);
        $shares = (int) ($metric?->shares_count ?? $old['shares'] ?? 0);
        $views = (int) ($metric?->views_count ?? $old['viewCount'] ?? $old['views'] ?? 0);
        $payload = array_merge($old, [
            'id' => $id, 'userId' => (string) ($request->user()->firebase_uid ?: $request->user()->id),
            'authorId' => (string) ($request->user()->firebase_uid ?: $request->user()->id), 'author' => $request->user()->name,
            'text' => $post->text_content, 'content' => $post->text_content, 'type' => $post->post_type,
            'audience' => $post->audience, 'status' => $post->status,
            'likes' => $reactions, 'reactions_count' => $reactions,
            'commentCount' => $comments, 'comments' => $comments, 'comments_count' => $comments,
            'shares' => $shares, 'shares_count' => $shares,
            'viewCount' => $views, 'views' => $views, 'views_count' => $views,
            'metrics' => array_merge((array) ($old['metrics'] ?? []), [
                'reactions' => $reactions, 'comments' => $comments, 'shares' => $shares, 'views' => $views,
            ]),
            'backendMetricsUpdatedAtMs' => now()->getTimestampMs(),
            'createdAtMs' => (int) ($old['createdAtMs'] ?? $post->created_at?->getTimestampMs() ?? now()->getTimestampMs()),
            'updatedAtMs' => now()->getTimestampMs(),
        ]);
        DB::table('firebase_documents')->updateOrInsert(['collection_path' => 'posts', 'document_id' => $id], [
            'parent_document_path' => null, 'payload' => json_encode($payload), 'firebase_updated_at' => now(),
            'created_at' => $existing?->created_at ?? now(), 'updated_at' => now(),
        ]);
    }
}
