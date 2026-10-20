<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Feed\FeedAudience;
use App\Services\SocialContent;
use App\Services\SocialMetrics;
use App\Services\SocialNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** @tags Social content */
class SocialContentController
{
    /** Fetch visible posts or reels directly from PostgreSQL, with authoritative metrics. */
    public function index(Request $request, SocialMetrics $metrics): JsonResponse
    {
        $input = $request->validate(['type' => 'sometimes|in:posts,reels', 'authorId' => 'sometimes|string|max:191', 'q' => 'sometimes|string|max:100', 'limit' => 'sometimes|integer|min:1|max:100', 'before' => 'sometimes|integer|min:1']);
        $type = $input['type'] ?? 'posts';
        $limit = $input['limit'] ?? 30;
        $query = (new FeedAudience($request->user()))->query($type);
        if (isset($input['authorId'])) {
            $query->where('feed_owner_id', $input['authorId']);
        }
        if (isset($input['q'])) {
            $query->where(fn ($q) => $q->where('payload->text', 'ilike', '%'.$input['q'].'%')->orWhere('payload->caption', 'ilike', '%'.$input['q'].'%')->orWhereJsonContains('payload->searchTokens', mb_strtolower($input['q'])));
        }
        if (isset($input['before'])) {
            $query->where('id', '<', $input['before']);
        }
        $rows = $query->orderByDesc('id')->limit($limit + 1)->get();
        $items = $rows->take($limit)->map(fn ($r) => array_merge(json_decode($r->payload, true) ?: [], ['id' => $r->document_id]))->all();

        return response()->json(['items' => $metrics->decorate($items, $type), 'hasMore' => $rows->count() > $limit, 'nextCursor' => $rows->count() > $limit ? (int) $rows[$limit - 1]->id : null]);
    }

    /** Get one visible post/reel. Numeric SQL and public post IDs resolve to the same content. */
    public function show(Request $request, string $type, string $id, SocialContent $content, SocialMetrics $metrics): JsonResponse
    {
        $row = $content->find($type, $id);
        abort_unless($row, 404);
        abort_unless($content->visible($request->user(), $row), 403);
        $items = $metrics->decorate([array_merge($row['data'], ['id' => $row['id'], 'type' => $row['kind']])], $row['collection'] === 'social_live_sessions' ? 'lives' : $row['collection']);

        return response()->json($items[0]);
    }

    /** Record a qualified view. Retry the same eventId safely; a new eventId represents a new viewing session. */
    public function view(Request $request, string $type, string $id, SocialMetrics $metrics): JsonResponse
    {
        $data = $request->validate(['eventId' => 'required|string|max:80|regex:/^[A-Za-z0-9_-]+$/', 'actorUid' => 'sometimes|string|max:191']);
        abort_if(isset($data['actorUid']) && ! in_array($data['actorUid'], SocialNotification::keys($request->user()), true), 403, 'Session changed.');

        return response()->json($metrics->record($request->user(), $type, $id, 'view:'.$data['eventId']));
    }

    /** Delete your content and its reel mirror on the backend. */
    public function destroy(Request $request, string $type, string $id, SocialContent $content): JsonResponse
    {
        $item = $content->find($type, $id);
        abort_unless($item, 404);
        $keys = SocialNotification::keys($request->user());
        abort_unless(array_intersect($keys, array_filter([$item['data']['ownerId'] ?? null, $item['data']['authorId'] ?? null])) || ($item['pageId'] && $content->canManage($request->user(), $item['pageId'])), 403);
        DB::transaction(function () use ($item) {
            $rows = DB::table('firebase_documents')->where(fn ($q) => $q->where(fn ($q) => $q->where('collection_path', $item['collection'])->where('document_id', $item['id']))->orWhere(fn ($q) => $q->where('collection_path', 'reels')->where(fn ($q) => $q->where('document_id', $item['id'])->orWhere('payload->postId', $item['id'])->orWhere('payload->sourcePostId', $item['id']))))->lockForUpdate()->get();
            foreach ($rows as $row) {
                $data = json_decode($row->payload, true) ?: [];
                $data['status'] = 'deleted';
                $data['deleted'] = true;
                $data['deletedAt'] = now()->toISOString();
                DB::table('firebase_documents')->where('id', $row->id)->update(['payload' => json_encode($data), 'updated_at' => now(), 'firebase_updated_at' => now()]);
            }
            if ($item['collection'] === 'posts') {
                DB::table('posts')->where('firebase_id', $item['id'])->update(['status' => 'deleted', 'updated_at' => now()]);
            }
        });

        return response()->json(['deleted' => true]);
    }
}
