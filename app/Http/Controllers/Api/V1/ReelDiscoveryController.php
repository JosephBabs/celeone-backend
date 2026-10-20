<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Feed\FeedAudience;
use App\Services\SocialMetrics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class ReelDiscoveryController
{
    public function __invoke(Request $request)
    {
        $input = $request->validate([
            'cursor' => 'nullable|string|max:4096',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $user = $request->user();
        $limit = (int) ($input['limit'] ?? 30);

        /*
         * Fresh Reel session:
         * - stable DB snapshot
         * - random seed for creator ordering
         * - random seed for each creator's reels
         */
        $state = [
            'uid' => (int) $user->id,
            'snapshot' => (int) DB::table('firebase_documents')->max('id'),
            'expires' => time() + 7200,

            'creatorSeed' => bin2hex(random_bytes(16)),
            'reelSeed' => bin2hex(random_bytes(16)),

            'afterRank' => null,
            'afterAuthor' => null,
            'afterId' => null,
        ];

        if (! empty($input['cursor'])) {
            try {
                $state = json_decode(
                    Crypt::decryptString($input['cursor']),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            } catch (\Throwable $e) {
                abort(422, 'Invalid reel cursor.');
            }

            abort_unless(
                (int) ($state['uid'] ?? 0) === (int) $user->id
                && (int) ($state['expires'] ?? 0) > time()
                && ! empty($state['creatorSeed'])
                && ! empty($state['reelSeed']),
                410,
                'Refresh reels.'
            );
        }

        /*
         * Add user ID so two users don't receive the same random sequence.
         */
        $creatorSeed = $state['creatorSeed'].'|'.$user->id;
        $reelSeed = $state['reelSeed'].'|'.$user->id;

        $audience = new FeedAudience($user);

        /*
         * Resolve creator reliably.
         *
         * Normal:
         *     feed_author_id
         *
         * Compatibility:
         *     payload.authorId
         *
         * Last fallback:
         *     document_id
         *
         * The last fallback means a malformed reel doesn't accidentally get
         * grouped with every other reel missing an author.
         */
        $authorExpression = "
            COALESCE(
                NULLIF(feed_author_id, ''),
                NULLIF(payload->>'authorId', ''),
                document_id
            )
        ";

        /*
         * First stage:
         *
         * Randomize reels INSIDE each creator and number them:
         *
         * Creator A:
         *   random reel -> rank 1
         *   random reel -> rank 2
         *   random reel -> rank 3
         *
         * Creator B:
         *   random reel -> rank 1
         *   random reel -> rank 2
         *
         * etc.
         */
        $ranked = $audience
            ->query('reels')
            ->where(
                'id',
                '<=',
                (int) $state['snapshot']
            )
            ->whereRaw(
                '('.
                    "COALESCE(payload->>'videoUrl', '') <> '' ".
                    'OR ('.
                        "jsonb_typeof(payload->'media') = 'array' ".
                        "AND payload->'media' <> '[]'::jsonb".
                    ')'.
                ')'
            )
            ->select([
                'id',
                'document_id',
                'feed_author_id',
                'feed_owner_id',
                'feed_page_id',
                'feed_created_at_ms',
                'feed_audience',
                'feed_status',
                'feed_tags',
                'feed_revision',
                'feed_popularity',
                'payload',
            ])
            ->selectRaw(
                "{$authorExpression} AS author_key"
            )
            ->selectRaw(
                "
                ROW_NUMBER() OVER (
                    PARTITION BY {$authorExpression}
                    ORDER BY
                        md5(document_id || ?),
                        document_id
                ) AS author_rank
                ",
                [$reelSeed]
            )
            ->selectRaw(
                "
                md5(
                    ({$authorExpression}) || ?
                ) AS author_order
                ",
                [$creatorSeed]
            );

        /*
         * Second stage:
         *
         * Order by:
         *
         * 1. author_rank
         * 2. random creator order
         * 3. document id tie breaker
         *
         * Therefore:
         *
         * ROUND 1:
         * A1, C1, B1, D1
         *
         * ROUND 2:
         * A2, C2, B2, D2
         *
         * ROUND 3:
         * A3, C3, B3, D3
         *
         * The creator order remains the same between rounds, which means
         * the LAST creator of one round cannot be the FIRST creator of the
         * next round when 2+ creators still have reels.
         */
        $query = DB::query()
            ->fromSub($ranked, 'ranked_reels');

        /*
         * Cursor/keyset pagination.
         *
         * Do not use OFFSET.
         * Do not reshuffle while the user scrolls.
         */
        if (
            $state['afterRank'] !== null
            && $state['afterAuthor'] !== null
            && $state['afterId'] !== null
        ) {
            $afterRank = (int) $state['afterRank'];
            $afterAuthor = (string) $state['afterAuthor'];
            $afterId = (string) $state['afterId'];

            $query->where(function ($q) use (
                $afterRank,
                $afterAuthor,
                $afterId
            ) {
                $q->where(
                    'author_rank',
                    '>',
                    $afterRank
                )
                    ->orWhere(function ($q) use (
                        $afterRank,
                        $afterAuthor
                    ) {
                        $q->where(
                            'author_rank',
                            '=',
                            $afterRank
                        )
                            ->where(
                                'author_order',
                                '>',
                                $afterAuthor
                            );
                    })
                    ->orWhere(function ($q) use (
                        $afterRank,
                        $afterAuthor,
                        $afterId
                    ) {
                        $q->where(
                            'author_rank',
                            '=',
                            $afterRank
                        )
                            ->where(
                                'author_order',
                                '=',
                                $afterAuthor
                            )
                            ->where(
                                'document_id',
                                '>',
                                $afterId
                            );
                    });
            });
        }

        $rows = $query
            ->orderBy('author_rank')
            ->orderBy('author_order')
            ->orderBy('document_id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;

        $selected = $rows
            ->take($limit)
            ->values();

        /*
         * Save exact location in the randomized sequence.
         */
        if ($last = $selected->last()) {
            $state['afterRank'] =
                (int) $last->author_rank;

            $state['afterAuthor'] =
                (string) $last->author_order;

            $state['afterId'] =
                (string) $last->document_id;
        }

        $items = $selected
            ->map(function ($row) use ($audience) {
                $data = json_decode(
                    $row->payload,
                    true
                ) ?: [];

                return array_merge(
                    $data,
                    [
                        'id' => (string) $row->document_id,

                        'reelId' => (string) $row->document_id,

                        'type' => 'reel',

                        'authorId' => $data['authorId']
                            ?? $row->feed_author_id
                            ?? $row->author_key,

                        'createdAtMs' => (int) $row->feed_created_at_ms,

                        'feedReason' => $audience->reason($row),
                    ]
                );
            })
            ->values();

        $items = app(SocialMetrics::class)->decorate(collect($items)->all(), 'reels');

        return response()->json([
            'items' => $items,

            'hasMore' => $hasMore,

            'nextCursor' => $hasMore && $selected->isNotEmpty()
                    ? Crypt::encryptString(
                        json_encode(
                            $state,
                            JSON_UNESCAPED_SLASHES
                        )
                    )
                    : null,
        ]);
    }
}
