<?php

namespace App\Http\Controllers\Api\V1;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StatusController extends DomainResourceController
{
    protected string $table='statuses';

    public function publish(Request $request): JsonResponse
    {
        $user = $request->user();
        $uid = (string) ($user->firebase_uid ?: $user->legacy_firebase_uid ?: $user->uid ?: $user->id);
        abort_unless($uid !== '', 401, 'Authenticated user has no publishable id.');

        $data = $request->validate([
            'userId' => ['nullable', 'string', 'max:191'],
            'userName' => ['nullable', 'string', 'max:255'],
            'userAvatar' => ['nullable', 'string', 'max:2000'],
            'updatedAtMs' => ['nullable', 'numeric'],
            'statusItems' => ['required', 'array', 'min:1', 'max:30'],
            'statusItems.*.id' => ['nullable', 'string', 'max:191'],
            'statusItems.*.type' => ['required', 'string', 'in:text,canvas,image,video,shared'],
            'statusItems.*.text' => ['nullable', 'string', 'max:5000'],
            'statusItems.*.imageUrl' => ['nullable', 'string', 'max:4000'],
            'statusItems.*.videoUrl' => ['nullable', 'string', 'max:4000'],
            'statusItems.*.thumbnailUrl' => ['nullable', 'string', 'max:4000'],
            'statusItems.*.storagePath' => ['nullable', 'string', 'max:2000'],
            'statusItems.*.backgroundColor' => ['nullable', 'string', 'max:255'],
            'statusItems.*.textColor' => ['nullable', 'string', 'max:80'],
            'statusItems.*.createdAtMs' => ['nullable', 'numeric'],
            'statusItems.*.expiresAtMs' => ['nullable', 'numeric'],
            'statusItems.*.durationSec' => ['nullable', 'numeric'],
            'statusItems.*.width' => ['nullable', 'numeric'],
            'statusItems.*.height' => ['nullable', 'numeric'],
            'statusItems.*.mediaWidth' => ['nullable', 'numeric'],
            'statusItems.*.mediaHeight' => ['nullable', 'numeric'],
            'statusItems.*.mutedVideo' => ['nullable', 'boolean'],
            'statusItems.*.backgroundAudio' => ['nullable', 'array'],
        ]);

        abort_if(!empty($data['userId']) && (string) $data['userId'] !== $uid, 403, 'Cannot publish status for another user.');

        $now = now();
        $nowIso = $now->toISOString();
        $nowMs = (int) floor(microtime(true) * 1000);
        $ttlMs = 24 * 60 * 60 * 1000;
        $items = [];
        foreach (array_values($data['statusItems']) as $index => $item) {
            $type = (string) $item['type'];
            abort_if($type === 'image' && empty($item['imageUrl']), 422, 'Image status requires imageUrl.');
            abort_if($type === 'video' && empty($item['videoUrl']), 422, 'Video status requires videoUrl.');
            $createdAtMs = (int) ($item['createdAtMs'] ?? ($nowMs + $index));
            $expiresAtMs = (int) ($item['expiresAtMs'] ?? ($createdAtMs + $ttlMs));
            $items[] = array_filter([
                'id' => (string) ($item['id'] ?? ($uid . '_' . $createdAtMs . '_' . $index)),
                'type' => $type,
                'text' => (string) ($item['text'] ?? ''),
                'backgroundColor' => $item['backgroundColor'] ?? null,
                'textColor' => $item['textColor'] ?? null,
                'imageUrl' => (string) ($item['imageUrl'] ?? ''),
                'videoUrl' => (string) ($item['videoUrl'] ?? ''),
                'thumbnailUrl' => (string) ($item['thumbnailUrl'] ?? ''),
                'storagePath' => $item['storagePath'] ?? null,
                'width' => isset($item['width']) ? (float) $item['width'] : null,
                'height' => isset($item['height']) ? (float) $item['height'] : null,
                'mediaWidth' => isset($item['mediaWidth']) ? (float) $item['mediaWidth'] : null,
                'mediaHeight' => isset($item['mediaHeight']) ? (float) $item['mediaHeight'] : null,
                'createdAt' => Carbon::createFromTimestampMs($createdAtMs)->toISOString(),
                'createdAtMs' => $createdAtMs,
                'expiresAtMs' => $expiresAtMs,
                'durationSec' => isset($item['durationSec']) ? (float) $item['durationSec'] : ($type === 'video' ? 15 : 8),
                'backgroundAudio' => $item['backgroundAudio'] ?? null,
                'mutedVideo' => (bool) ($item['mutedVideo'] ?? false),
                'metrics' => ['views' => 0, 'reactions' => 0],
            ], fn ($value) => $value !== null);
        }

        $existing = DB::table('firebase_documents')->where(['collection_path' => 'status', 'document_id' => $uid])->first();
        $payload = $existing ? (json_decode((string) $existing->payload, true) ?: []) : [];
        $previous = array_values(array_filter((array) ($payload['statusItems'] ?? []), fn ($item) => (int) ($item['expiresAtMs'] ?? 0) > $nowMs));
        $payload = array_merge($payload, [
            'userId' => $uid,
            'userName' => (string) ($data['userName'] ?? $payload['userName'] ?? $user->name ?? ''),
            'userAvatar' => (string) ($data['userAvatar'] ?? $payload['userAvatar'] ?? $user->avatar_url ?? ''),
            'updatedAt' => $nowIso,
            'updatedAtMs' => (int) ($data['updatedAtMs'] ?? $nowMs),
            'statusItems' => array_merge($previous, $items),
        ]);

        DB::table('firebase_documents')->updateOrInsert(
            ['collection_path' => 'status', 'document_id' => $uid],
            ['parent_document_path' => null, 'payload' => json_encode($payload), 'firebase_updated_at' => $nowIso, 'updated_at' => $nowIso, 'created_at' => $existing?->created_at ?? $nowIso]
        );

        foreach ($items as $item) {
            DB::table('statuses')->updateOrInsert(
                ['public_id' => (string) $item['id']],
                ['user_id' => $user->id, 'body' => $item['text'] ?? null, 'media_url' => ($item['videoUrl'] ?: ($item['imageUrl'] ?? null)), 'expires_at' => Carbon::createFromTimestampMs((int) $item['expiresAtMs']), 'created_at' => $nowIso, 'updated_at' => $nowIso]
            );
        }

        return response()->json(['ok' => true, 'id' => $uid, 'data' => $payload], $existing ? 200 : 201);
    }
}
