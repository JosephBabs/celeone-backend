<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FcmPushService
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public function send(string $token, string $title, string $body, array $data = [], string $priority = 'high', ?string $collapseKey = null): array
    {
        $credentials = $this->credentials();
        $projectId = trim((string) (config('fcm.project_id') ?: ($credentials['project_id'] ?? '')));
        if ($projectId === '') {
            throw new RuntimeException('FCM_PROJECT_ID_NOT_CONFIGURED');
        }

        $type = strtolower(trim((string) ($data['type'] ?? $data['notificationType'] ?? '')));
        $isCall = str_contains($type, 'call');
        $isChat = in_array($type, ['chat', 'message', 'new_message', 'dm_message', 'group_message'], true);
        $dataOnly = $isCall || $isChat;
        $ttlSeconds = $isCall ? 45 : ($isChat ? 86400 : 3600);

        $stringData = [];
        foreach (array_merge(['title' => $title, 'body' => $body], $data) as $key => $value) {
            if ($value === null) $value = '';
            if (is_bool($value)) $value = $value ? 'true' : 'false';
            if (is_array($value) || is_object($value)) $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $stringData[(string) $key] = (string) $value;
        }

        $channel = $isCall ? 'celeone.calls' : 'celeone.default';
        $message = [
            'token' => $token,
            'data' => $stringData,
            'android' => [
                'priority' => strtoupper($priority) === 'HIGH' ? 'HIGH' : 'NORMAL',
                'ttl' => $ttlSeconds . 's',
                'collapse_key' => $collapseKey ?: null,
                'restricted_package_name' => config('fcm.android_package') ?: null,
            ],
            'apns' => [
                'headers' => [
                    'apns-priority' => $dataOnly ? '5' : '10',
                    'apns-push-type' => $dataOnly ? 'background' : 'alert',
                ],
                'payload' => [
                    'aps' => [
                        'content-available' => 1,
                        'sound' => $dataOnly ? null : 'default',
                    ],
                ],
            ],
        ];

        if (! $dataOnly) {
            $message['notification'] = ['title' => $title, 'body' => $body];
            $message['android']['notification'] = [
                'channel_id' => $channel,
                'sound' => 'default',
                'tag' => $collapseKey ?: null,
            ];
        }

        $message = $this->removeNulls($message);
        $response = Http::withToken($this->accessToken($credentials))
            ->acceptJson()
            ->connectTimeout((int) config('fcm.connect_timeout', 10))
            ->timeout((int) config('fcm.timeout', 20))
            ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", ['message' => $message]);

        $json = $response->json() ?: [];
        $providerCode = $this->providerErrorCode($json);

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'provider_message_id' => (string) ($json['name'] ?? ''),
            'provider_code' => $providerCode,
            'invalid_token' => in_array($providerCode, ['UNREGISTERED', 'SENDER_ID_MISMATCH'], true),
            'retryable' => $response->serverError() || $response->status() === 429 || in_array($providerCode, ['UNAVAILABLE', 'INTERNAL', 'RESOURCE_EXHAUSTED'], true),
            'response' => $json,
        ];
    }

    private function accessToken(array $credentials): string
    {
        $cacheKey = 'celeone:fcm:oauth:' . sha1((string) ($credentials['client_email'] ?? ''));
        return Cache::remember($cacheKey, now()->addMinutes(50), function () use ($credentials): string {
            $email = trim((string) ($credentials['client_email'] ?? ''));
            $privateKey = (string) ($credentials['private_key'] ?? '');
            $tokenUri = trim((string) ($credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token'));
            if ($email === '' || $privateKey === '') {
                throw new RuntimeException('FCM_CREDENTIAL_FIELDS_MISSING');
            }

            $now = time();
            $header = ['alg' => 'RS256', 'typ' => 'JWT'];
            $claims = [
                'iss' => $email,
                'scope' => self::SCOPE,
                'aud' => $tokenUri,
                'iat' => $now,
                'exp' => $now + 3600,
            ];
            $unsigned = $this->b64(json_encode($header, JSON_UNESCAPED_SLASHES)) . '.' . $this->b64(json_encode($claims, JSON_UNESCAPED_SLASHES));
            $key = openssl_pkey_get_private($privateKey);
            if ($key === false) throw new RuntimeException('FCM_PRIVATE_KEY_INVALID');
            $signature = '';
            if (! openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('FCM_JWT_SIGN_FAILED');
            }
            $assertion = $unsigned . '.' . $this->b64($signature);

            $response = Http::asForm()
                ->connectTimeout((int) config('fcm.connect_timeout', 10))
                ->timeout((int) config('fcm.timeout', 20))
                ->post($tokenUri, [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $assertion,
                ]);
            if (! $response->successful()) {
                throw new RuntimeException('FCM_OAUTH_FAILED:' . $response->status() . ':' . substr($response->body(), 0, 300));
            }
            $token = trim((string) data_get($response->json(), 'access_token', ''));
            if ($token === '') throw new RuntimeException('FCM_ACCESS_TOKEN_MISSING');
            return $token;
        });
    }

    private function credentials(): array
    {
        $path = trim((string) (config('fcm.credentials') ?: config('firebase_compat.credentials')));
        if ($path === '') throw new RuntimeException('FCM_CREDENTIAL_PATH_NOT_CONFIGURED');
        if (! str_starts_with($path, '/') && ! preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
            $path = base_path($path);
        }
        if (! is_file($path)) throw new RuntimeException('FCM_CREDENTIAL_FILE_MISSING');
        if (! is_readable($path)) throw new RuntimeException('FCM_CREDENTIAL_FILE_NOT_READABLE');
        $json = json_decode((string) file_get_contents($path), true);
        if (! is_array($json)) throw new RuntimeException('FCM_CREDENTIAL_JSON_INVALID');
        return $json;
    }

    private function providerErrorCode(array $json): string
    {
        foreach ((array) data_get($json, 'error.details', []) as $detail) {
            if (is_array($detail) && ! empty($detail['errorCode'])) return (string) $detail['errorCode'];
        }
        return (string) (data_get($json, 'error.status') ?: data_get($json, 'error.code') ?: '');
    }

    private function removeNulls(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $item = $this->removeNulls($item);
                if ($item === []) unset($value[$key]); else $value[$key] = $item;
            } elseif ($item === null || $item === '') {
                unset($value[$key]);
            }
        }
        return $value;
    }

    private function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
