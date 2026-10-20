<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Feed\FeedAudience;
use App\Services\SocialContent;
use App\Services\SocialMetrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** @tags Page analytics */
class PageAnalyticsController
{
    /** Owner-only lifetime statistics over all page publications, not a feed sample. Reach is summed per-content unique viewers. */
    public function show(Request $request, string $id, SocialContent $content): JsonResponse
    {
        abort_unless($content->canManage($request->user(), $id), 403, 'Only page managers can see analytics.');
        $items = $content->pageContents($id);
        $published = array_values(array_filter($items, fn ($item) => $content->published($item)));
        $keys = array_column($published, 'key');
        $metrics = DB::table('social_content_metrics')->whereIn('content_key', $keys)->get()->keyBy('content_key');
        $totals = ['publications' => count($published), 'totalViews' => 0, 'reach' => 0, 'uniqueAudience' => (int) DB::table('social_content_viewers')->whereIn('content_key', $keys)->distinct()->count('user_id'), 'impressions' => 0, 'watchTimeMs' => 0, 'totalLikes' => 0, 'totalComments' => 0, 'shares' => 0, 'legacyViews' => 0];
        $breakdown = [];
        $performance = [];
        $sql = DB::table('posts')->leftJoin('post_metrics', 'post_metrics.post_id', '=', 'posts.id')->whereIn('firebase_id', array_column($published, 'id'))->select('firebase_id', 'reactions_count', 'comments_count', 'shares_count')->get()->keyBy('firebase_id');
        foreach ($published as $item) {
            $metric = $metrics->get($item['key']);
            $data = $item['data'];
            $post = $sql->get($item['id']);
            $views = (int) ($metric?->views_count ?? 0);
            $reach = (int) ($metric?->reach_count ?? 0);
            $likes = (int) ($post?->reactions_count ?? $data['metrics']['reactions'] ?? $data['reactions_count'] ?? $data['likes'] ?? 0);
            $comments = (int) ($post?->comments_count ?? $data['metrics']['comments'] ?? $data['comments_count'] ?? $data['comments'] ?? 0);
            $shares = (int) ($data['metrics']['shares'] ?? $data['shares_count'] ?? $data['shares'] ?? $post?->shares_count ?? 0);
            $totals['totalViews'] += $views;
            $totals['reach'] += $reach;
            $totals['impressions'] += (int) ($metric?->impressions_count ?? 0);
            $totals['watchTimeMs'] += (int) ($metric?->watch_ms ?? 0);
            $totals['legacyViews'] += (int) ($metric?->legacy_views ?? 0);
            $totals['totalLikes'] += max(0, $likes);
            $totals['totalComments'] += max(0, $comments);
            $totals['shares'] += max(0, $shares);
            $kind = $item['kind'];
            if (in_array($kind, ['image', 'photo', 'gallery', 'mixed'], true)) {
                $kind = 'photo';
            }
            if (! in_array($kind, ['photo', 'video', 'reel', 'live'], true)) {
                $kind = 'text';
            }
            $breakdown[$kind] ??= ['publications' => 0, 'views' => 0, 'reach' => 0];
            $breakdown[$kind]['publications']++;
            $breakdown[$kind]['views'] += $views;
            $breakdown[$kind]['reach'] += $reach;
            $performance[] = ['id' => $item['id'], 'type' => $kind, 'title' => mb_substr((string) ($data['text'] ?? $data['title'] ?? $data['caption'] ?? ''), 0, 160), 'views' => $views, 'reach' => $reach, 'impressions' => (int) ($metric?->impressions_count ?? 0)];
        }
        usort($performance, fn ($a, $b) => [$b['reach'], $b['views'], $b['id']] <=> [$a['reach'], $a['views'], $a['id']]);
        $totals['engagement'] = $totals['totalLikes'] + $totals['totalComments'] + $totals['shares'];
        $totals['followers'] = (int) DB::table('firebase_documents')->where('collection_path', 'followers')->where('payload->targetId', $id)->where('payload->status', 'active')->count();
        $totals['following'] = (int) DB::table('firebase_documents')->where('collection_path', 'following')->where('payload->followerId', $id)->where('payload->followerType', 'page')->where('payload->status', 'active')->count();
        $totals['profileVisits'] = (int) DB::table('firebase_documents')->where('collection_path', 'page_profile_views/'.$id.'/viewers')->count();
        $totals['scheduledPosts'] = (int) DB::table('scheduled_social_posts')->where('page_id', $id)->where('status', 'scheduled')->count();
        foreach (['drafts' => 'draft', 'pendingReview' => 'pending', 'rejected' => 'rejected'] as $key => $status) {
            $totals[$key] = count(array_filter($items, fn ($item) => str_contains((string) ($item['data']['moderationStatus'] ?? $item['data']['status'] ?? ''), $status)));
        }

        return response()->json(['pageId' => $id, 'period' => 'lifetime', 'asOf' => now()->toISOString(), 'totals' => $totals, 'breakdown' => $breakdown, 'topContent' => array_slice($performance, 0, 20), 'definitions' => [
            'totalViews' => 'Recorded viewing sessions, plus retained historical view counts. Retry event IDs count once. Owner and page-manager views are excluded from new tracking.',
            'reach' => 'Sum of distinct authenticated viewers for each publication. One person viewing two publications contributes two.',
            'uniqueAudience' => 'Distinct authenticated viewers across all page content, counted once per page.',
            'history' => 'Reach is based on identifiable viewer records. Missing historical viewers cannot be reconstructed from aggregate view counts.',
        ]]);
    }

