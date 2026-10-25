<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Support\Facades\DB;

class PublicMediaController
{
    public function __invoke(string $type, string $id)
    {
        abort_if(strlen($id) > 191, 404);

        $collection = $type === 'songs' ? 'songs' : 'videos';
        $row = DB::table('firebase_documents')
            ->where('collection_path', $collection)
            ->where('document_id', $id)
            ->first();

        $payload = $row ? json_decode((string) $row->payload, true) : null;
        if (! is_array($payload) && $collection === 'videos') {
            $row = DB::table('firebase_documents')
                ->where('collection_path', 'movies')
                ->where('document_id', $id)
                ->first();
            $payload = $row ? json_decode((string) $row->payload, true) : null;
        }

        $relational = DB::table($collection)->where('public_id', $id)->whereNull('deleted_at')->first();
        if (! is_array($payload) && $relational) {
            $payload = json_decode((string) $relational->data, true);
        }

        abort_unless(is_array($payload), 404);
        abort_unless(in_array(strtolower((string) ($payload['status'] ?? $relational->status ?? 'published')), ['published', 'active', 'approved'], true), 404);
        abort_if(in_array(strtolower((string) ($payload['moderationStatus'] ?? '')), ['rejected', 'blocked', 'removed'], true), 404);
        abort_if(! empty($payload['isPrivate']) || ! empty($payload['deletedAt']) || ! empty($payload['deleted']) || ! empty($payload['isDeleted']), 404);

        $payload['id'] = $id;
        $payload['type'] = $collection === 'songs' ? 'music' : ($payload['type'] ?? 'movie');
        return response()->json(['data' => \App\Services\PublicVideoPreview::decorate($payload)])
            ->header('Cache-Control', 'public, max-age=30');
    }
}
