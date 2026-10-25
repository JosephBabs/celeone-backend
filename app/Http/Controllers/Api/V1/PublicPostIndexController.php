<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Support\Facades\DB;

class PublicPostIndexController
{
    public function __invoke()
    {
        $items = DB::table('firebase_documents')
            ->where('collection_path', 'posts')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['document_id', 'payload'])
            ->map(function ($row): ?array {
                $payload = json_decode((string) $row->payload, true);
                if (! is_array($payload) || ! in_array(strtolower((string) ($payload['status'] ?? 'published')), ['published', 'active', 'approved'], true)) {
                    return null;
                }
                if (! in_array(strtolower((string) ($payload['audience'] ?? 'public')), ['public', ''], true)) {
                    return null;
                }
                $payload['id'] = $row->document_id;

                return $payload;
            })
            ->filter()
            ->values();

        return response()->json(['data' => $items])->header('Cache-Control', 'public, max-age=30');
    }
}
