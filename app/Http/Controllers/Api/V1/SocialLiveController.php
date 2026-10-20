<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Services\SocialNotification as N;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SocialLiveController extends Controller
{
    use ApiResponses;

    public function provision(Request $request)
    {
        $d = $request->validate([
            'hostType' => 'required|in:user,page',
            'hostId' => 'required|string|max:191',
            'title' => 'nullable|string|max:160',
            'description' => 'nullable|string|max:1000',
        ]);

        $user = $request->user();

        abort_if($user->firebase_disabled, 403);

        $keys = N::keys($user);

        $uid = (string) (
            $user->firebase_uid
            ?: $user->legacy_firebase_uid
            ?: $user->id
        );

        if ($d['hostType'] === 'page') {
            $host = N::payload(
                DB::table('firebase_documents')
                    ->where([
                        'collection_path' => 'pages',
                        'document_id' => $d['hostId'],
                    ])
                    ->first()
            );

            $allowed = array_intersect(
                $keys,
                array_filter([
                    $host['ownerId'] ?? null,
                    $host['userId'] ?? null,
                    $host['createdBy'] ?? null,
                ])
            );

            foreach ($keys as $key) {
                if (
                    in_array(
                        $host['roles'][$key] ?? '',
                        ['owner', 'admin', 'editor'],
                        true
                    )
                ) {
                    $allowed[] = $key;
                }
            }

            abort_unless(
                $host && $allowed,
                403,
                'NOT_PAGE_MANAGER'
            );

            $created =
                $host['createdAtMs']
                ?? $host['createdAt']
                ?? null;
        } else {
            abort_unless(
                in_array($d['hostId'], $keys, true),
                403,
                'HOST_MISMATCH'
            );

            $host = [
                'name' => $user->name,
                'photoURL' => $user->avatar_url,
            ];

            $created =
                $user->firebase_created_at
                ?: $user->created_at;
        }

        if (is_array($created)) {
            $created =
                ($created['seconds']
                ?? $created['_seconds']
                ?? 0) * 1000;
        }

        $ms = is_numeric($created)
            ? (float) $created
            : (strtotime((string) $created) ?: 0) * 1000;

        abort_unless(
            $ms > 0 &&
            now()->getTimestampMs() - $ms >= 30 * 86400000,
            403,
            'accountTooNew'
        );

        $id = (string) Str::ulid();

        $name = 'social_' . $id;

        $secret = Str::random(48);

        $playback =
            'https://live.celeonetv.com/hls/'
            . $name
            . '.m3u8';

        $public = [
            'id' => $id,
            'sessionId' => $id,

            'hostId' => $d['hostId'],
            'hostType' => $d['hostType'],
            'hostOwnerId' => $uid,

            'hostName' =>
                $host['name']
                ?? $host['displayName']
                ?? $user->name,

            'hostAvatar' =>
                $host['logoUrl']
                ?? $host['photoURL']
                ?? $host['profileImage']
                ?? '',

            'title' =>
                $d['title']
                ?? $user->name,

            'description' =>
                $d['description']
                ?? '',

            'playbackUrl' => $playback,

            'status' => 'starting',

            'createdAtMs' => now()->getTimestampMs(),
            'startedAtMs' => 0,

            'likes' => 0,
            'viewerCount' => 0,
            'commentCount' => 0,

            'hostPaused' => false,
        ];

        DB::transaction(function () use (
            $id,
            $name,
            $secret,
            $user,
            $public
        ) {
            DB::table('social_live_credentials')->insert([
                'session_id' => $id,
                'user_id' => $user->id,
                'stream_name' => $name,
                'secret_hash' => hash('sha256', $secret),
                'expires_at' => now()->addHours(8),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('firebase_documents')->insert([
                'collection_path' => 'social_live_sessions',
                'document_id' => $id,
                'parent_document_path' => null,
                'payload' => json_encode($public),
                'firebase_updated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return $this->success([
            'sessionId' => $id,

            // Nginx/RTMP will separate ?token= into its args field.
            'streamKey' =>
                $name
                . '?token='
                . $secret,

            'rtmpUrl' =>
                'rtmp://stream.celeonetv.com/live',

            'playbackUrl' => $playback,
        ]);
    }

    /**
     * PUBLIC LIVE DISCOVERY
     *
     * This is now the authoritative source for the Social live rail.
     *
     * A live is visible only when:
     * - its public session status is "live"
     * - its private stream credential still exists
     * - the credential is not expired
     */
    public function active(Request $request)
    {
        $rows = DB::table('firebase_documents as f')
            ->join(
                'social_live_credentials as c',
                'c.session_id',
                '=',
                'f.document_id'
            )
            ->where(
                'f.collection_path',
                'social_live_sessions'
            )
            ->whereRaw(
                "COALESCE(f.payload->>'status', '') = ?",
                ['live']
            )
            ->where(
                'c.expires_at',
                '>',
                now()
            )
            ->orderByDesc('f.updated_at')
            ->limit(50)
            ->get([
                'f.document_id',
                'f.payload',
                'f.updated_at',
            ]);

        $items = $rows
            ->map(function ($row) {
                $data = N::payload($row);

                $id = (string) (
                    $data['id']
                    ?? $data['sessionId']
                    ?? $row->document_id
                );

                if ($id === '') {
                    return null;
                }

                return array_merge(
                    $data,
                    [
                        'id' => $id,
                        'sessionId' => (string) (
                            $data['sessionId']
                            ?? $id
                        ),
                        'status' => 'live',
                        'viewerCount' => max(
                            0,
                            (int) ($data['viewerCount'] ?? 0)
                        ),
                        'likes' => max(
                            0,
                            (int) ($data['likes'] ?? 0)
                        ),
                        'commentCount' => max(
                            0,
                            (int) ($data['commentCount'] ?? 0)
                        ),
                    ]
                );
            })
            ->filter(function ($item) {
                return
                    is_array($item) &&
                    !empty($item['id']) &&
                    !empty($item['playbackUrl']);
            })
            ->sort(function ($a, $b) {
                $viewerCompare =
                    (int) ($b['viewerCount'] ?? 0)
                    <=>
                    (int) ($a['viewerCount'] ?? 0);

                if ($viewerCompare !== 0) {
                    return $viewerCompare;
                }

                return
                    (int) ($b['startedAtMs'] ?? 0)
                    <=>
                    (int) ($a['startedAtMs'] ?? 0);
            })
            ->values();

        return $this
            ->success([
                'items' => $items,
                'count' => $items->count(),

                // Mobile can use this value later if required.
                'refreshAfterMs' => 5000,

                'serverTimeMs' =>
                    now()->getTimestampMs(),
            ])
            ->header(
                'Cache-Control',
                'no-store, no-cache, must-revalidate, max-age=0'
            )
            ->header(
                'Pragma',
                'no-cache'
            );
    }

    public function stop(Request $request)
    {
        $d = $request->validate([
            'sessionId' =>
                'required|string|max:191',
        ]);

        $credential =
            DB::table('social_live_credentials')
                ->where(
                    'session_id',
                    $d['sessionId']
                )
                ->first();

        if (!$credential) {
            return $this->success([
                'ok' => true,
            ]);
        }

        abort_unless(
            (int) $credential->user_id
            ===
            (int) $request->user()->id,
            403
        );

        $this->end(
            $credential->session_id
        );

        return $this->success([
            'ok' => true,
        ]);
    }

    public function callback(
        Request $request,
        string $action
    ) {
        $path = (string) (
            config(
                'services.social_live.internal_key_path'
            )
            ?: '/etc/celeone/notification-internal-key'
        );

        $expected =
            is_readable($path)
                ? trim(
                    file_get_contents($path)
                )
                : '';

        $provided = (string) $request->header(
            'X-Celeone-Internal',
            ''
        );

        if (
            $expected === '' ||
            $provided === '' ||
            !hash_equals(
                $expected,
                $provided
            )
        ) {
            logger()->warning(
                'SOCIAL_LIVE_CALLBACK_REJECT',
                [
                    'reason' => 'internal_key',
                    'action' => $action,
                    'expected_present' =>
                        $expected !== '',
                    'provided_present' =>
                        $provided !== '',
                ]
            );

            abort(403);
        }

        $app = (string) $request->input(
            'app',
            'live'
        );

        $name = trim(
            (string) $request->input(
                'name',
                ''
            )
        );

        $args = (string) $request->input(
            'args',
            ''
        );

        $secret = trim(
            (string) $request->input(
                'token',
                ''
            )
        );

        if ($app !== 'live') {
            logger()->warning(
                'SOCIAL_LIVE_CALLBACK_REJECT',
                [
                    'reason' => 'app',
                    'action' => $action,
                    'app' => $app,
                ]
            );

            abort(403);
        }

        /*
         * Support both forms:
         *
         * name=social_xxx
         * args=token=xxx
         *
         * and:
         *
         * name=social_xxx?token=xxx
         */
        if (str_contains($name, '?')) {
            [
                $streamName,
                $queryString
            ] = array_pad(
                explode(
                    '?',
                    $name,
                    2
                ),
                2,
                ''
            );

            $name = trim($streamName);

            if ($secret === '') {
                parse_str(
                    $queryString,
                    $query
                );

                $secret = trim(
                    (string) (
                        $query['token']
                        ?? ''
                    )
                );
            }
        }

        if (
            $secret === '' &&
            $args !== ''
        ) {
            parse_str(
                ltrim($args, '?'),
                $query
            );

            $secret = trim(
                (string) (
                    $query['token']
                    ?? ''
                )
            );
        }

        if ($name === '') {
            logger()->warning(
                'SOCIAL_LIVE_CALLBACK_REJECT',
                [
                    'reason' =>
                        'stream_name_empty',
                    'action' => $action,
                ]
            );

            abort(403);
        }

        $credential =
            DB::table(
                'social_live_credentials'
            )
                ->where(
                    'stream_name',
                    $name
                )
                ->where(
                    'expires_at',
                    '>',
                    now()
                )
                ->first();

        if (!$credential) {
            /*
             * Keep old Cèlè One TV channel
             * stream keys working.
             */
            $channel =
                DB::table(
                    'firebase_documents'
                )
                    ->where(
                        'collection_path',
                        'channels'
                    )
                    ->where(
                        'payload->streamKey',
                        $name
                    )
                    ->exists();

            if (
                $channel ||
                $action === 'done'
            ) {
                return $this->success([
                    'ok' => true,
                ]);
            }

            logger()->warning(
                'SOCIAL_LIVE_CALLBACK_REJECT',
                [
                    'reason' =>
                        'credential_not_found',
                    'action' => $action,
                    'stream_name' => $name,
                    'args_present' =>
                        $args !== '',
                    'token_present' =>
                        $secret !== '',
                ]
            );

            abort(403);
        }

        if ($secret === '') {
            logger()->warning(
                'SOCIAL_LIVE_CALLBACK_REJECT',
                [
                    'reason' =>
                        'token_missing',
                    'action' => $action,
                    'stream_name' => $name,
                    'args_present' =>
                        $args !== '',
                ]
            );

            abort(403);
        }

        if (
            !hash_equals(
                (string) $credential->secret_hash,
                hash(
                    'sha256',
                    $secret
                )
            )
        ) {
            logger()->warning(
                'SOCIAL_LIVE_CALLBACK_REJECT',
                [
                    'reason' =>
                        'token_hash_mismatch',
                    'action' => $action,
                    'stream_name' => $name,
                    'token_length' =>
                        strlen($secret),
                ]
            );

            abort(403);
        }

        if ($action === 'done') {
            $this->end(
                (string) $credential->session_id
            );

            logger()->info(
                'SOCIAL_LIVE_CALLBACK_DONE',
                [
                    'stream_name' => $name,
                    'session_id' =>
                        $credential->session_id,
                ]
            );

            return $this->success([
                'ok' => true,
            ]);
        }

        $row =
            DB::table(
                'firebase_documents'
            )
                ->where([
                    'collection_path' =>
                        'social_live_sessions',

                    'document_id' =>
                        $credential->session_id,
                ])
                ->first();

        if (!$row) {
            logger()->warning(
                'SOCIAL_LIVE_CALLBACK_REJECT',
                [
                    'reason' =>
                        'session_missing',
                    'stream_name' => $name,
                ]
            );

            abort(403);
        }

        $data = N::payload($row);

        if (
            !in_array(
                (string) (
                    $data['status']
                    ?? ''
                ),
                [
                    'starting',
                    'live',
                ],
                true
            )
        ) {
            logger()->warning(
                'SOCIAL_LIVE_CALLBACK_REJECT',
                [
                    'reason' =>
                        'session_state',
                    'stream_name' =>
                        $name,
                    'status' =>
                        $data['status']
                        ?? null,
                ]
            );

            abort(403);
        }

        $data['status'] = 'live';

        if (
            empty(
                $data['startedAtMs']
            )
        ) {
            $data['startedAtMs'] =
                now()->getTimestampMs();
        }

        $data['updatedAtMs'] =
            now()->getTimestampMs();

        DB::table(
            'firebase_documents'
        )
            ->where(
                'id',
                $row->id
            )
            ->update([
                'payload' =>
                    json_encode($data),

                'firebase_updated_at' =>
                    now(),

                'updated_at' =>
                    now(),
            ]);

        logger()->info(
            'SOCIAL_LIVE_CALLBACK_ACCEPTED',
            [
                'stream_name' =>
                    $name,

                'session_id' =>
                    $credential->session_id,
            ]
        );

        return $this->success([
            'ok' => true,
        ]);
    }

    private function end(
        string $id
    ): void {
        DB::transaction(
            function () use ($id) {
                $row =
                    DB::table(
                        'firebase_documents'
                    )
                        ->where([
                            'collection_path' =>
                                'social_live_sessions',

                            'document_id' =>
                                $id,
                        ])
                        ->first();

                if ($row) {
                    $data =
                        N::payload($row);

                    $data['status'] =
                        'ended';

                    $data['endedAtMs'] =
                        now()->getTimestampMs();

                    $data['updatedAtMs'] =
                        now()->getTimestampMs();

                    DB::table(
                        'firebase_documents'
                    )
                        ->where(
                            'id',
                            $row->id
                        )
                        ->update([
                            'payload' =>
                                json_encode($data),

                            'firebase_updated_at' =>
                                now(),

                            'updated_at' =>
                                now(),
                        ]);
                }

                DB::table(
                    'social_live_credentials'
                )
                    ->where(
                        'session_id',
                        $id
                    )
                    ->delete();

                /*
                 * Legacy/migrated table:
                 * keep it consistent if a row exists.
                 */
                DB::table(
                    'social_live_sessions'
                )
                    ->where(
                        'public_id',
                        $id
                    )
                    ->update([
                        'status' =>
                            'ended',

                        'updated_at' =>
                            now(),
                    ]);
            }
        );
    }
}
