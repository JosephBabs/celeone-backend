<?php

namespace App\Services\Feed;

use App\Services\SocialMetrics;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FeedObjectCache
{
    public function get(array $rows): array
    {
        $keys = [];
        foreach ($rows as $row) {
            $keys[$row->document_id] = 'feed:post:'.$row->document_id.':'.$row->feed_revision;
        }
        $cached = [];
        try {
            $cached = Cache::store(config('feed.cache_store'))->many(array_values($keys));
        } catch (\Throwable $error) {
            Log::debug('Feed object cache unavailable; using PostgreSQL.', ['error' => $error->getMessage()]);
        }
        $result = [];
        $missing = [];
        foreach ($keys as $id => $key) {
            if (is_array($cached[$key] ?? null)) {
                $result[$id] = $cached[$key];
            } else {
                $missing[] = $id;
            }
        }
        if ($missing) {
            $writes = [];
            foreach (DB::table('firebase_documents')->where('collection_path', 'posts')->whereIn('document_id', $missing)->get(['document_id', 'payload', 'feed_revision']) as $row) {
                if ($keys[$row->document_id] !== 'feed:post:'.$row->document_id.':'.$row->feed_revision) {
                    continue;
                }
                $payload = json_decode($row->payload, true) ?: [];
                $payload['id'] = (string) $row->document_id;
                $result[$row->document_id] = $payload;
                // Use the revision read WITH the payload, preventing a racing edit
                // from publishing new content under an old version's cache key.
                $writes['feed:post:'.$row->document_id.':'.$row->feed_revision] = $payload;
            }
            try {
                Cache::store(config('feed.cache_store'))->putMany($writes, config('feed.object_ttl_seconds', 86400));
            } catch (\Throwable) { /* PostgreSQL remains authoritative. */
            }
        }
        $decorated = app(SocialMetrics::class)->decorate(array_values($result));

        return array_column($decorated, null, 'id');
    }
}
