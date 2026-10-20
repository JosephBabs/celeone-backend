<?php

namespace App\Services;

use App\Models\User;
use App\Services\Feed\FeedAudience;
use Illuminate\Support\Facades\DB;

class SocialContent
{
    public function payload(object $row): array
    {
        return is_array($row->payload) ? $row->payload : (json_decode($row->payload, true) ?: []);
    }

    public function find(string $type, string $id): ?array
    {
        $collection = match ($type) {
            'posts' => 'posts', 'reels' => 'reels', 'lives' => 'social_live_sessions', default => null
        };
        if (! $collection) {
            return null;
        }
        $row = DB::table('firebase_documents')->where(['collection_path' => $collection, 'document_id' => $id])->first();
        if (! $row && $type === 'posts' && ctype_digit($id) && strlen($id) < 19) {
            $legacy = DB::table('posts')->where('id', $id)->value('firebase_id');
            if ($legacy) {
                $row = DB::table('firebase_documents')->where(['collection_path' => 'posts', 'document_id' => $legacy])->first();
            }
        }
        if (! $row) {
            return null;
        }
        $data = $this->payload($row);
        $kind = $collection === 'social_live_sessions' ? 'live' : ($data['type'] ?? ($collection === 'reels' ? 'reel' : 'text'));
        if ($collection === 'reels') {
            $postId = (string) ($data['postId'] ?? $data['sourcePostId'] ?? $id);
            $post = DB::table('firebase_documents')->where(['collection_path' => 'posts', 'document_id' => $postId])->first();
            if ($post) {
                $row = $post;
                $data = $this->payload($post);
                $collection = 'posts';
            }
            $kind = 'reel';
        } elseif ($collection === 'posts' && DB::table('firebase_documents')->where('collection_path', 'reels')->where(fn ($q) => $q->where('document_id', $row->document_id)->orWhere('payload->postId', $row->document_id)->orWhere('payload->sourcePostId', $row->document_id))->exists()) {
            $kind = 'reel';
        }
        $page = (string) ($data['pageId'] ?? (($data['actorType'] ?? '') === 'page' ? ($data['actorId'] ?? '') : (($data['hostType'] ?? '') === 'page' ? ($data['hostId'] ?? '') : '')));

        return ['key' => $collection.':'.$row->document_id, 'id' => (string) $row->document_id, 'collection' => $collection, 'row' => $row, 'data' => $data, 'kind' => $kind, 'pageId' => $page ?: null];
    }

    public function published(array $content): bool
    {
        $data = $content['data'];

        return ! ($data['deleted'] ?? false) && empty($data['deletedAt']) && in_array($data['status'] ?? 'published', ['published', 'active', 'live', 'ended'], true);
    }

    public function visible(User $user, array $content): bool
    {
        if (! $this->published($content)) {
            return false;
        }
        if ($content['collection'] === 'social_live_sessions') {
            $audience = $content['data']['audience'] ?? 'public';

            return $audience === 'public' || in_array((string) ($content['data']['hostOwnerId'] ?? $content['data']['hostId'] ?? ''), SocialNotification::keys($user), true);
        }

        return (new FeedAudience($user))->query($content['collection'])->where('document_id', $content['id'])->exists();
    }

    public function page(string $id): array
    {
        $row = DB::table('firebase_documents')->where(['collection_path' => 'pages', 'document_id' => $id])->first();
        abort_unless($row, 404, 'Page not found.');

        return $this->payload($row);
    }

    public function canManage(User $user, string $id): bool
    {
        $data = $this->page($id);
        $keys = SocialNotification::keys($user);
        if (array_intersect($keys, array_filter([$data['ownerId'] ?? null, $data['userId'] ?? null, $data['createdBy'] ?? null]))) {
            return true;
        }
        foreach ($keys as $key) {
            if (in_array($data['roles'][$key] ?? null, ['owner', 'admin', 'editor'], true)) {
                return true;
            }
        }

        return false;
    }

    public function pageContents(string $id): array
    {
        $rows = DB::table('firebase_documents')->whereIn('collection_path', ['posts', 'reels', 'social_live_sessions'])
            ->where(fn ($q) => $q->where('payload->pageId', $id)
                ->orWhere(fn ($q) => $q->where('payload->actorType', 'page')->where('payload->actorId', $id))
                ->orWhere(fn ($q) => $q->where('payload->hostType', 'page')->where('payload->hostId', $id)))->get();
        $postIds = [];
        foreach ($rows as $row) {
            $data = $this->payload($row);
            if ($row->collection_path === 'posts') {
                $postIds[] = $row->document_id;
            }
            if ($row->collection_path === 'reels') {
                $postIds[] = (string) ($data['postId'] ?? $data['sourcePostId'] ?? $row->document_id);
            }
        }
        $posts = DB::table('firebase_documents')->where('collection_path', 'posts')->whereIn('document_id', $postIds)->get()->keyBy('document_id');
        $mirrors = DB::table('firebase_documents')->where('collection_path', 'reels')->where(fn ($q) => $q->whereIn('document_id', $postIds)->orWhereIn('payload->postId', $postIds)->orWhereIn('payload->sourcePostId', $postIds))->get();
        $reelPosts = [];
        foreach ($mirrors as $mirror) {
            $data = $this->payload($mirror);
            $reelPosts[(string) ($data['postId'] ?? $data['sourcePostId'] ?? $mirror->document_id)] = true;
        }
        $result = [];
        foreach ($rows as $row) {
            $collection = $row->collection_path;
            $data = $this->payload($row);
            $kind = $collection === 'social_live_sessions' ? 'live' : ($data['type'] ?? ($collection === 'reels' ? 'reel' : 'text'));
            if ($collection === 'reels') {
                $post = $posts->get((string) ($data['postId'] ?? $data['sourcePostId'] ?? $row->document_id));
                if ($post) {
                    $row = $post;
                    $data = $this->payload($post);
                    $collection = 'posts';
                }
                $kind = 'reel';
            } elseif ($collection === 'posts' && isset($reelPosts[$row->document_id])) {
                $kind = 'reel';
            }
            $page = (string) ($data['pageId'] ?? (($data['actorType'] ?? '') === 'page' ? ($data['actorId'] ?? '') : (($data['hostType'] ?? '') === 'page' ? ($data['hostId'] ?? '') : '')));
            if ($page !== $id || ($data['deleted'] ?? false) || ! empty($data['deletedAt']) || in_array($data['status'] ?? '', ['deleted', 'removed'], true)) {
                continue;
            }
            $key = $collection.':'.$row->document_id;
            $result[$key] = ['key' => $key, 'id' => (string) $row->document_id, 'collection' => $collection, 'row' => $row, 'data' => $data, 'kind' => $kind, 'pageId' => $page];
        }

        return array_values($result);
    }
}
