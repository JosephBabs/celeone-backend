<?php

namespace App\Services\Feed;

use App\Models\User;
use App\Services\SocialContent;
use App\Services\SocialMetrics;
use Illuminate\Support\Facades\DB;

class FeedInteractions
{
    public function record(User $user, array $events): int
    {
        return DB::transaction(function () use ($user, $events): int {
            DB::table('feed_affinities')->insertOrIgnore([
                'user_id' => $user->id,
                'tag_scores' => json_encode((object) []),
                'author_scores' => json_encode((object) []),
                'updated_at' => now(),
            ]);

            $profile = DB::table('feed_affinities')
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            $tagScores = json_decode($profile->tag_scores ?? '{}', true) ?: [];
            $authorScores = json_decode($profile->author_scores ?? '{}', true) ?: [];

            $accepted = 0;

            $selfIds = array_values(array_filter(array_unique([
                (string) $user->id,
                (string) ($user->firebase_uid ?? ''),
                (string) ($user->legacy_firebase_uid ?? ''),
            ])));

            foreach ($events as $event) {
                $postId = (string) ($event['postId'] ?? '');
                $eventId = (string) ($event['eventId'] ?? '');
                $type = strtoupper((string) ($event['type'] ?? ''));
                $durationMs = max(0, min(300000, (int) ($event['durationMs'] ?? 0)));

                if ($postId === '' || $eventId === '') {
                    continue;
                }

                $inserted = DB::table('feed_interactions')->insertOrIgnore([
                    'user_id' => $user->id,
                    'event_id' => $eventId,
                    'post_id' => $postId,
                    'type' => $type,
                    'duration_ms' => $durationMs,
                    'event_day' => now()->toDateString(),
                    'created_at' => now(),
                ]);

                // Duplicate interaction/event: don't learn from it twice.
                if (! $inserted) {
                    continue;
                }

                $accepted++;
                if (in_array($type, ['VIEW', 'WATCH'], true)) {
                    $contentService = app(SocialContent::class);
                    $content = $contentService->find('posts', $postId) ?? $contentService->find('reels', $postId);
                    if ($content && $contentService->visible($user, $content)) {
                        app(SocialMetrics::class)->record($user, $content['collection'], $content['id'], 'feed:'.$eventId, $type === 'VIEW' ? 'IMPRESSION' : 'WATCH', $durationMs);
                    }
                }

                $document = DB::table('firebase_documents')
                    ->where('document_id', $postId)
                    ->whereNotNull('feed_created_at_ms')
                    ->orderByDesc('id')
                    ->first([
                        'feed_author_id',
                        'feed_tags',
                    ]);

                if (! $document) {
                    continue;
                }

                $weight = $this->weight($type, $durationMs);

                if ($weight == 0.0) {
                    continue;
                }

                /*
                 * TOPIC / TAG LEARNING
                 */
                $tags = json_decode($document->feed_tags ?: '[]', true) ?: [];

                foreach ($tags as $tag) {
                    $tag = trim(mb_strtolower((string) $tag));

                    if ($tag === '') {
                        continue;
                    }

                    $current = (float) ($tagScores[$tag] ?? 0);

                    // Gentle adaptation: old behavior still matters but new
                    // behavior can progressively change the profile.
                    $next = ($current * 0.985) + ($weight * 0.60);

                    $tagScores[$tag] = $this->clamp($next, -8.0, 12.0);
                }

                /*
                 * AUTHOR / CREATOR LEARNING
                 */
                $author = trim((string) ($document->feed_author_id ?? ''));

                if ($author !== '' && ! in_array($author, $selfIds, true)) {
                    $current = (float) ($authorScores[$author] ?? 0);

                    $next = ($current * 0.985) + $weight;

                    $authorScores[$author] = $this->clamp(
                        $next,
                        -12.0,
                        20.0
                    );
                }
            }

            // Keep the JSON affinity profiles bounded.
            $tagScores = $this->prune($tagScores, 250);
            $authorScores = $this->prune($authorScores, 300);

            DB::table('feed_affinities')
                ->where('user_id', $user->id)
                ->update([
                    'tag_scores' => json_encode((object) $tagScores),
                    'author_scores' => json_encode((object) $authorScores),
                    'updated_at' => now(),
                ]);

            return $accepted;
        }, 3);
    }

    /**
     * Rebuild author affinity from interactions already collected before V32.
     */
    public function rebuildAuthorScores(?int $onlyUserId = null): int
    {
        $usersQuery = DB::table('feed_interactions')
            ->select('user_id')
            ->distinct();

        if ($onlyUserId !== null) {
            $usersQuery->where('user_id', $onlyUserId);
        }

        $userIds = $usersQuery->pluck('user_id');
        $rebuilt = 0;

        foreach ($userIds as $userId) {
            $user = User::find($userId);

            if (! $user) {
                continue;
            }

            $scores = [];

            $selfIds = array_values(array_filter(array_unique([
                (string) $user->id,
                (string) ($user->firebase_uid ?? ''),
                (string) ($user->legacy_firebase_uid ?? ''),
            ])));

            $rows = DB::table('feed_interactions')
                ->where('user_id', $userId)
                ->orderBy('id')
                ->get([
                    'post_id',
                    'type',
                    'duration_ms',
                ]);

            if ($rows->isEmpty()) {
                continue;
            }

            $postIds = $rows
                ->pluck('post_id')
                ->filter()
                ->unique()
                ->values()
                ->all();

            $documents = DB::table('firebase_documents')
                ->whereIn('document_id', $postIds)
                ->whereNotNull('feed_created_at_ms')
                ->orderByDesc('id')
                ->get([
                    'document_id',
                    'feed_author_id',
                ])
                ->unique('document_id')
                ->keyBy('document_id');

            foreach ($rows as $row) {
                $document = $documents->get($row->post_id);

                if (! $document) {
                    continue;
                }

                $author = trim((string) ($document->feed_author_id ?? ''));

                if ($author === '' || in_array($author, $selfIds, true)) {
                    continue;
                }

                $weight = $this->weight(
                    strtoupper((string) $row->type),
                    (int) $row->duration_ms
                );

                $current = (float) ($scores[$author] ?? 0);

                $scores[$author] = $this->clamp(
                    ($current * 0.985) + $weight,
                    -12.0,
                    20.0
                );
            }

            $scores = $this->prune($scores, 300);

            DB::table('feed_affinities')->insertOrIgnore([
                'user_id' => $userId,
                'tag_scores' => json_encode((object) []),
                'author_scores' => json_encode((object) []),
                'updated_at' => now(),
            ]);

            DB::table('feed_affinities')
                ->where('user_id', $userId)
                ->update([
                    'author_scores' => json_encode((object) $scores),
                    'updated_at' => now(),
                ]);

            $rebuilt++;
        }

        return $rebuilt;
    }

    private function weight(string $type, int $durationMs): float
    {
        return match ($type) {
            'VIEW' => 0.08,
            'CLICK' => 0.25,

            'LIKE' => 1.00,
            'COMMENT' => 1.35,
            'SHARE' => 1.80,
            'SAVE' => 1.60,

            // Watch duration carries progressively stronger intent.
            'WATCH' => min(
                1.25,
                max(0.10, $durationMs / 30000)
            ),

            // Explicit/fast rejection must reduce future exposure.
            'SKIP' => -0.80,

            default => 0.0,
        };
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }

    private function prune(array $scores, int $limit): array
    {
        uasort(
            $scores,
            static fn ($a, $b) => abs((float) $b) <=> abs((float) $a)
        );

        return array_slice($scores, 0, $limit, true);
    }
}
