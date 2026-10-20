<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\RefreshToken;
use App\Models\User;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class FirebaseExchangeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        // Accept the original mobile key as a compatibility alias while all
        // clients move to the documented id_token contract.
        $validated = $request->validate([
            'id_token' => ['required_without:firebase_token', 'nullable', 'string', 'max:8192'],
            'firebase_token' => ['required_without:id_token', 'nullable', 'string', 'max:8192'],
        ]);
        $claims = $this->verifyIdToken((string) ($validated['id_token'] ?? $validated['firebase_token']));
        $uid = (string) $claims->sub;
        $email = isset($claims->email) ? (string) $claims->email : null;

        $user = User::where('legacy_firebase_uid', $uid)->orWhere('firebase_uid', $uid)->first();
        if (! $user && $email) {
            $user = User::where('email', $email)->first();
        }
        if (! $user) {
            $user = new User();
        }
        $user->fill([
            'firebase_uid' => $user->firebase_uid ?: $uid,
            'legacy_firebase_uid' => $uid,
            'email' => $email ?: $user->email,
            'name' => $user->name ?: ($claims->name ?? 'CeleOne user'),
            'avatar_url' => $user->avatar_url ?: ($claims->picture ?? null),
            'email_verified_at' => ($claims->email_verified ?? false) ? now() : $user->email_verified_at,
        ]);
        $user->save();

        $accessToken = $user->createToken('firebase-exchange', ['mobile'])->plainTextToken;
        $rawRefreshToken = Str::random(96);
        RefreshToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $rawRefreshToken),
            'expires_at' => now()->addDays((int) config('sanctum.expiration', 30)),
            'device_id' => $request->header('X-Device-ID'),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
        ]);

        return response()->json([
            'ok' => true,
            'data' => ['access_token' => $accessToken, 'refresh_token' => $rawRefreshToken, 'token_type' => 'Bearer', 'user' => $user],
            'meta' => ['request_id' => 'req_'.Str::lower(Str::random(20)), 'server_time' => now()->utc()->toISOString()],
        ]);
    }

    private function verifyIdToken(string $token): object
    {
        $keys = Cache::remember('firebase:secure-token-keys', now()->addHours(6), function (): array {
            return Http::timeout(10)->get('https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com')->throw()->json();
        });
        $claims = JWT::decode($token, JWK::parseKeySet($keys));
        $projectId = (string) config('services.firebase.project_id');
        abort_unless(($claims->aud ?? null) === $projectId, 401, 'Invalid Firebase audience.');
        abort_unless(($claims->iss ?? null) === 'https://securetoken.google.com/'.$projectId, 401, 'Invalid Firebase issuer.');
        abort_unless(isset($claims->sub) && is_string($claims->sub) && $claims->sub !== '', 401, 'Invalid Firebase subject.');
        abort_unless(! isset($claims->auth_time) || (int) $claims->auth_time <= now()->timestamp, 401, 'Invalid Firebase authentication time.');
        return $claims;
    }
}
