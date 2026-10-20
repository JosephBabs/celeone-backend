<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\FirebaseTokenVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class FirebaseExchangeController extends Controller
{
    /**
     * Exchange an existing Firebase authenticated session for
     * a Laravel Sanctum session.
     *
     * IMPORTANT:
     * This endpoint intentionally remains compatible with old
     * Cèlè One mobile builds.
     *
     * Old application flow:
     *
     * Google
     *   -> Firebase signInWithCredential()
     *   -> Firebase user.getIdToken()
     *   -> POST /api/v1/auth/firebase/exchange
     *   -> Laravel Sanctum token
     *
     * No mobile rebuild is required for this flow.
     */
    public function __invoke(
        Request $request,
        FirebaseTokenVerifier $verifier
    ): JsonResponse {
        /*
         * DO NOT use $request->validate() here.
         *
         * Some already-released mobile builds process Laravel's
         * standard validation "errors" object with Array.flat().
         * On some Hermes builds that results in:
         *
         * "undefined is not a function"
         *
         * We therefore return our own stable error format.
         */

        $idToken = trim((string) (
            $request->input('id_token')
            ?? $request->input('idToken')
            ?? $request->input('firebase_token')
            ?? $request->input('firebaseToken')
            ?? $request->input('token')
            ?? ''
        ));

        $deviceName = trim((string) (
            $request->input('device_name')
            ?? $request->input('deviceName')
            ?? $request->header('X-Device-ID')
            ?? 'migrated-mobile'
        ));

        if ($deviceName === '') {
            $deviceName = 'migrated-mobile';
        }

        Log::info('FIREBASE_EXCHANGE_REQUEST', [
            'has_token' => $idToken !== '',
            'device_name' => $deviceName,
            'user_agent' => $request->userAgent(),
            'request_id' => $request->attributes->get('request_id'),
        ]);

        if ($idToken === '') {
            return $this->failureCompat(
                'FIREBASE_TOKEN_REQUIRED',
                'Firebase ID token is required.',
                400
            );
        }

        /*
         * Verify the Firebase ID token.
         */
        try {
            $identity = $verifier->verify($idToken);
        } catch (\Throwable $exception) {
            Log::warning('FIREBASE_EXCHANGE_VERIFY_FAILED', [
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
                'request_id' => $request->attributes->get('request_id'),
            ]);

            return $this->failureCompat(
                'UNAUTHENTICATED',
                'Firebase identity could not be verified.',
                401
            );
        }

        $uid = trim((string) ($identity['localId'] ?? ''));

        if ($uid === '') {
            Log::warning('FIREBASE_EXCHANGE_UID_MISSING', [
                'request_id' => $request->attributes->get('request_id'),
            ]);

            return $this->failureCompat(
                'UNAUTHENTICATED',
                'Firebase UID is missing.',
                401
            );
        }

        $email = strtolower(
            trim((string) ($identity['email'] ?? ''))
        );

        $emailVerified = filter_var(
            $identity['emailVerified'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );

        $provider = strtolower(
            trim((string) (
                $identity['verifiedSignInProvider']
                ?? ''
            ))
        );

        Log::info('FIREBASE_EXCHANGE_IDENTITY_VERIFIED', [
            'firebase_uid' => $uid,
            'email' => $email,
            'email_verified' => $emailVerified,
            'provider' => $provider,
            'request_id' => $request->attributes->get('request_id'),
        ]);

        try {
            $user = DB::transaction(
                function () use (
                    $uid,
                    $identity,
                    $email,
                    $emailVerified,
                    $provider
                ): User {
                    /*
                     * First preference:
                     * account already linked by Firebase UID.
                     */
                    $user = User::query()
                        ->where('legacy_firebase_uid', $uid)
                        ->orWhere('firebase_uid', $uid)
                        ->lockForUpdate()
                        ->first();

                    /*
                     * Second preference:
                     * verified Firebase e-mail.
                     *
                     * This permits old Firebase users to migrate into
                     * an already-created Laravel account.
                     */
                    if (!$user && $email !== '') {
                        $candidate = User::query()
                            ->whereRaw(
                                'LOWER(email) = ?',
                                [$email]
                            )
                            ->lockForUpdate()
                            ->first();

                        if ($candidate) {
                            $candidateFirebaseUid = trim(
                                (string) (
                                    $candidate->firebase_uid
                                    ?? ''
                                )
                            );

                            $candidateLegacyUid = trim(
                                (string) (
                                    $candidate->legacy_firebase_uid
                                    ?? ''
                                )
                            );

                            /*
                             * Never silently attach an unverified
                             * e-mail to an existing account.
                             */
                            if (!$emailVerified) {
                                abort(
                                    403,
                                    'The Firebase email must be verified before linking this account.'
                                );
                            }

                            /*
                             * If another Firebase identity is already
                             * attached, do not overwrite it.
                             */
                            if (
                                $candidateFirebaseUid !== ''
                                && $candidateFirebaseUid !== $uid
                            ) {
                                abort(
                                    409,
                                    'This email is already linked to another Firebase account.'
                                );
                            }

                            if (
                                $candidateLegacyUid !== ''
                                && $candidateLegacyUid !== $uid
                            ) {
                                abort(
                                    409,
                                    'This email is already linked to another Firebase account.'
                                );
                            }

                            $user = $candidate;
                        }
                    }

                    /*
                     * Brand-new Firebase user.
                     */
                    if (!$user) {
                        $displayName = trim(
                            (string) (
                                $identity['displayName']
                                ?? ''
                            )
                        );

                        if ($displayName === '') {
                            $displayName = $email !== ''
                                ? Str::before($email, '@')
                                : 'Cèlè One user';
                        }

                        $user = new User();

                        $user->name = $displayName;

                        $user->email = $email !== ''
                            ? $email
                            : $uid . '@migrated.celeone.local';

                        /*
                         * User model has a hashed password cast.
                         * This random password is never used for
                         * Firebase authentication.
                         */
                        $user->password = Str::random(64);
                    }

                    /*
                     * Disabled Cèlè One account.
                     */
                    if (
                        Schema::hasColumn(
                            'users',
                            'firebase_disabled'
                        )
                        && (bool) $user->firebase_disabled
                    ) {
                        abort(
                            403,
                            'This account is disabled.'
                        );
                    }

                    /*
                     * Disabled Firebase identity.
                     */
                    if (
                        filter_var(
                            $identity['disabled'] ?? false,
                            FILTER_VALIDATE_BOOLEAN
                        )
                    ) {
                        abort(
                            403,
                            'This account is disabled.'
                        );
                    }

                    /*
                     * Once a Laravel password has been migrated,
                     * old Firebase PASSWORD login is not permitted
                     * to bypass it.
                     *
                     * IMPORTANT:
                     * Google Firebase authentication remains allowed
                     * so already-published versions of Cèlè One can
                     * continue signing in with Google.
                     */
                    if (
                        Schema::hasColumn(
                            'users',
                            'password_migrated_at'
                        )
                        && $user->password_migrated_at
                        && $provider !== 'google.com'
                    ) {
                        abort(
                            403,
                            'Please sign in with your current CeleOne password or Google.'
                        );
                    }

                    $role = $this->resolvedRole(
                        $user,
                        $uid,
                        $identity
                    );

                    $updates = [
                        'firebase_uid' => $uid,
                        'legacy_firebase_uid' => $uid,
                        'role' => $role,
                    ];

                    if (
                        Schema::hasColumn(
                            'users',
                            'avatar_url'
                        )
                    ) {
                        $photoUrl = trim(
                            (string) (
                                $identity['photoUrl']
                                ?? ''
                            )
                        );

                        if ($photoUrl !== '') {
                            $updates['avatar_url'] = $photoUrl;
                        }
                    }

                    if (
                        Schema::hasColumn(
                            'users',
                            'email_verified_at'
                        )
                        && $emailVerified
                        && !$user->email_verified_at
                    ) {
                        $updates['email_verified_at'] = now();
                    }

                    $user->forceFill($updates);

                    $user->save();

                    /*
                     * Keep imported user_data role in sync.
                     */
                    if (Schema::hasTable('user_data')) {
                        DB::table('user_data')
                            ->where(
                                function ($query) use (
                                    $user,
                                    $uid
                                ): void {
                                    $query
                                        ->where(
                                            'user_id',
                                            $user->id
                                        )
                                        ->orWhere(
                                            'firebase_uid',
                                            $uid
                                        );
                                }
                            )
                            ->update([
                                'role' => $role,
                                'updated_at' => now(),
                            ]);
                    }

                    return $user->fresh();
                }
            );
        } catch (
            \Symfony\Component\HttpKernel\Exception\HttpException $exception
        ) {
            Log::warning('FIREBASE_EXCHANGE_ACCOUNT_REJECTED', [
                'status' => $exception->getStatusCode(),
                'message' => $exception->getMessage(),
                'firebase_uid' => $uid,
                'email' => $email,
                'provider' => $provider,
                'request_id' => $request->attributes->get('request_id'),
            ]);

            return $this->failureCompat(
                'ACCOUNT_REJECTED',
                $exception->getMessage()
                    ?: 'The account could not be authenticated.',
                $exception->getStatusCode()
            );
        } catch (\Throwable $exception) {
            Log::error('FIREBASE_EXCHANGE_ACCOUNT_ERROR', [
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'firebase_uid' => $uid,
                'email' => $email,
                'request_id' => $request->attributes->get('request_id'),
            ]);

            return $this->failureCompat(
                'FIREBASE_EXCHANGE_FAILED',
                'The Firebase session could not be exchanged.',
                500
            );
        }

        /*
         * Issue the Laravel Sanctum token.
         */
        try {
            $accessToken = $user
                ->createToken($deviceName)
                ->plainTextToken;
        } catch (\Throwable $exception) {
            Log::error('FIREBASE_EXCHANGE_SANCTUM_FAILED', [
                'user_id' => $user->id,
                'message' => $exception->getMessage(),
                'request_id' => $request->attributes->get('request_id'),
            ]);

            return $this->failureCompat(
                'TOKEN_CREATION_FAILED',
                'The Laravel session could not be created.',
                500
            );
        }

        $passwordMigrated =
            Schema::hasColumn(
                'users',
                'password_migrated_at'
            )
            && !empty($user->password_migrated_at);

        Log::info('FIREBASE_EXCHANGE_SUCCESS', [
            'user_id' => $user->id,
            'firebase_uid' => $uid,
            'email' => $user->email,
            'provider' => $provider,
            'password_migrated' => $passwordMigrated,
            'request_id' => $request->attributes->get('request_id'),
        ]);

        /*
         * COMPATIBILITY RESPONSE.
         *
         * Existing versions of the mobile app use:
         *
         * response.data.access_token
         *
         * Some versions use:
         *
         * response.access_token
         * response.token
         *
         * Return all three layouts until older builds are retired.
         */
        $session = [
            'user' => $user,
            'password_migrated' => $passwordMigrated,
            'access_token' => $accessToken,
            'token' => $accessToken,
            'token_type' => 'Bearer',
        ];

        return response()->json([
            'ok' => true,

            /*
             * Old/direct response compatibility.
             */
            'user' => $user,
            'password_migrated' => $passwordMigrated,
            'access_token' => $accessToken,
            'token' => $accessToken,
            'token_type' => 'Bearer',

            /*
             * Current API envelope.
             */
            'data' => $session,

            'meta' => [
                'request_id' => $request
                    ->attributes
                    ->get('request_id'),

                'server_time' => now()->toISOString(),
            ],
        ]);
    }

    /**
     * Resolve the role without unintentionally downgrading an
     * imported administrator.
     */
    private function resolvedRole(
        User $user,
        string $uid,
        array $identity
    ): string {
        $candidates = [];

        /*
         * Existing Laravel role has first priority.
         */
        if (!empty($user->role)) {
            $candidates[] = strtolower(
                trim((string) $user->role)
            );
        }

        /*
         * Firebase custom claims.
         */
        if (!empty($identity['customAttributes'])) {
            $claims = is_array(
                $identity['customAttributes']
            )
                ? $identity['customAttributes']
                : (
                    json_decode(
                        (string) $identity['customAttributes'],
                        true
                    ) ?: []
                );

            if (
                ($claims['isSuperAdmin'] ?? false) === true
            ) {
                $candidates[] = 'super_admin';
            }

            if (
                ($claims['isAdmin'] ?? false) === true
                || ($claims['admin'] ?? false) === true
            ) {
                $candidates[] = 'admin';
            }

            if (!empty($claims['role'])) {
                $candidates[] = strtolower(
                    trim((string) $claims['role'])
                );
            }
        }

        /*
         * Imported user_data role.
         */
        if (Schema::hasTable('user_data')) {
            $profileRole = DB::table('user_data')
                ->where(
                    function ($query) use (
                        $user,
                        $uid
                    ): void {
                        if ($user->exists) {
                            $query
                                ->where(
                                    'user_id',
                                    $user->id
                                )
                                ->orWhere(
                                    'firebase_uid',
                                    $uid
                                );
                        } else {
                            $query->where(
                                'firebase_uid',
                                $uid
                            );
                        }
                    }
                )
                ->orderByDesc('id')
                ->value('role');

            if ($profileRole) {
                $candidates[] = strtolower(
                    trim((string) $profileRole)
                );
            }
        }

        if (
            in_array(
                'super_admin',
                $candidates,
                true
            )
        ) {
            return 'super_admin';
        }

        if (
            in_array(
                'admin',
                $candidates,
                true
            )
        ) {
            return 'admin';
        }

        return 'user';
    }

    /**
     * Failure response deliberately contains NO "errors" array.
     *
     * This is necessary for compatibility with already-released
     * React Native/Hermes clients whose generic error parser calls
     * Array.flat() when Laravel returns a validation errors object.
     */
    private function failureCompat(
        string $code,
        string $message,
        int $status
    ): JsonResponse {
        return response()->json([
            'ok' => false,

            /*
             * Old clients can read payload.message.
             */
            'message' => $message,

            /*
             * New clients can read payload.error.message.
             */
            'error' => [
                'code' => $code,
                'message' => $message,
                'retryable' => false,
            ],

            'meta' => [
                'request_id' => request()
                    ->attributes
                    ->get('request_id'),

                'server_time' => now()->toISOString(),
            ],
        ], $status);
    }
}
