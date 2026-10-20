<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\ScheduledSocialPosts;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScheduledPostController
{
    public function index(Request $request, ScheduledSocialPosts $posts): JsonResponse
    {
        $data = $request->validate(['pageId' => 'nullable|string|max:191', 'page' => 'sometimes|integer|min:1']);
        $pageId = $data['pageId'] ?? null;
        $posts->page($request->user(), $pageId);
        $query = DB::table('scheduled_social_posts')->whereIn('status', ['scheduled', 'blocked']);
        if ($pageId) {
            $query->where('page_id', $pageId);
        } else {
            $query->where('user_id', $request->user()->id)->whereNull('page_id');
        }
        $rows = $query->orderBy('scheduled_at')->orderBy('id')->offset((($data['page'] ?? 1) - 1) * 30)->limit(31)->get();

        return response()->json(['data' => ['items' => $rows->take(30)->map(fn ($row) => $posts->present($row))->values(), 'hasMore' => $rows->count() > 30]]);
    }

    private function validateDraft(Request $request): array
    {
        $data = $request->validate([
            'scheduledAt' => ['required', 'date', 'after:now', 'regex:/T.*(?:Z|[+-]\d{2}:\d{2})$/'],
            'timezone' => 'required|timezone', 'pageId' => 'nullable|string|max:191',
            'payload' => 'required|array', 'payload.text' => 'nullable|string|max:30000',
            'payload.type' => 'required|in:text,image,video,reel',
            'payload.audience' => 'required|in:public,friends,private',
            'payload.media' => 'present|array|max:10', 'payload.media.*.url' => 'required|url:https|max:4000',
            'payload.backgroundCanvas' => 'nullable|array', 'payload.textOverlays' => 'sometimes|array|max:100',
        ]);
        abort_unless(trim($data['payload']['text'] ?? '') !== '' || count($data['payload']['media']), 422, 'The post is empty.');
        abort_if(strlen(json_encode($data['payload'])) > 250000, 422, 'The post is too large.');
        if ($data['payload']['type'] === 'reel') {
            abort_unless(count($data['payload']['media']) > 0, 422, 'A video is required.');
        }
        // Only content can be supplied by the client; identity/status/timestamps are server-owned.
        $payload = array_intersect_key($data['payload'], array_flip(['text', 'type', 'audience', 'media', 'backgroundCanvas', 'textOverlays']));
        $payload['caption'] = $payload['text'] ?? '';
        $payload['visibility'] = $payload['audience'];

        return [
            'payload' => json_encode($payload), 'scheduled_at' => Carbon::parse($data['scheduledAt'])->utc()->toISOString(),
            'timezone' => $data['timezone'], 'page_id' => $data['pageId'] ?? null,
        ];
    }

    public function store(Request $request, ScheduledSocialPosts $posts): JsonResponse
    {
        $data = $this->validateDraft($request);
        $posts->page($request->user(), $data['page_id']);
        $requestId = $request->validate(['requestId' => 'required|string|max:100'])['requestId'];
        $id = 'scheduled_'.hash('sha256', $request->user()->id.':'.$requestId);
        DB::table('scheduled_social_posts')->insertOrIgnore(array_merge($data, [
            'id' => $id, 'user_id' => $request->user()->id, 'status' => 'scheduled', 'revision' => 1,
            'created_at' => now()->toISOString(), 'updated_at' => now()->toISOString(),
        ]));

        return response()->json(['data' => $posts->present(DB::table('scheduled_social_posts')->where('id', $id)->first())], 201);
    }

    public function update(Request $request, string $id, ScheduledSocialPosts $posts): JsonResponse
    {
        $data = $this->validateDraft($request);
        $revision = $request->validate(['revision' => 'required|integer|min:1'])['revision'];

        return DB::transaction(function () use ($request, $id, $posts, $data, $revision): JsonResponse {
            $row = DB::table('scheduled_social_posts')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            $posts->authorize($request->user(), $row);
            abort_unless(in_array($row->status, ['scheduled', 'blocked'], true) && $revision === (int) $row->revision, 409, 'This post changed or was already published. Refresh and try again.');
            abort_unless($data['page_id'] === $row->page_id, 422, 'The publishing identity cannot be changed.');
            DB::table('scheduled_social_posts')->where('id', $id)->update(array_merge($data, [
                'revision' => $revision + 1, 'status' => 'scheduled', 'last_error' => null, 'updated_at' => now()->toISOString(),
                'user_id' => $request->user()->id,
            ]));

            return response()->json(['data' => $posts->present(DB::table('scheduled_social_posts')->where('id', $id)->first())]);
        });
    }

    public function publish(Request $request, string $id, ScheduledSocialPosts $posts): JsonResponse
    {
        $revision = $request->validate(['revision' => 'required|integer|min:1'])['revision'];

        return response()->json(['data' => $posts->present($posts->publish($id, $request->user(), $revision))]);
    }
}
