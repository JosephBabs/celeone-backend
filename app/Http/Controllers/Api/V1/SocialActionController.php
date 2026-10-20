<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Services\Outbox;
use App\Services\SocialContent;
use App\Services\SocialMetrics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SocialActionController extends Controller
{
    use ApiResponses;

    public function follow(Request $request, Outbox $outbox)
    {
        $data = $request->validate([
            'target_type' => 'required|in:user,page',
            'target_id' => 'required|string|max:191',
        ]);
        $id = $request->user()->id.':'.$data['target_type'].':'.$data['target_id'];
        DB::table('following')->updateOrInsert(
            ['public_id' => $id],
            [
                'user_id' => $request->user()->id,
                'parent_type' => $data['target_type'],
                'parent_id' => $data['target_id'],
                'status' => 'active',
                'data' => json_encode($data),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
        $outbox->record('follow.updated', $data['target_type'], $data['target_id'], [
            'user_id' => $request->user()->id,
            'following' => true,
        ]);

        return $this->success(['following' => true]);
    }

    public function unfollow(Request $request, string $type, string $id, Outbox $outbox)
    {
        DB::table('following')->where([
            'user_id' => $request->user()->id,
            'parent_type' => $type,
            'parent_id' => $id,
        ])->update(['status' => 'inactive', 'updated_at' => now()]);
        $outbox->record('follow.updated', $type, $id, [
            'user_id' => $request->user()->id,
            'following' => false,
        ]);

        return $this->success(['following' => false]);
    }

    public function savePost(Request $request, string $post)
    {
        $id = $request->user()->id.':'.$post;
        DB::table('saved_posts')->updateOrInsert(
            ['public_id' => $id],
            [
                'user_id' => $request->user()->id,
                'parent_type' => 'post',
                'parent_id' => $post,
                'status' => 'active',
                'data' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        return $this->success(['saved' => true]);
    }

    public function unsavePost(Request $request, string $post)
    {
        DB::table('saved_posts')->where([
            'user_id' => $request->user()->id,
            'parent_id' => $post,
        ])->delete();

        return $this->success(['saved' => false]);
    }

    /**
     * Laravel/PostgreSQL is the source of truth for unique social views.
     * The Firebase compatibility mirror is updated with the exact SQL count,
     * never incremented independently.
     */
    public function view(Request $request, string $type, string $id, Outbox $outbox)
    {
        if (in_array($type, ['posts', 'reels'], true)) {
            $content = app(SocialContent::class)->find($type, $id);
            abort_unless($content, 404, 'Content not found.');
            $event = $request->validate(['eventId' => 'sometimes|string|max:80|regex:/^[A-Za-z0-9_-]+$/']);
            $key = isset($event['eventId']) ? 'view:'.$event['eventId'] : 'legacy-view:'.sha1($content['key']);

            return $this->success(app(SocialMetrics::class)->record($request->user(), $type, $id, $key));
        }

        $key = $request->user()->id.':'.$type.':'.$id;

        $result = DB::transaction(function () use ($request, $type, $id, $key, $outbox): array {
            $alreadyViewed = DB::table('post_views')->where('public_id', $key)->exists();
            $post = null;
            if ($type === 'posts') {
                $post = Post::where('firebase_id', $id)
                    ->orWhere('id', ctype_digit($id) ? (int) $id : -1)
                    ->first();
            }
            $baseCount = $this->currentViewCount($type, $id, $post);

            DB::table('post_views')->updateOrInsert(
                ['public_id' => $key],
                [
                    'user_id' => $request->user()->id,
                    'parent_type' => $type,
                    'parent_id' => $id,
                    'status' => 'viewed',
                    'data' => json_encode(['viewed_at' => now()->toISOString()]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );

            // post_metrics can be seeded from legacy Firebase counts. Do not
            // replace that historical base with the number of API-era rows in
            // post_views; increment it only for a genuinely new viewer.
            $count = $alreadyViewed ? $baseCount : $baseCount + 1;

            if ($type === 'posts') {
                if ($post) {
                    DB::table('post_metrics')->updateOrInsert(
                        ['post_id' => $post->id],
                        [
                            'views_count' => $count,
                            'updated_at' => now(),
                            'created_at' => now(),
                        ],
                    );
                }
            }

            $this->syncViewMirror($type, $id, $count);

            if (! $alreadyViewed) {
                $outbox->record('view.recorded', rtrim($type, 's'), $id, [
                    'user_id' => $request->user()->id,
                    'views_count' => $count,
                ]);
            }

            return [
                'viewed' => true,
                'created' => ! $alreadyViewed,
                'views_count' => $count,
                'viewCount' => $count,
            ];
        });

        return $this->success($result);
    }

    public function readNotification(Request $request, string $id)
    {
        DB::table('notification_inbox')->where([
            'public_id' => $id,
            'user_id' => $request->user()->id,
        ])->update(['status' => 'read', 'updated_at' => now()]);

        return $this->success(['read' => true]);
    }

    public function readAll(Request $request)
    {
        DB::table('notification_inbox')
            ->where('user_id', $request->user()->id)
            ->where('status', '!=', 'read')
            ->update(['status' => 'read', 'updated_at' => now()]);

        return $this->success(['read' => true]);
    }

    private function currentViewCount(string $type, string $id, ?Post $post = null): int
    {
        if ($type === 'posts' && $post) {
            $count = DB::table('post_metrics')->where('post_id', $post->id)->value('views_count');
            if ($count !== null) {
                return max(0, (int) $count);
            }
        }

        $collection = match ($type) {
            'posts' => 'posts',
            'reels' => 'reels',
            'statuses' => 'status',
            default => null,
        };
        if (! $collection) {
            return 0;
        }
        $row = DB::table('firebase_documents')
            ->where('collection_path', $collection)
            ->where('document_id', $id)
            ->first();
        if (! $row) {
            return 0;
        }
        $payload = is_array($row->payload)
            ? $row->payload
            : (json_decode((string) $row->payload, true) ?: []);

        return max(0, (int) ($payload['metrics']['views'] ?? $payload['views_count'] ?? $payload['viewCount'] ?? $payload['views'] ?? 0));
    }

    private function syncViewMirror(string $type, string $id, int $count): void
    {
        $collection = match ($type) {
            'posts' => 'posts',
            'reels' => 'reels',
            'statuses' => 'status',
            default => null,
        };
        if (! $collection) {
            return;
        }

        $row = DB::table('firebase_documents')
            ->where('collection_path', $collection)
            ->where('document_id', $id)
            ->first();
        if (! $row) {
            return;
        }

        $payload = is_array($row->payload)
            ? $row->payload
            : (json_decode((string) $row->payload, true) ?: []);
        $payload['viewCount'] = $count;
        $payload['views'] = $count;
        $payload['metrics'] = array_merge((array) ($payload['metrics'] ?? []), ['views' => $count]);
        $payload['backendMetricsUpdatedAtMs'] = now()->getTimestampMs();
        $payload['updatedAtMs'] = max((int) ($payload['updatedAtMs'] ?? 0), now()->getTimestampMs());

        DB::table('firebase_documents')->where('id', $row->id)->update([
            'payload' => json_encode($payload),
            'firebase_updated_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
