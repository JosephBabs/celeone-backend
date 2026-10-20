<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordMigrationController extends Controller
{
    use ApiResponses;

    private const OTP_TTL_MINUTES = 10;
    private const TOKEN_TTL_MINUTES = 15;

    public function requestOtp(Request $request)
    {
        abort_unless($request->user(), 401);
        return Cache::lock('password-migration:' . $request->user()->id, 30)->block(5, function () use ($request) {
        $data = $request->validate(['email' => ['required', 'email', 'max:190']]);
        $email = $this->accountEmail($request, $data['email']);
        $user = $request->user();

        // Do not reveal whether an address exists.
        if ($user) {
            $code = (string) random_int(100000, 999999);
            Cache::put($this->otpKey($email), Hash::make($code), now()->addMinutes(self::OTP_TTL_MINUTES));
            Cache::forget($this->tokenKey($email));
            Cache::put($this->otpKey($email) . ':attempts', 0, now()->addMinutes(self::OTP_TTL_MINUTES));

            $purpose = $this instanceof PasswordResetController ? 'recovery' : 'migration';
            Mail::send(
                ['html' => 'emails.security-code', 'text' => 'emails.security-code-text'],
                ['code' => $code, 'minutes' => self::OTP_TTL_MINUTES, 'purpose' => $purpose],
                function ($message) use ($email, $purpose): void {
                    $message->to($email)->subject($purpose === 'recovery'
                        ? 'Your CeleOne password recovery code'
                        : 'Secure your CeleOne account');
                },
            );
        }

        return $this->success(['sent' => true, 'expires_in' => self::OTP_TTL_MINUTES * 60]);
        });
    }

    public function verifyOtp(Request $request)
    {
        abort_unless($request->user(), 401);
        return Cache::lock('password-migration:' . $request->user()->id, 30)->block(5, function () use ($request) {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'code' => ['required', 'digits:6'],
        ]);
        $email = $this->accountEmail($request, $data['email']);
        if (Cache::increment($this->otpKey($email) . ':attempts') > 5) {
            Cache::forget($this->otpKey($email));
            throw ValidationException::withMessages(['code' => ['Too many attempts. Request a new code.']]);
        }
        $stored = Cache::get($this->otpKey($email));

        if (! is_string($stored) || ! Hash::check($data['code'], $stored)) {
            throw ValidationException::withMessages(['code' => ['The verification code is invalid or expired.']]);
        }

        $token = Str::random(64);
        Cache::put($this->tokenKey($email), Hash::make($token), now()->addMinutes(self::TOKEN_TTL_MINUTES));
        Cache::forget($this->otpKey($email));

        return $this->success(['verification_token' => $token, 'expires_in' => self::TOKEN_TTL_MINUTES * 60]);
        });
    }

    public function complete(Request $request)
    {
        abort_unless($request->user(), 401);
        return Cache::lock('password-migration:' . $request->user()->id, 30)->block(5, function () use ($request) {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'verification_token' => ['required', 'string', 'min:40'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);
        $email = $this->accountEmail($request, $data['email']);
        $stored = Cache::get($this->tokenKey($email));

        if (! is_string($stored) || ! Hash::check($data['verification_token'], $stored)) {
            throw ValidationException::withMessages(['verification_token' => ['The verification token is invalid or expired.']]);
        }

        $user = $request->user();
        if (! $user) {
            throw ValidationException::withMessages(['email' => ['The account could not be found.']]);
        }

        $session = DB::transaction(function () use ($user, $data): array {
            $user->forceFill(['password' => $data['password'], 'password_migrated_at' => now()])->save();
            $user->tokens()->delete();
            return ['user' => $user, 'password_migrated' => true,
                'access_token' => $user->createToken('password-migration')->plainTextToken,
                'token_type' => 'Bearer'];
        });
        Cache::forget($this->tokenKey($email));
        return $this->success($session);
        });
    }

    private function accountEmail(Request $request, string $submitted): string
    {
        $user = $request->user();
        abort_unless($user && ! $user->firebase_disabled, 403);
        $email = strtolower(trim((string) $user->email));
        abort_unless(hash_equals($email, strtolower(trim($submitted))), 403, 'Only your own account can be updated.');
        return $email;
    }

    protected function otpKey(string $email): string
    {
        return 'celeone:password-migration:v2:otp:' . hash('sha256', $email);
    }

    protected function tokenKey(string $email): string
    {
        return 'celeone:password-migration:v2:token:' . hash('sha256', $email);
    }
}
