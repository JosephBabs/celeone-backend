<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class SocialMetrics
{
    public function __construct(private SocialContent $content) {}

    public function isOwner(User $user, array $content): bool
    {
        $data = $content['data'];
        if (array_intersect(SocialNotification::keys($user), array_filter([$data['ownerId'] ?? null, $data['actorOwnerId'] ?? null, $data['authorId'] ?? null, $data['hostOwnerId'] ?? null]))) {
            return true;
        }

        return $content['pageId'] && $this->content->canManage($user, $content['pageId']);
    }

    public function record(User $user, string $type, string $id, string $eventId, string $eventType = 'VIEW', int $duration = 0): array
    {
        $content = $this->content->find($type, $id);
        abort_unless($content, 404, 'Content not found.');
        abort_unless($this->content->visible($user, $content), 403, 'Content is not visible to you.');
        if ($this->isOwner($user, $content)) {
            return array_merge($this->counts($content['key']), ['created' => false, 'excluded' => 'owner']);
        }

        return DB::transaction(function () use ($user, $content, $eventId, $eventType, $duration) {
            $this->ensure($content);
            $metric = DB::table('social_content_metrics')->where('content_key', $content['key'])->lockForUpdate()->first();
            $inserted = DB::table('social_metric_events')->insertOrIgnore(['user_id' => $user->id, 'event_id' => $eventId, 'content_key' => $content['key'], 'event_type' => $eventType, 'duration_ms' => $duration, 'created_at' => now()]);
            if (! $inserted) {
                $previous = DB::table('social_metric_events')->where(['user_id' => $user->id, 'event_id' => $eventId])->first();
                abort_unless($previous && $previous->content_key === $content['key'] && ($previous->event_type === $eventType || ($previous->event_type === 'HISTORICAL_VIEW' && $eventType === 'VIEW' && str_starts_with($eventId, 'legacy-view:'))), 409, 'Event ID already used for a different event.');

                return array_merge($this->counts($content['key']), ['created' => false]);
            }
            $reached = in_array($eventType, ['VIEW', 'IMPRESSION'], true) || ($eventType === 'WATCH' && $duration >= 2000);
            $newViewer = $reached ? DB::table('social_content_viewers')->insertOrIgnore(['content_key' => $content['key'], 'user_id' => $user->id, 'first_seen_at' => now()]) : 0;
            DB::table('social_content_metrics')->where('content_key', $content['key'])->update([
                'page_id' => $content['pageId'],
                'views_count' => $metric->views_count + ($eventType === 'VIEW' ? 1 : 0),
                'reach_count' => $metric->reach_count + $newViewer,
                'impressions_count' => $metric->impressions_count + ($eventType === 'IMPRESSION' ? 1 : 0),
                'watch_ms' => $metric->watch_ms + ($eventType === 'WATCH' ? $duration : 0), 'updated_at' => now(),
            ]);
            if ($eventType === 'VIEW' && $content['collection'] !== 'social_live_sessions') {
                DB::table('post_views')->updateOrInsert(['public_id' => $user->id.':'.$content['collection'].':'.$content['id']], [
                    'user_id' => $user->id, 'parent_type' => $content['collection'], 'parent_id' => $content['id'], 'status' => 'viewed',
                    'data' => json_encode(['viewed_at' => now()->toISOString()]), 'created_at' => now(), 'updated_at' => now(),
                ]);
                $uid = (string) ($user->firebase_uid ?: $user->legacy_firebase_uid ?: $user->id);
                DB::table('firebase_documents')->updateOrInsert(['collection_path' => 'users/'.$uid.'/post_views', 'document_id' => $content['id']], [
                    'parent_document_path' => 'users/'.$uid, 'payload' => json_encode(['postId' => $content['id'], 'viewedAtMs' => now()->getTimestampMs()]),
                    'firebase_updated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->sync($content);

            return array_merge($this->counts($content['key']), ['created' => true]);
        }, 3);
    }

    public function ensure(array $content, int $legacyViews = 0): void
    {
        DB::table('social_content_metrics')->insertOrIgnore(['content_key' => $content['key'], 'page_id' => $content['pageId'], 'legacy_views' => $legacyViews, 'views_count' => $legacyViews, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function counts(string $key): array
    {
        $row = DB::table('social_content_metrics')->where('content_key', $key)->first();

        return ['views_count' => (int) ($row?->views_count ?? 0), 'viewCount' => (int) ($row?->views_count ?? 0), 'reach' => (int) ($row?->reach_count ?? 0), 'impressions' => (int) ($row?->impressions_count ?? 0), 'watchTimeMs' => (int) ($row?->watch_ms ?? 0)];
    }

    public function decorate(array $items, string $type = 'posts'): array
    {
        // Resolve mirrored reels once per batch; metric totals never come from cached payloads.
        $keys = [];
        $postIds = [];
        if ($type === 'reels') {
            $candidateIds = array_map(fn ($item) => (string) ($item['postId'] ?? $item['sourcePostId'] ?? $item['id'] ?? ''), $items);
            $postIds = DB::table('firebase_documents')->where('collection_path', 'posts')->whereIn('document_id', $candidateIds)->pluck('document_id')->all();
        }
        foreach ($items as $index => $item) {
            $id = (string) ($item['id'] ?? $item['postId'] ?? '');
            if ($id === '') {
                continue;
            }
            if ($type === 'reels') {
                $postId = (string) ($item['postId'] ?? $item['sourcePostId'] ?? $id);
                $keys[$index] = in_array($postId, $postIds, true) ? 'posts:'.$postId : 'reels:'.$id;
            } else {
                $keys[$index] = ($type === 'lives' ? 'social_live_sessions' : $type).':'.$id;
            }
        }
        $metrics = DB::table('social_content_metrics')->whereIn('content_key', array_values($keys))->get()->keyBy('content_key');
        foreach ($keys as $index => $key) {
            $row = $metrics->get($key);
            $views = (int) ($row?->views_count ?? 0);
            $items[$index]['views_count'] = $items[$index]['viewCount'] = $items[$index]['views'] = $views;
            $items[$index]['metrics'] = array_merge((array) ($items[$index]['metrics'] ?? []), ['views' => $views, 'reach' => (int) ($row?->reach_count ?? 0), 'impressions' => (int) ($row?->impressions_count ?? 0), 'watchTimeMs' => (int) ($row?->watch_ms ?? 0)]);
        }

        return $items;
    }

    public function sync(array $content): void
    {
        $counts = $this->counts($content['key']);
        $id = $content['id'];
        $rows = DB::table('firebase_documents')->where(fn ($q) => $q->where(fn ($q) => $q->where('collection_path', $content['collection'])->where('document_id', $id))
            ->when($content['collection'] === 'posts', fn ($q) => $q->orWhere(fn ($q) => $q->where('collection_path', 'reels')->where(fn ($q) => $q->where('document_id', $id)->orWhere('payload->postId', $id)->orWhere('payload->sourcePostId', $id)))))->lockForUpdate()->get();
        foreach ($rows as $row) {
            $data = $this->content->payload($row);
            $data['views_count'] = $data['viewCount'] = $data['views'] = $counts['views_count'];
            $data['metrics'] = array_merge((array) ($data['metrics'] ?? []), ['views' => $counts['views_count'], 'reach' => $counts['reach'], 'impressions' => $counts['impressions'], 'watchTimeMs' => $counts['watchTimeMs']]);
            $data['backendMetricsUpdatedAtMs'] = now()->getTimestampMs();
            DB::table('firebase_documents')->where('id', $row->id)->update(['payload' => json_encode($data), 'firebase_updated_at' => now(), 'updated_at' => now()]);
        }
        if ($content['collection'] === 'posts') {
            $postId = DB::table('posts')->where('firebase_id', $id)->value('id');
            if ($postId) {
                DB::table('post_metrics')->updateOrInsert(['post_id' => $postId],['views_count' => $counts['views_count'], 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }
}
