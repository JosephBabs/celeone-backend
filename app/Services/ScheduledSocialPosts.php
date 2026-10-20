<?php

namespace App\Services;

use App\Jobs\NotifyPageFollowers;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ScheduledSocialPosts
{
    public function page(User $user, ?string $pageId): array
    {
        abort_if($user->firebase_disabled, 403, 'This account cannot publish.');
        if (! $pageId) {
            return [];
        }
        $page = SocialNotification::payload(DB::table('firebase_documents')->where([
            'collection_path' => 'pages', 'document_id' => $pageId,
        ])->first());
        $keys = SocialNotification::keys($user);
        $allowed = array_intersect($keys, array_filter([
            $page['ownerId'] ?? null, $page['userId'] ?? null, $page['createdBy'] ?? null,
        ]));
        foreach ($keys as $key) {
            if (in_array($page['roles'][$key] ?? null, ['owner', 'admin', 'editor'], true)) {
                $allowed[] = $key;
            }
        }
        abort_unless($page && $allowed, 403, 'You no longer have permission to publish for this page.');

        return $page;
    }

    public function authorize(User $user, object $row): void
    {
        if ($row->page_id) {
            $this->page($user, $row->page_id);
        } else {
            abort_unless((int) $row->user_id === (int) $user->id, 403);
            $this->page($user, null);
        }
    }

    public function present(object $row): array
    {
        return [
            'id' => $row->id, 'payload' => json_decode($row->payload, true),
            'pageId' => $row->page_id, 'scheduledAt' => Carbon::parse($row->scheduled_at)->toISOString(),
            'timezone' => $row->timezone, 'status' => $row->status,
            'revision' => (int) $row->revision, 'lastError' => $row->last_error,
        ];
    }

    public function publish(string $id, ?User $actor = null, ?int $revision = null): ?object
    {
        return DB::transaction(function () use ($id, $actor, $revision): ?object {
            $row = DB::table('scheduled_social_posts')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($actor) {
                $this->authorize($actor, $row);
            }
            // The same ID is used for the post; retries and concurrent workers cannot duplicate it.
            if ($row->status === 'published') {
                return $row;
            }
            if (! $actor && ($row->status !== 'scheduled' || Carbon::parse($row->scheduled_at)->isFuture())) {
                return null;
            }
            abort_if($revision !== null && $revision !== (int) $row->revision, 409, 'This scheduled post changed. Refresh and try again.');
            $publisher = $actor ?: User::find($row->user_id);
            try {
                abort_unless($publisher, 403, 'Publisher account is unavailable.');
                $page = $this->page($publisher, $row->page_id);
            } catch (HttpException $error) {
                if ($actor) {
                    throw $error;
                }
                DB::table('scheduled_social_posts')->where('id', $id)->update([
                    'status' => 'blocked', 'last_error' => $error->getMessage(), 'updated_at' => now()->toISOString(),
                ]);

                return null;
            }
            $uid = (string) ($publisher->firebase_uid ?: $publisher->legacy_firebase_uid ?: $publisher->id);
            $payload = json_decode($row->payload, true);
            $name = $page['name'] ?? $publisher->name;
            $avatar = $row->page_id ? ($page['logoUrl'] ?? $page['avatar_url'] ?? $page['photoURL'] ?? '') : ($publisher->avatar_url ?? '');
            $now = now();
            $payload = array_merge($payload, [
                'authorId' => $uid, 'ownerId' => $uid, 'actorOwnerId' => $uid,
                'actorType' => $row->page_id ? 'page' : 'user', 'actorId' => $row->page_id ?: $uid,
                'pageId' => $row->page_id, 'authorName' => $name, 'authorAvatar' => $avatar,
                'author' => ['id' => $row->page_id ?: $uid, 'uid' => $row->page_id ?: $uid, 'name' => $name, 'displayName' => $name, 'photoURL' => $avatar, 'type' => $row->page_id ? 'page' : 'user'],
                'status' => 'published', 'moderationStatus' => 'pending',
                'createdAt' => $now->toISOString(), 'createdAtMs' => $now->getTimestampMs(),
                'updatedAt' => $now->toISOString(), 'updatedAtMs' => $now->getTimestampMs(),
            ]);
            if ($row->page_id) {
                $payload['audience'] = $payload['visibility'] = 'public';
            }
            $record = [
                'collection_path' => 'posts', 'document_id' => $id, 'parent_document_path' => null,
                'payload' => json_encode($payload), 'firebase_updated_at' => $now->toISOString(),
                'created_at' => $now->toISOString(), 'updated_at' => $now->toISOString(),
            ];
            DB::table('firebase_documents')->insert($record);
            if (($payload['type'] ?? '') === 'reel') {
                DB::table('firebase_documents')->insert(array_merge($record, [
                    'collection_path' => 'reels',
                    'payload' => json_encode(array_merge($payload, ['postId' => $id, 'videoUrl' => $payload['media'][0]['url'] ?? ''])),
                ]));
            }
            app(Outbox::class)->record('feed.item.created', 'post', $id, ['post_id' => $id]);
            NotifyPageFollowers::dispatch($id, $publisher->id)->afterCommit();
            DB::table('scheduled_social_posts')->where('id', $id)->update([
                'status' => 'published', 'published_at' => $now->toISOString(), 'last_error' => null,
                'revision' => (int) $row->revision + 1, 'updated_at' => $now->toISOString(),
            ]);

            return DB::table('scheduled_social_posts')->where('id', $id)->first();
        });
    }
}
