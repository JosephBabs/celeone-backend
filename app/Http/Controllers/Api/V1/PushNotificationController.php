<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Models\User;
use App\Services\FcmPushService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class PushNotificationController extends Controller
{
    use ApiResponses;

    public function registerDevice(Request $request) { return $this->storeDevice($request); }
    public function permissionState(Request $request) { return $this->storeDevice($request); }

    private function storeDevice(Request $request)
    {
        $data = $request->validate([
            'deviceId' => 'required|string|max:191',
            'token' => 'nullable|string|max:4096',
            'platform' => 'nullable|string|max:30',
            'appVersion' => 'nullable|string|max:50',
            'pushEnabled' => 'nullable|boolean',
            'permission' => 'nullable|array',
            'appState' => 'nullable|string|max:40',
            'meta' => 'nullable|array',
        ]);

        $user = $request->user();
        $uid = $this->primaryUid($user);
        $deviceId = trim((string) $data['deviceId']);
        $token = trim((string) ($data['token'] ?? ''));
        $enabled = (bool) ($data['pushEnabled'] ?? false) && $token !== '';
        $nowMs = (int) floor(microtime(true) * 1000);

        // Existing Cèlè One installations may already have the Node notification
        // service's push_devices schema. Keep it updated so both the legacy
        // notification worker and Laravel can address the same device.
        if ($this->usesNodePushSchema()) {
            if ($token !== '') {
                DB::table('push_devices')->where('token', $token)->where('uid', '!=', $uid)->delete();
            }
            DB::table('push_devices')->updateOrInsert(
                ['uid' => $uid, 'device_id' => $deviceId],
                [
                    'id' => $uid . ':' . $deviceId,
                    'token' => $token !== '' ? $token : null,
                    'platform' => $data['platform'] ?? 'android',
                    'app_version' => $data['appVersion'] ?? '',
                    'push_enabled' => $enabled,
                    'permission_authorization_status' => data_get($data, 'permission.authorizationStatus'),
                    'android_granted' => data_get($data, 'permission.androidGranted'),
                    'app_state' => $data['appState'] ?? 'active',
                    'invalid_token' => false,
                    'last_seen_at_ms' => $nowMs,
                    'updated_at_ms' => $nowMs,
                    'created_at_ms' => $nowMs,
                    'meta' => json_encode($data['meta'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ],
            );
        }

        // Laravel-native token registry used by V20. This is deliberately kept
        // in parallel during migration; it does not depend on Firebase/Firestore.
        if (Schema::hasTable('devices') && Schema::hasTable('push_tokens')) {
            DB::table('devices')->updateOrInsert(
                ['user_id' => $user->id, 'device_id' => $deviceId],
                [
                    'platform' => $data['platform'] ?? 'android',
                    'app_version' => $data['appVersion'] ?? null,
                    'last_seen_at' => now(),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
            $device = DB::table('devices')->where(['user_id' => $user->id, 'device_id' => $deviceId])->first();
            if ($enabled) {
                if ($device?->id) DB::table('push_tokens')->where('device_id', $device->id)->where('token', '!=', $token)->delete();
                DB::table('push_tokens')->updateOrInsert(
                    ['token' => $token],
                    [
                        'user_id' => $user->id,
                        'device_id' => $device?->id,
                        'provider' => 'fcm',
                        'last_used_at' => now(),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            } else {
                if ($token !== '') DB::table('push_tokens')->where('token', $token)->delete();
                if ($device?->id) DB::table('push_tokens')->where('device_id', $device->id)->delete();
            }
        }

        Log::info('PUSH_REGISTER', [
            'user_id' => $user->id,
            'firebase_uid' => $uid,
            'device_id' => $deviceId,
            'enabled' => $enabled,
            'has_token' => $token !== '',
        ]);

        return $this->success(['registered' => true, 'pushEnabled' => $enabled, 'deviceId' => $deviceId, 'uid' => $uid]);
    }

    public function send(Request $request, FcmPushService $fcm)
    {
        $data = $request->validate([
            'targetUid' => 'required|string|max:191',
            'type' => 'required|string|max:64',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'sourceId' => 'nullable|string|max:191',
            'id' => 'nullable|string|max:191',
            'data' => 'nullable|array',
        ]);

        $actor = $request->user();
        $target = $this->resolveTargetUser($data['targetUid']);
        if (! $target) return $this->failure('PUSH_TARGET_NOT_FOUND', 'Recipient was not found.', 404);
        if ((int) $target->id === (int) $actor->id) return $this->success(['delivered' => 0, 'skipped' => true, 'reason' => 'SELF_TARGET']);

        $type = strtolower(trim((string) $data['type']));
        $title = trim((string) ($data['title'] ?? 'Cèlè One')) ?: 'Cèlè One';
        $body = trim((string) ($data['description'] ?? ''));
        $notificationId = preg_replace('/[^a-zA-Z0-9._:-]/', '_', trim((string) ($data['id'] ?? '')))
            ?: $type . '_' . $target->id . '_' . Str::uuid();
        $payload = array_merge((array) ($data['data'] ?? []), [
            'id' => $notificationId,
            'notificationId' => $notificationId,
            'type' => $type,
            'notificationType' => $type,
            'targetUid' => (string) $data['targetUid'],
            'actorUid' => $this->primaryUid($actor),
            'sourceId' => (string) ($data['sourceId'] ?? ''),
        ]);

        $this->persistInbox($notificationId, $target, $title, $body, $type, $data['sourceId'] ?? null, $payload, 'pending');
        $tokens = $this->tokensForUser($target, (string) $data['targetUid']);

        if (empty($tokens)) {
            $this->persistInbox($notificationId, $target, $title, $body, $type, $data['sourceId'] ?? null, $payload, 'no_device');
            Log::warning('PUSH_NO_DEVICE', ['target_user_id' => $target->id, 'target_uid' => $data['targetUid'], 'type' => $type]);
            return $this->success(['queued' => true, 'delivered' => 0, 'id' => $notificationId, 'reason' => 'NO_REGISTERED_DEVICE']);
        }

        $delivered = 0; $failed = 0; $errors = [];
        $collapseKey = $this->collapseKey($type, $payload, $notificationId);
        foreach ($tokens as $token) {
            try {
                $result = $fcm->send($token, $title, $body, $payload, 'high', $collapseKey);
                if ($result['ok'] ?? false) {
                    $delivered++;
                    if (Schema::hasTable('push_tokens')) DB::table('push_tokens')->where('token', $token)->update(['last_used_at' => now(), 'updated_at' => now()]);
                    if ($this->usesNodePushSchema()) DB::table('push_devices')->where('token', $token)->update(['last_seen_at_ms' => (int) floor(microtime(true) * 1000), 'updated_at_ms' => (int) floor(microtime(true) * 1000)]);
                } else {
                    $failed++;
                    $errors[] = ['status' => $result['status'] ?? 0, 'code' => $result['provider_code'] ?? 'FCM_ERROR'];
                    if ($result['invalid_token'] ?? false) $this->deleteToken($token);
                }
            } catch (Throwable $e) {
                $failed++;
                $errors[] = ['code' => $e->getMessage()];
                Log::error('PUSH_FCM_SEND_FAILED', ['target_user_id' => $target->id, 'type' => $type, 'error' => $e->getMessage()]);
            }
        }

        $this->persistInbox($notificationId, $target, $title, $body, $type, $data['sourceId'] ?? null, array_merge($payload, ['push_errors' => $errors]), $delivered > 0 ? 'sent' : 'failed');
        Log::info('PUSH_SEND', [
            'actor_user_id' => $actor->id,
            'target_user_id' => $target->id,
            'target_uid' => $data['targetUid'],
            'type' => $type,
            'delivered' => $delivered,
            'failed' => $failed,
            'notification_id' => $notificationId,
        ]);

        return $this->success(['queued' => true, 'delivered' => $delivered, 'failed' => $failed, 'id' => $notificationId, 'errors' => $errors]);
    }

    public function inbox(Request $request)
    {
        $limit = max(1, min((int) $request->query('limit', 100), 200));
        if ($this->usesNodeInboxSchema()) {
            $uids = $this->uidAliases($request->user());
            $query = DB::table('notification_inbox')->whereIn('uid', $uids);
            if ($request->filled('beforeMs')) $query->where('created_at_ms', '<', (int) $request->query('beforeMs'));
            $items = $query->orderByDesc('created_at_ms')->limit($limit)->get()->map(fn ($row) => [
                'id' => $row->id,
                'type' => $row->type,
                'title' => $row->title,
                'description' => $row->description,
                'data' => is_string($row->data) ? (json_decode($row->data, true) ?: []) : (array) $row->data,
                'read' => (bool) $row->read,
                'createdAtMs' => (int) $row->created_at_ms,
            ])->values();
            return $this->success(['items' => $items]);
        }

        $query = DB::table('notification_inbox')->where('user_id', $request->user()->id);
        if ($request->filled('beforeMs')) {
            $before = (int) $request->query('beforeMs');
            $query->where('created_at', '<', \Carbon\Carbon::createFromTimestampMs($before));
        }
        $items = $query->orderByDesc('created_at')->limit($limit)->get()->map(function ($row) {
            $payload = is_string($row->data ?? null) ? (json_decode($row->data, true) ?: []) : (array) ($row->data ?? []);
            return [
                'id' => $row->public_id,
                'type' => $row->parent_type ?: ($payload['type'] ?? 'system'),
                'title' => $row->title,
                'description' => $row->body,
                'data' => $payload,
                'read' => $row->status === 'read',
                'createdAtMs' => $row->created_at ? \Carbon\Carbon::parse($row->created_at)->getTimestampMs() : 0,
            ];
        })->values();
        return $this->success(['items' => $items]);
    }

    public function read(Request $request)
    {
        $ids = $request->validate(['ids' => 'required|array|max:200', 'ids.*' => 'string|max:191'])['ids'];
        if ($this->usesNodeInboxSchema()) {
            $count = DB::table('notification_inbox')->whereIn('uid', $this->uidAliases($request->user()))->whereIn('id', $ids)->update(['read' => true, 'read_at_ms' => (int) floor(microtime(true) * 1000), 'updated_at_ms' => (int) floor(microtime(true) * 1000)]);
        } else {
            $count = DB::table('notification_inbox')->where('user_id', $request->user()->id)->whereIn('public_id', $ids)->update(['status' => 'read', 'updated_at' => now()]);
        }
        return $this->success(['count' => $count]);
    }

    public function readAll(Request $request)
    {
        if ($this->usesNodeInboxSchema()) {
            $count = DB::table('notification_inbox')->whereIn('uid', $this->uidAliases($request->user()))->where('read', false)->update(['read' => true, 'read_at_ms' => (int) floor(microtime(true) * 1000), 'updated_at_ms' => (int) floor(microtime(true) * 1000)]);
        } else {
            $count = DB::table('notification_inbox')->where('user_id', $request->user()->id)->where('status', '!=', 'read')->update(['status' => 'read', 'updated_at' => now()]);
        }
        return $this->success(['count' => $count]);
    }

    public function delete(Request $request)
    {
        $ids = $request->validate(['ids' => 'required|array|max:200', 'ids.*' => 'string|max:191'])['ids'];
        $count = $this->usesNodeInboxSchema()
            ? DB::table('notification_inbox')->whereIn('uid', $this->uidAliases($request->user()))->whereIn('id', $ids)->delete()
            : DB::table('notification_inbox')->where('user_id', $request->user()->id)->whereIn('public_id', $ids)->delete();
        return $this->success(['count' => $count]);
    }

    public function deleteAll(Request $request)
    {
        $count = $this->usesNodeInboxSchema()
            ? DB::table('notification_inbox')->whereIn('uid', $this->uidAliases($request->user()))->delete()
            : DB::table('notification_inbox')->where('user_id', $request->user()->id)->delete();
        return $this->success(['count' => $count]);
    }

    private function resolveTargetUser(string $uid): ?User
    {
        $uid = trim($uid);
        $query = User::query()->where('firebase_uid', $uid)->orWhere('legacy_firebase_uid', $uid);
        if (ctype_digit($uid)) $query->orWhere('id', (int) $uid);
        if (preg_match('/^laravel_(\d+)$/', $uid, $match)) $query->orWhere('id', (int) $match[1]);
        return $query->first();
    }

    private function primaryUid(User $user): string
    {
        return trim((string) ($user->firebase_uid ?: $user->legacy_firebase_uid ?: ('laravel_' . $user->id)));
    }

    private function uidAliases(User $user, ?string $requestedUid = null): array
    {
        return array_values(array_unique(array_filter([
            trim((string) $requestedUid),
            trim((string) $user->firebase_uid),
            trim((string) $user->legacy_firebase_uid),
            'laravel_' . $user->id,
            (string) $user->id,
        ])));
    }

    private function tokensForUser(User $user, string $requestedUid): array
    {
        $tokens = [];
        if ($this->usesNodePushSchema()) {
            $tokens = array_merge($tokens, DB::table('push_devices')
                ->whereIn('uid', $this->uidAliases($user, $requestedUid))
                ->where('push_enabled', true)
                ->where('invalid_token', false)
                ->whereNotNull('token')
                ->pluck('token')->all());
        }
        if (Schema::hasTable('push_tokens')) {
            $tokens = array_merge($tokens, DB::table('push_tokens')->where('user_id', $user->id)->where('provider', 'fcm')->whereNotNull('token')->pluck('token')->all());
        }
        return array_values(array_unique(array_filter(array_map(fn ($token) => trim((string) $token), $tokens))));
    }

    private function deleteToken(string $token): void
    {
        if ($this->usesNodePushSchema()) DB::table('push_devices')->where('token', $token)->update(['push_enabled' => false, 'invalid_token' => true, 'updated_at_ms' => (int) floor(microtime(true) * 1000)]);
        if (Schema::hasTable('push_tokens')) DB::table('push_tokens')->where('token', $token)->delete();
    }

    private function persistInbox(string $id, User $user, string $title, string $body, string $type, ?string $sourceId, array $data, string $status): void
    {
        if (! Schema::hasTable('notification_inbox')) return;
        if ($this->usesNodeInboxSchema()) {
            $now = (int) floor(microtime(true) * 1000);
            DB::table('notification_inbox')->updateOrInsert(
                ['id' => $id],
                [
                    'uid' => $this->primaryUid($user),
                    'type' => $type,
                    'title' => $title,
                    'description' => $body,
                    'data' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'read' => false,
                    'source' => 'laravel',
                    'created_at_ms' => $now,
                    'updated_at_ms' => $now,
                    'delivered_push_at_ms' => $status === 'sent' ? $now : null,
                ],
            );
            return;
        }
        DB::table('notification_inbox')->updateOrInsert(
            ['public_id' => $id],
            [
                'user_id' => $user->id,
                'parent_type' => $type,
                'parent_id' => $sourceId,
                'status' => $status,
                'title' => $title,
                'body' => $body,
                'data' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    private function usesNodePushSchema(): bool
    {
        return Schema::hasTable('push_devices') && Schema::hasColumn('push_devices', 'uid') && Schema::hasColumn('push_devices', 'push_enabled') && Schema::hasColumn('push_devices', 'token');
    }

    private function usesNodeInboxSchema(): bool
    {
        return Schema::hasTable('notification_inbox') && Schema::hasColumn('notification_inbox', 'uid') && Schema::hasColumn('notification_inbox', 'created_at_ms') && Schema::hasColumn('notification_inbox', 'read');
    }

    private function collapseKey(string $type, array $payload, string $fallback): ?string
    {
        if (str_contains($type, 'call')) return 'call_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) ($payload['callId'] ?? $fallback));
        if (in_array($type, ['chat', 'message', 'new_message', 'dm_message', 'group_message'], true)) return null;
        return substr(preg_replace('/[^a-zA-Z0-9_-]/', '_', $fallback), 0, 64);
    }
}
