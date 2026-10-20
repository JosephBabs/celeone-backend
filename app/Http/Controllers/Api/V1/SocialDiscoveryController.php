<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Feed\FeedAudience;
use App\Services\SocialMetrics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SocialDiscoveryController
{
    public function __invoke(Request $request)
    {
        $input = $request->validate(['q' => 'nullable|string|max:100', 'type' => 'required|in:pages,posts', 'page' => 'nullable|integer|min:1', 'limit' => 'nullable|integer|min:1|max:50']);
        $pages = $input['type'] === 'pages';
        $query = $pages ? DB::table('firebase_documents')->where('collection_path', 'pages')
            ->whereRaw("COALESCE(payload->>'visibility',payload->>'audience','public') = 'public'")
            ->whereRaw("COALESCE(payload->>'status','active') IN ('active','published','approved')")
            : (new FeedAudience($request->user()))->query();
        if (! empty($input['q'])) {
            $query->whereRaw("CONCAT_WS(' ',payload->>'name',payload->>'displayName',payload->>'username',payload->>'title',payload->>'text',payload->>'caption',payload->>'description',payload->>'country',payload->>'city',payload->>'tags') ILIKE ?", ['%'.$input['q'].'%']);
        }
        if ($pages && empty($input['q'])) {
            $query->orderByRaw("ROW_NUMBER() OVER (PARTITION BY COALESCE(payload->>'country','') ORDER BY md5(document_id || ?))", [now()->format('Y-m-d')]);
        }
        $limit = $input['limit'] ?? 40;
        $page = $input['page'] ?? 1;
        $rows = $query->orderByDesc('id')->offset(($page - 1) * $limit)->limit($limit + 1)->get(['document_id', 'payload']);
        $items = $rows->take($limit)->map(function ($row) use ($pages) {
            $data = json_decode($row->payload, true) ?: [];

            return array_merge($data, ['id' => $row->document_id, '__kind' => $pages ? 'pages' : match ($data['type'] ?? 'post') {
                'reel' => 'vibes','video' => 'videos','event' => 'events',default => 'posts'
            }]);
        })->values()->all();
        if (! $pages) {
            $items = app(SocialMetrics::class)->decorate($items);
        }

        return response()->json(['items' => $items, 'has_more' => $rows->count() > $limit, 'page' => $page]);
    }
}
