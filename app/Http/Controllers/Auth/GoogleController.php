<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Google\Client as GoogleClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GoogleController extends Controller
{
    /**
     * Exchange a Google ID token for a Laravel Sanctum access token.
     *
     * Flow:
     * Mobile -> Google ID token -> Laravel -> Google verification
     * -> Laravel user -> Sanctum token.
     *
     * Firebase Auth is intentionally not used here.
     */
    public function handleGoogleSignIn(Request $request): JsonResponse
    {
        Log::info('GOOGLE_AUTH_REQUEST_RECEIVED', [
            'has_id_token' => $request->filled('id_token'),
            'has_legacy_idToken' => $request->filled('idToken'),
            'device_name' => $request->input('device_name'),
            'user_agent' => $request->userAgent(),
            'request_id' => $request->attributes->get('request_id'),
        ]);

        $data = $request->validate([
            'id_token' => ['nullable', 'string'],
            'idToken' => ['nullable', 'string'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        /*
         * Support both:
         *
         * {
         *   "id_token": "..."
         * }
         *
         * and the older:
         *
         * {
         *   "idToken": "..."
         * }
         */
        $idToken = trim((string) (
            $data['id_token']
            ?? $data['idToken']
            ?? ''
        ));

        if ($idToken === '') {
            Log::warning('GOOGLE_AUTH_TOKEN_MISSING', [
                'request_id' => $request->attributes->get('request_id'),
            ]);

            throw ValidationException::withMessages([
                'id_token' => ['Google ID token is required.'],
            ]);
        }

        $clientId = trim((string) config('services.google.web_client_id'));

        if ($clientId === '') {
            Log::error('GOOGLE_AUTH_SERVER_NOT_CONFIGURED', [
                'request_id' => $request->attributes->get('request_id'),
            ]);

            return $this->googleFailure(
                'GOOGLE_SERVER_NOT_CONFIGURED',
                'Google authentication is not configured on the server.',
                503
            );
        }

        try {
            /*
             * Verify Google token server-side.
             */
            $googleClient = new GoogleClient([
                'client_id' => $clientId,
            ]);

            $payload = $googleClient->verifyIdToken($idToken);

            if (!is_array($payload)) {
                Log::warning('GOOGLE_AUTH_INVALID_TOKEN', [
                    'request_id' => $request->attributes->get('request_id'),
                ]);

                return $this->googleFailure(
                    'GOOGLE_TOKEN_INVALID',
                    'Invalid Google ID token.',
                    401
                );
            }

            $subject = trim((string) ($payload['sub'] ?? ''));
            $email = strtolower(trim((string) ($payload['email'] ?? '')));
            $audience = trim((string) ($payload['aud'] ?? ''));
            $issuer = trim((string) ($payload['iss'] ?? ''));
            $expiresAt = (int) ($payload['exp'] ?? 0);

            $emailVerified = filter_var(
                $payload['email_verified'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            );

            /*
             * Extra verification even though verifyIdToken() already
             * validates Google's signature and token claims.
             */
            $tokenIsValid =
                $subject !== ''
                && $email !== ''
                && $emailVerified === true
                && hash_equals($clientId, $audience)
                && in_array(
                    $issuer,
                    [
                        'accounts.google.com',
                        'https://accounts.google.com',
                    ],
                    true
                )
                && $expiresAt > time();

            if (!$tokenIsValid) {
                Log::warning('GOOGLE_AUTH_TOKEN_REJECTED', [
                    'has_subject' => $subject !== '',
                    'has_email' => $email !== '',
                    'email_verified' => $emailVerified,
                    'audience_matches' => (
                        $audience !== ''
                        && hash_equals($clientId, $audience)
                    ),
                    'issuer' => $issuer,
                    'expired' => $expiresAt <= time(),
                    'request_id' => $request->attributes->get('request_id'),
                ]);

                return $this->googleFailure(
                    'GOOGLE_TOKEN_REJECTED',
                    'Google token verification failed.',
                    401
                );
            }

            Log::info('GOOGLE_AUTH_TOKEN_VERIFIED', [
                'google_subject' => $subject,
                'email' => $email,
                'request_id' => $request->attributes->get('request_id'),
            ]);

            /*
             * Find, link or create the Cèlè One account.
             */
            $user = DB::transaction(
                function () use ($payload, $subject, $email): User {

                    $byGoogle = null;

                    if (Schema::hasColumn('users', 'google_id')) {
                        $byGoogle = User::query()
                            ->where('google_id', $subject)
                            ->lockForUpdate()
                            ->first();
                    }

                    $byEmail = User::query()
                        ->whereRaw('LOWER(email) = ?', [$email])
                        ->lockForUpdate()
                        ->first();

                    /*
                     * Google ID and e-mail must never resolve to two
                     * different existing Cèlè One users.
                     */
                    if (
                        $byGoogle
                        && $byEmail
                        && $byGoogle->id !== $byEmail->id
                    ) {
                        abort(
                            409,
                            'This Google account conflicts with an existing Cele One account.'
                        );
                    }

                    $user = $byGoogle ?: $byEmail;

                    /*
                     * Existing e-mail linked to another Google account.
                     */
                    if (
                        $user
                        && Schema::hasColumn('users', 'google_id')
                        && !empty($user->google_id)
                        && !hash_equals(
                            (string) $user->google_id,
                            $subject
                        )
                    ) {
                        abort(
                            409,
                            'This email is already linked to another Google account.'
                        );
                    }

                    $googleName = trim(
                        (string) ($payload['name'] ?? '')
                    );

                    if ($googleName === '') {
                        $googleName = Str::before($email, '@');
                    }

                    $googlePicture = $payload['picture'] ?? null;

                    if (!$user) {
                        $createData = [
                            'name' => $googleName,
                            'email' => $email,

                            /*
                             * Google-only accounts do not use this password.
                             * A secure random hash prevents an empty password.
                             */
                            'password' => Hash::make(
                                Str::random(64)
                            ),
                        ];

                        if (Schema::hasColumn('users', 'google_id')) {
                            $createData['google_id'] = $subject;
                        }

                        if (
                            Schema::hasColumn(
                                'users',
                                'avatar_url'
                            )
                        ) {
                            $createData['avatar_url'] = $googlePicture;
                        }

                        if (Schema::hasColumn('users', 'role')) {
                            $createData['role'] = 'user';
                        }

                        if (
                            Schema::hasColumn(
                                'users',
                                'email_verified_at'
                            )
                        ) {
                            $createData['email_verified_at'] = now();
                        }

                        if (
                            Schema::hasColumn(
                                'users',
                                'password_migrated_at'
                            )
                        ) {
                            $createData['password_migrated_at'] = now();
                        }

                        $user = User::create($createData);
                    } else {
                        $updates = [];

                        if (
                            Schema::hasColumn('users', 'google_id')
                            && empty($user->google_id)
                        ) {
                            $updates['google_id'] = $subject;
                        }

                        if (empty($user->name)) {
                            $updates['name'] = $googleName;
                        }

                        if (
                            Schema::hasColumn(
                                'users',
                                'avatar_url'
                            )
                            && empty($user->avatar_url)
                            && !empty($googlePicture)
                        ) {
                            $updates['avatar_url'] = $googlePicture;
                        }

                        if (
                            Schema::hasColumn(
                                'users',
                                'email_verified_at'
                            )
                            && empty($user->email_verified_at)
                        ) {
                            $updates['email_verified_at'] = now();
                        }

                        if (
                            Schema::hasColumn(
                                'users',
                                'password_migrated_at'
                            )
                            && empty($user->password_migrated_at)
                        ) {
                            /*
                             * Google authenticated users have already
                             * established ownership of the e-mail account.
                             */
                            $updates['password_migrated_at'] = now();
                        }

                        if (!empty($updates)) {
                            $user->forceFill($updates);
                            $user->save();
                        }
                    }

                    /*
                     * Preserve your existing disabled-account behaviour.
                     */
                    if (
                        Schema::hasColumn(
                            'users',
                            'firebase_disabled'
                        )
                        && (bool) $user->firebase_disabled
                    ) {
                        abort(403, 'This account is disabled.');
                    }

                    return $user->fresh();
                }
            );

            /*
             * Generate Laravel Sanctum token.
             */
            $deviceName = trim((string) (
                $data['device_name']
                ?? $request->header('X-Device-ID')
                ?? 'celeone-mobile-google'
            ));

            if ($deviceName === '') {
                $deviceName = 'celeone-mobile-google';
            }

            $accessToken = $user
                ->createToken($deviceName)
                ->plainTextToken;

            Log::info('GOOGLE_AUTH_SUCCESS', [
                'user_id' => $user->id,
                'email' => $user->email,
                'device_name' => $deviceName,
                'request_id' => $request->attributes->get('request_id'),
            ]);

            /*
             * IMPORTANT:
             *
             * We expose the session in BOTH formats.
             *
             * New mobile:
             * response.data.access_token
             *
             * Compatibility/older mobile:
             * response.access_token
             *
             * This removes response-shape ambiguity while the app is
             * transitioning from Firebase Auth to Laravel authentication.
             */
            return response()->json([
                'ok' => true,

                /*
                 * Compatibility fields.
                 */
                'access_token' => $accessToken,
                'token' => $accessToken,
                'token_type' => 'Bearer',
                'password_migrated' => true,
                'user' => $user,

                /*
                 * Canonical API structure.
                 */
                'data' => [
                    'access_token' => $accessToken,
                    'token' => $accessToken,
                    'token_type' => 'Bearer',
                    'password_migrated' => true,
                    'user' => $user,
                ],

                'meta' => [
                    'request_id' => $request
                        ->attributes
                        ->get('request_id'),

                    'server_time' => now()->toISOString(),
                ],
            ]);
        } catch (
            \Symfony\Component\HttpKernel\Exception\HttpException $exception
        ) {
            /*
             * Preserve explicit 403/409 responses generated above.
             */
            Log::warning('GOOGLE_AUTH_HTTP_EXCEPTION', [
                'status' => $exception->getStatusCode(),
                'message' => $exception->getMessage(),
                'request_id' => $request->attributes->get('request_id'),
            ]);

            throw $exception;
        } catch (\Throwable $exception) {
            Log::error('GOOGLE_AUTH_EXCEPTION', [
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'request_id' => $request->attributes->get('request_id'),
            ]);

            return $this->googleFailure(
                'GOOGLE_AUTH_FAILED',
                'Google authentication failed.',
                401
            );
        }
    }

    /**
     * Consistent Google authentication failure response.
     */
    private function googleFailure(
        string $code,
        string $message,
        int $status
    ): JsonResponse {
        return response()->json([
            'ok' => false,

            'error' => [
                'code' => $code,
                'message' => $message,
            ],

            /*
             * Also expose message at the top level so older mobile
             * error handlers can display the real server error.
             */
            'message' => $message,

            'meta' => [
                'request_id' => request()
                    ->attributes
                    ->get('request_id'),

                'server_time' => now()->toISOString(),
            ],
        ], $status);
    }
}
