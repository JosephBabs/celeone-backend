<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Services\Outbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReactionController extends Controller
{
    use ApiResponses;

    public function show(Request $request, string $post)
    {
        $record = $this->post($post);
        $imported = $this->importedPost($record?->firebase_id ?: $post);
        abort_unless($record || $imported, 404, 'Post not found.');

        $publicId = (string) ($record?->firebase_id ?: $post);
        $actorId = $this->actorId($request);

        $mirror = DB::table('firebase_documents')->where([
            'collection_path' => 'posts/' . $publicId . '/reactions',
            'document_id' => $actorId,
        ])->first();

        $reaction = $mirror ? ($this->payload($mirror)['reaction'] ?? 'like') : null;

        if (! $reaction && $record) {
            $reaction = DB::table('reactions')->where([
                'post_id' => $record->id,
                'user_id' => $request->user()->id,
            ])->value('reaction_type');
        }

        return $this->success([
            'reaction' => $reaction,
            'reacted' => (bool) $reaction,
            'reactions_count' => $this->reactionCount($record, $publicId),
        ]);
    }

    public function upsert(Request $request, string $post, Outbox $outbox)
    {
        $data = $request->validate([
            'reaction' => 'required|in:like,love,haha,wow,sad,care,pray',
            'actorType' => 'nullable|in:user,page',
            'actorId' => 'nullable|string|max:191',
            'actorName' => 'nullable|string|max:255',
            'actorAvatar' => 'nullable|string|max:2000',
            'actorOwnerId' => 'nullable|string|max:191',
            'pageId' => 'nullable|string|max:191',
        ]);

        $record = $this->post($post);
        $imported = $this->importedPost($record?->firebase_id ?: $post);
        abort_unless($record || $imported, 404, 'Post not found.');

        $publicId = (string) ($record?->firebase_id ?: $post);
        $actorType = (string) ($data['actorType'] ?? 'user');
        $actorId = $this->actorId($request, $data);

        if ($actorType === 'page') {
            $this->authorizePageActor($request, $actorId);
        }

        $this->ensureMetricRow($record);

        $count = DB::transaction(function () use (
            $request,
            $record,
            $publicId,
            $actorId,
            $actorType,
            $data,
            $outbox
        ): int {
            // Serialize every reaction count mutation for this post.
            // For normal Laravel posts, post_metrics is the lock row.
            // For imported-only posts, the root mirror row is the lock row.
            $baseCount = $this->lockedReactionCount($record, $publicId);

            // Prevent two simultaneous taps/requests from the same actor from
            // both observing "not reacted" before the mirror row is inserted.
            DB::select(
                'select pg_advisory_xact_lock(hashtext(?))',
                ['celeone:reaction:' . $publicId . ':' . $actorId]
            );

            $hadReaction = $this->actorPreviouslyReacted(
                $request,
                $record,
                $publicId,
                $actorId,
                $actorType
            );

            if ($record && $actorType === 'user') {
                DB::table('reactions')->updateOrInsert(
                    [
                        'user_id' => $request->user()->id,
                        'post_id' => $record->id,
                    ],
                    [
                        'reaction_type' => $data['reaction'],
                        'updated_at' => now(),
                    ],
                );
            }

            $now = now();

            $existingMirror = DB::table('firebase_documents')->where([
                'collection_path' => 'posts/' . $publicId . '/reactions',
                'document_id' => $actorId,
            ])->first();

            $payload = [
                'userId' => (string) (
                    $request->user()->firebase_uid
                    ?: $request->user()->legacy_firebase_uid
                    ?: $request->user()->id
                ),
                'actorDocId' => $actorId,
                'actorId' => $actorId,
                'actorType' => $actorType,
                'actorOwnerId' => $actorType === 'page'
                    ? (string) ($request->user()->firebase_uid ?: $request->user()->id)
                    : null,
                'actorName' => (string) (
                    $data['actorName'] ?? $request->user()->name ?? 'Member'
                ),
                'actorAvatar' => (string) (
                    $data['actorAvatar'] ?? $request->user()->avatar_url ?? ''
                ),
                'reaction' => $data['reaction'],
                'postId' => $publicId,
                'pageId' => $actorType === 'page' ? $actorId : null,
                'updatedAt' => $now->toISOString(),
                'updatedAtMs' => $now->getTimestampMs(),
                'createdAt' => $existingMirror
                    ? ($this->payload($existingMirror)['createdAt'] ?? $now->toISOString())
                    : $now->toISOString(),
                'createdAtMs' => $existingMirror
                    ? ($this->payload($existingMirror)['createdAtMs'] ?? $now->getTimestampMs())
                    : $now->getTimestampMs(),
            ];

            DB::table('firebase_documents')->updateOrInsert(
                [
                    'collection_path' => 'posts/' . $publicId . '/reactions',
                    'document_id' => $actorId,
                ],
                [
                    'parent_document_path' => 'posts/' . $publicId,
                    'payload' => json_encode($payload),
                    'firebase_updated_at' => $now,
                    'updated_at' => $now,
                ],
            );

            $count = $hadReaction ? $baseCount : $baseCount + 1;
            $this->persistReactionCount($record, $publicId, $actorId, true, $count);

            $outbox->record('reaction.updated', 'post', $publicId, [
                'post_id' => $publicId,
                'user_id' => $request->user()->id,
                'actor_id' => $actorId,
                'actor_type' => $actorType,
                'reaction' => $data['reaction'],
                'reactions_count' => $count,
            ]);

            return $count;
        }, 3);

        return $this->success([
            'reaction' => $data['reaction'],
            'reacted' => true,
            'reactions_count' => $count,
            'likes' => $count,
            'actor_id' => $actorId,
        ]);
    }

    public function destroy(Request $request, string $post, Outbox $outbox)
    {
        $record = $this->post($post);
        $imported = $this->importedPost($record?->firebase_id ?: $post);
        abort_unless($record || $imported, 404, 'Post not found.');

        $publicId = (string) ($record?->firebase_id ?: $post);
        $actorType = (string) $request->input('actorType', 'user');
        $actorId = $this->actorId($request, $request->all());

        if ($actorType === 'page') {
            $this->authorizePageActor($request, $actorId);
        }

        $this->ensureMetricRow($record);

        $count = DB::transaction(function () use (
            $request,
            $record,
            $publicId,
            $actorType,
            $actorId,
            $outbox
        ): int {
            $baseCount = $this->lockedReactionCount($record, $publicId);

            DB::select(
                'select pg_advisory_xact_lock(hashtext(?))',
                ['celeone:reaction:' . $publicId . ':' . $actorId]
            );

            $hadReaction = $this->actorPreviouslyReacted(
                $request,
                $record,
                $publicId,
                $actorId,
                $actorType
            );

            if ($record && $actorType === 'user') {
                DB::table('reactions')->where([
                    'user_id' => $request->user()->id,
                    'post_id' => $record->id,
                ])->delete();
            }

            DB::table('firebase_documents')->where([
                'collection_path' => 'posts/' . $publicId . '/reactions',
                'document_id' => $actorId,
            ])->delete();

            $count = $hadReaction ? max(0, $baseCount - 1) : $baseCount;
            $this->persistReactionCount($record, $publicId, $actorId, false, $count);

            $outbox->record('reaction.updated', 'post', $publicId, [
                'post_id' => $publicId,
                'user_id' => $request->user()->id,
                'actor_id' => $actorId,
                'actor_type' => $actorType,
                'reaction' => null,
                'reactions_count' => $count,
            ]);

            return $count;
        }, 3);

        return $this->success([
            'reaction' => null,
            'reacted' => false,
            'reactions_count' => $count,
            'likes' => $count,
            'actor_id' => $actorId,
        ]);
    }

    private function post(string $id): ?Post
    {
        return Post::where('id', ctype_digit($id) ? (int) $id : -1)
            ->orWhere('firebase_id', $id)
            ->first();
    }

    private function importedPost(string $id): ?object
    {
        return DB::table('firebase_documents')->where([
            'collection_path' => 'posts',
            'document_id' => $id,
        ])->first();
    }

    private function actorId(Request $request, array $data = []): string
    {
        $type = (string) (
            $data['actorType'] ?? $request->input('actorType', 'user')
        );

        if ($type === 'page') {
            $id = trim((string) (
                $data['actorId']
                ?? $data['pageId']
                ?? $request->input('actorId', $request->input('pageId', ''))
            ));
            abort_unless($id !== '', 422, 'Page actor id is required.');
            return $id;
        }

        return (string) (
            $request->user()->firebase_uid
            ?: $request->user()->legacy_firebase_uid
            ?: $request->user()->id
        );
    }

    private function authorizePageActor(Request $request, string $pageId): void
    {
        if ($request->user()->isAdmin()) return;

        $owned = DB::table('pages')
            ->where('owner_id', $request->user()->id)
            ->where(function ($q) use ($pageId): void {
                $q->where('firebase_id', $pageId)
                    ->orWhere('public_id', $pageId)
                    ->orWhere('id', ctype_digit($pageId) ? (int) $pageId : -1);
            })
            ->exists();

        if (! $owned) {
            $row = DB::table('firebase_documents')->where([
                'collection_path' => 'pages',
                'document_id' => $pageId,
            ])->first();

            $payload = $row ? $this->payload($row) : [];
            $keys = array_filter([
                (string) $request->user()->id,
                (string) $request->user()->firebase_uid,
                (string) $request->user()->legacy_firebase_uid,
            ]);
            $owners = array_filter([
                (string) ($payload['ownerId'] ?? ''),
                (string) ($payload['createdBy'] ?? ''),
                (string) ($payload['userId'] ?? ''),
            ]);
            $owned = (bool) array_intersect($keys, $owners);
        }

        abort_unless($owned, 403, 'You cannot act as this page.');
    }

    private function ensureMetricRow(?Post $record): void
    {
        if (! $record) return;

        DB::table('post_metrics')->insertOrIgnore([
            'post_id' => $record->id,
            'reactions_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function lockedReactionCount(?Post $record, string $publicId): int
    {
        if ($record) {
            $metric = DB::table('post_metrics')
                ->where('post_id', $record->id)
                ->lockForUpdate()
                ->first();

            return max(0, (int) ($metric?->reactions_count ?? 0));
        }

        $row = DB::table('firebase_documents')
            ->where([
                'collection_path' => 'posts',
                'document_id' => $publicId,
            ])
            ->lockForUpdate()
            ->first();

        if (! $row) return 0;

        $payload = $this->payload($row);

        return max(0, (int) (
            $payload['metrics']['reactions']
            ?? $payload['reactions_count']
            ?? $payload['likes']
            ?? 0
        ));
    }

    private function reactionCount(?Post $record, string $publicId): int
    {
        if ($record) {
            $metric = DB::table('post_metrics')
                ->where('post_id', $record->id)
                ->value('reactions_count');

            if ($metric !== null) {
                return max(0, (int) $metric);
            }
        }

        $row = $this->importedPost($publicId);

        if ($row) {
            $payload = $this->payload($row);
            return max(0, (int) (
                $payload['metrics']['reactions']
                ?? $payload['reactions_count']
                ?? $payload['likes']
                ?? 0
            ));
        }

        return 0;
    }

    private function actorPreviouslyReacted(
        Request $request,
        ?Post $record,
        string $publicId,
        string $actorId,
        string $actorType,
    ): bool {
        if ($record && $actorType === 'user') {
            if (DB::table('reactions')->where([
                'post_id' => $record->id,
                'user_id' => $request->user()->id,
            ])->exists()) {
                return true;
            }
        }

        if (DB::table('firebase_documents')->where([
            'collection_path' => 'posts/' . $publicId . '/reactions',
            'document_id' => $actorId,
        ])->exists()) {
            return true;
        }

        // Legacy clients stored membership in likedBy on the root mirror.
        // Preserve that knowledge so the first Laravel write does not
        // double-count an already existing historical reaction.
        $row = $this->importedPost($publicId);

        if ($row) {
            $payload = $this->payload($row);
            $keys = array_filter([
                $actorId,
                (string) $request->user()->firebase_uid,
                (string) $request->user()->legacy_firebase_uid,
                (string) $request->user()->email,
            ]);
            $likedBy = array_map('strval', (array) ($payload['likedBy'] ?? []));

            if ((bool) array_intersect($keys, $likedBy)) {
                return true;
            }
        }

        return false;
    }

    private function persistReactionCount(
        ?Post $record,
        string $publicId,
        string $actorId,
        bool $add,
        int $count
    ): void {
        if ($record) {
            DB::table('post_metrics')
                ->where('post_id', $record->id)
                ->update([
                    'reactions_count' => max(0, $count),
                    'updated_at' => now(),
                ]);
        }

        $this->syncPostMirror($publicId, $actorId, $add, max(0, $count));
    }

    private function syncPostMirror(
        string $postId,
        string $actorId,
        bool $add,
        int $count
    ): void {
        $row = $this->importedPost($postId);
        if (! $row) return;

        $payload = $this->payload($row);

        $likedBy = array_values(array_unique(array_filter(
            (array) ($payload['likedBy'] ?? []),
            fn ($value) => (string) $value !== $actorId
        )));

        if ($add) {
            $likedBy[] = $actorId;
        }

        $payload['likedBy'] = array_values(array_unique($likedBy));
        $payload['likes'] = $count;
        $payload['reactions_count'] = $count;
        $payload['metrics'] = array_merge(
            (array) ($payload['metrics'] ?? []),
            ['reactions' => $count]
        );
        $payload['backendMetricsUpdatedAtMs'] = now()->getTimestampMs();
        $payload['updatedAtMs'] = now()->getTimestampMs();

        DB::table('firebase_documents')
            ->where('id', $row->id)
            ->update([
                'payload' => json_encode($payload),
                'firebase_updated_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function payload(object $row): array
    {
        return is_array($row->payload)
            ? $row->payload
            : (json_decode((string) $row->payload, true) ?: []);
    }
}
