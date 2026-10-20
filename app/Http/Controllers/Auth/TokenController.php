<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\RefreshToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TokenController extends Controller
{
    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate(['refresh_token' => ['required', 'string', 'min:32']]);
        $token = RefreshToken::with('user')
            ->where('token_hash', hash('sha256', $data['refresh_token']))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();
        abort_unless($token, 401, 'Invalid or expired refresh token.');

        $token->update(['revoked_at' => now()]);
        $newRefresh = Str::random(96);
        RefreshToken::create([
            'user_id' => $token->user_id,
            'token_hash' => hash('sha256', $newRefresh),
            'expires_at' => now()->addDays(30),
            'device_id' => $request->header('X-Device-ID'),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
        ]);

        return response()->json([
            'ok' => true,
            'data' => ['access_token' => $token->user->createToken('mobile-refresh', ['mobile'])->plainTextToken, 'refresh_token' => $newRefresh, 'token_type' => 'Bearer'],
            'meta' => ['request_id' => 'req_'.Str::lower(Str::random(20)), 'server_time' => now()->utc()->toISOString()],
        ]);
    }

    public function revoke(Request $request): JsonResponse
    {
        $data = $request->validate(['refresh_token' => ['required', 'string', 'min:32']]);
        RefreshToken::where('token_hash', hash('sha256', $data['refresh_token']))
            ->where('user_id', $request->user()->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $request->user()->currentAccessToken()?->delete();
        return response()->json(['ok' => true, 'data' => ['revoked' => true], 'meta' => ['server_time' => now()->utc()->toISOString()]]);
    }
}
