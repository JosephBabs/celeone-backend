<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Support\Facades\DB;

class PublicMediaController
{
    public function index(\Illuminate\Http\Request $request, string $type)
    {
        $collection = $type === 'songs' ? 'songs' : 'videos';
        $limit = max(1, min(100, $request->integer('limit', 20)));
        $query = DB::table('firebase_documents')->where('collection_path', $collection);
        if ($request->filled('cursor')) {
            $query->where('id', '>', (int) $request->input('cursor'));
        }

        // Fetch one extra row so pagination can skip unpublished records without
        // losing the next cursor at the end of a page.
        $rows = $query->orderBy('id')->limit($limit + 1)->get(['document_id', 'payload', 'id']);
        $hasMore = $rows->count() > $limit;
        $pageRows = $rows->take($limit);
        $items = [];
        $lastRowId = null;
        foreach ($pageRows as $row) {
            $lastRowId = $row->id;
            $payload = json_decode((string) $row->payload, true);
            if (! is_array($payload) || ! in_array(strtolower((string) ($payload['status'] ?? 'published')), ['published', 'active', 'approved'], true)) {
                continue;
            }
            if (in_array(strtolower((string) ($payload['moderationStatus'] ?? '')), ['rejected', 'blocked', 'removed'], true)) {
                continue;
            }
            $payload['id'] = $row->document_id;
            $items[] = $payload;
        }

        return response()->json(['data' => $items, 'meta' => ['next_cursor' => $hasMore && $lastRowId !== null ? (string) $lastRowId : null]])
            ->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=300')
            ->header('Vary', 'Accept-Encoding');
    }

    public function __invoke(string $type, string $id)
    {
        abort_if(strlen($id) > 191, 404);

        abort_unless(in_array($type, ['videos', 'movies', 'songs'], true), 404);
        $collection = $type === 'songs' ? 'songs' : 'videos';
        $collections = $collection === 'songs' ? ['songs'] : array_values(array_unique([$type, 'videos', 'movies']));

        $payload = null;
        $fallback = null;
        foreach ($collections as $sourceCollection) {
            $row = DB::table('firebase_documents')
                ->where('collection_path', $sourceCollection)
                ->where('document_id', $id)
                ->first();
            $candidate = $row ? json_decode((string) $row->payload, true) : null;
            $candidateRelational = null;

            if (! is_array($candidate)) {
                $candidateRelational = DB::table($collection)
                    ->where('public_id', $id)
                    ->whereNull('deleted_at')
                    ->first();
                $candidate = $candidateRelational ? json_decode((string) $candidateRelational->data, true) : null;
            }

            if (! is_array($candidate)) {
                continue;
            }

            $status = strtolower((string) ($candidate['status'] ?? $candidateRelational?->status ?? 'published'));
            $moderation = strtolower((string) ($candidate['moderationStatus'] ?? ''));
            $isPublic = in_array($status, ['published', 'active', 'approved'], true)
                && ! in_array($moderation, ['rejected', 'blocked', 'removed'], true)
                && empty($candidate['isPrivate'])
                && empty($candidate['deletedAt'])
                && empty($candidate['deleted'])
                && empty($candidate['isDeleted']);
            $hasPlayableMedia = $collection === 'songs'
                ? ! empty($candidate['audioUrl']) || ! empty($candidate['link'])
                : ! empty($candidate['videoUrl']) || ! empty($candidate['link']) || ! empty($candidate['media']);

            if ($isPublic && $hasPlayableMedia) {
                $payload = $candidate;
                break;
            }

            // Keep a public record as a fallback when the preferred legacy
            // collection has metadata but no direct media URL.
            if ($isPublic && ! is_array($fallback)) {
                $fallback = $candidate;
            }
        }

        $payload ??= $fallback;

        abort_unless(is_array($payload), 404);

        $payload['id'] = $id;
        $payload['type'] = $collection === 'songs' ? 'music' : ($payload['type'] ?? 'movie');
        return response()->json(['data' => \App\Services\PublicVideoPreview::decorate($payload)])
            ->header('Cache-Control', 'public, max-age=30');
    }
}
