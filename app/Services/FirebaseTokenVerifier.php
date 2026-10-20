<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class FirebaseTokenVerifier
{
    public function verify(string $idToken): array
    {
        $idToken = trim($idToken);

        if ($idToken === '') {
            throw new RuntimeException('Firebase ID token is empty.');
        }

        $apiKey = trim((string) config('services.firebase.api_key'));

        if ($apiKey === '') {
            Log::error('FIREBASE_VERIFY_CONFIG_MISSING', [
                'config' => 'services.firebase.api_key',
            ]);

            throw new RuntimeException(
                'Firebase API key is not configured.'
            );
        }

        /*
         * Ask Firebase/Google to validate the exact Firebase ID token.
         *
         * This works with Firebase sessions created by older versions
         * of the Cèlè One mobile application, including Google login
         * followed by Firebase signInWithCredential().
         */
        try {
            $response = Http::asJson()
                ->acceptJson()
                ->timeout(15)
                ->post(
                    'https://identitytoolkit.googleapis.com/v1/accounts:lookup?key='
                    . urlencode($apiKey),
                    [
                        'idToken' => $idToken,
                    ]
                );
        } catch (\Throwable $exception) {
            Log::warning('FIREBASE_VERIFY_HTTP_EXCEPTION', [
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
            ]);

            throw new RuntimeException(
                'Firebase verification service could not be reached.',
                0,
                $exception
            );
        }

        if (!$response->successful()) {
            Log::warning('FIREBASE_VERIFY_REJECTED', [
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            throw new RuntimeException(
                'Firebase rejected the supplied ID token.'
            );
        }

        $identity = $response->json('users.0');

        if (!is_array($identity)) {
            Log::warning('FIREBASE_VERIFY_NO_USER', [
                'status' => $response->status(),
            ]);

            throw new RuntimeException(
                'Firebase account could not be resolved.'
            );
        }

        $uid = trim((string) ($identity['localId'] ?? ''));

        if ($uid === '') {
            throw new RuntimeException(
                'Firebase identity does not contain a UID.'
            );
        }

        /*
         * Decode the Firebase JWT payload only AFTER Google's
         * accounts:lookup endpoint has accepted the token.
         *
         * We use the claims to determine which provider created
         * the Firebase session, particularly google.com.
         */
        $claims = $this->decodeJwtPayload($idToken);

        $subject = trim((string) ($claims['sub'] ?? ''));

        if ($subject === '' || !hash_equals($uid, $subject)) {
            Log::warning('FIREBASE_VERIFY_UID_MISMATCH', [
                'lookup_uid' => $uid,
                'jwt_subject' => $subject,
            ]);

            throw new RuntimeException(
                'Firebase identity mismatch.'
            );
        }

        /*
         * Optional additional Firebase project validation.
         *
         * If FIREBASE_PROJECT_ID is configured, make sure the
         * Firebase token really belongs to Cèlè One's project.
         */
        $projectId = trim(
            (string) config('services.firebase.project_id')
        );

        if ($projectId !== '') {
            $audience = trim(
                (string) ($claims['aud'] ?? '')
            );

            $issuer = trim(
                (string) ($claims['iss'] ?? '')
            );

            $expectedIssuer =
                'https://securetoken.google.com/' . $projectId;

            if (
                $audience !== ''
                && !hash_equals($projectId, $audience)
            ) {
                Log::warning('FIREBASE_VERIFY_AUDIENCE_MISMATCH', [
                    'audience' => $audience,
                    'expected' => $projectId,
                ]);

                throw new RuntimeException(
                    'Firebase project does not match.'
                );
            }

            if (
                $issuer !== ''
                && !hash_equals($expectedIssuer, $issuer)
            ) {
                Log::warning('FIREBASE_VERIFY_ISSUER_MISMATCH', [
                    'issuer' => $issuer,
                    'expected' => $expectedIssuer,
                ]);

                throw new RuntimeException(
                    'Firebase issuer does not match.'
                );
            }
        }

        $provider = trim(
            (string) (
                $claims['firebase']['sign_in_provider']
                ?? ''
            )
        );

        /*
         * Fallback for very old Firebase responses where the provider
         * claim is missing for some reason.
         */
        if (
            $provider === ''
            && isset($identity['providerUserInfo'])
            && is_array($identity['providerUserInfo'])
        ) {
            foreach ($identity['providerUserInfo'] as $providerInfo) {
                if (!is_array($providerInfo)) {
                    continue;
                }

                $providerId = trim(
                    (string) ($providerInfo['providerId'] ?? '')
                );

                if ($providerId !== '') {
                    $provider = $providerId;
                    break;
                }
            }
        }

        $identity['verifiedSignInProvider'] = $provider;

        Log::info('FIREBASE_VERIFY_SUCCESS', [
            'firebase_uid' => $uid,
            'email' => $identity['email'] ?? null,
            'email_verified' => $identity['emailVerified'] ?? false,
            'provider' => $provider,
        ]);

        return $identity;
    }

    /**
     * Safely decode the payload section of a JWT.
     */
    private function decodeJwtPayload(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) < 2) {
            throw new RuntimeException(
                'Firebase token has an invalid JWT format.'
            );
        }

        $payload = strtr(
            $parts[1],
            '-_',
            '+/'
        );

        /*
         * Restore base64 padding.
         */
        $remainder = strlen($payload) % 4;

        if ($remainder !== 0) {
            $payload .= str_repeat(
                '=',
                4 - $remainder
            );
        }

        $decoded = base64_decode(
            $payload,
            true
        );

        if ($decoded === false) {
            throw new RuntimeException(
                'Firebase token payload could not be decoded.'
            );
        }

        $claims = json_decode(
            $decoded,
            true
        );

        if (!is_array($claims)) {
            throw new RuntimeException(
                'Firebase token payload is invalid.'
            );
        }

        return $claims;
    }
}