    /** Paginated page publications with current backend metrics. Managers can also fetch unpublished content. */
    public function content(Request $request, string $id, SocialContent $content, SocialMetrics $metrics): JsonResponse
    {
        $data = $request->validate(['limit' => 'sometimes|integer|min:1|max:100', 'offset' => 'sometimes|integer|min:0', 'includeUnpublished' => 'sometimes|boolean']);
        $content->page($id);
        $manage = $content->canManage($request->user(), $id);
        abort_if(($data['includeUnpublished'] ?? false) && ! $manage, 403);
        $all = $content->pageContents($id);
        $allowed = [];
        if (! $manage) {
            $audience = new FeedAudience($request->user());
            foreach (['posts', 'reels'] as $type) {
                foreach ($audience->query($type)->whereIn('document_id', array_column($all, 'id'))->pluck('document_id') as $visible) {
                    $allowed[$type.':'.$visible] = true;
                }
            }
        }
        $items = array_filter($all, fn ($item) => $item['collection'] !== 'social_live_sessions' && ($manage ? (($data['includeUnpublished'] ?? false) || $content->published($item)) : ($content->published($item) && isset($allowed[$item['key']]))));
        usort($items, fn ($a, $b) => [(int) ($b['data']['createdAtMs'] ?? 0), $b['id']] <=> [(int) ($a['data']['createdAtMs'] ?? 0), $a['id']]);
        $offset = $data['offset'] ?? 0;
        $limit = $data['limit'] ?? 30;
        $slice = array_slice($items, $offset, $limit);
        $result = [];
        $grouped = [];
        foreach ($slice as $index => $item) {
            $grouped[$item['collection']][$index] = array_merge($item['data'], ['id' => $item['id'], 'type' => $item['kind']]);
        }
        foreach ($grouped as $type => $group) {
            foreach ($metrics->decorate($group, $type) as $index => $item) {
                $result[$index] = $item;
            }
        }
        ksort($result);
        $result = array_values($result);

        return response()->json(['items' => $result, 'total' => count($items), 'hasMore' => $offset + $limit < count($items), 'nextOffset' => $offset + $limit < count($items) ? $offset + $limit : null]);
    }

    /** Record a unique authenticated page visitor. */
    public function visit(Request $request, string $id, SocialContent $content): JsonResponse
    {
        $content->page($id);
        if ($content->canManage($request->user(), $id)) {
            return response()->json(['created' => false]);
        }
        $user = $request->user();
        $uid = (string) ($user->firebase_uid ?: $user->legacy_firebase_uid ?: $user->id);
        $created = DB::table('firebase_documents')->insertOrIgnore(['collection_path' => 'page_profile_views/'.$id.'/viewers', 'document_id' => $uid, 'parent_document_path' => 'page_profile_views/'.$id, 'payload' => json_encode(['viewerId' => $uid, 'viewedAtMs' => now()->getTimestampMs()]), 'created_at' => now(), 'updated_at' => now(), 'firebase_updated_at' => now()]);

        return response()->json(['created' => (bool) $created]);
    }
}
