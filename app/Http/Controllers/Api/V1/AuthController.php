<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use ApiResponses;

    public function confirmPassword(Request $request)
    {
        $data=$request->validate(['password'=>'required|string|max:1024']);
        if (!Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages(['password'=>'Incorrect password.']);
        }
        return $this->success(['confirmed'=>true]);
    }

    public function register(Request $request)
    {
        $data=$request->validate(['name'=>'nullable|string|max:120','email'=>'required|email|max:190|unique:users,email','password'=>'required|string|min:6|confirmed']);
        $email = strtolower(trim($data['email']));
        $user=User::create(['name'=>$data['name'] ?? Str::before($email, '@'),'email'=>$email,'password'=>$data['password'],'role'=>'user']);
        return $this->success($this->tokens($user, $request), 201);
    }

    public function login(Request $request)
    {
        $data=$request->validate(['email'=>'required|email','password'=>'required|string','device_name'=>'nullable|string|max:120']);
        $email = strtolower(trim($data['email']));
        // Imported accounts are normalized to lowercase. Use the normal index
        // first; retain a case-insensitive fallback for older records.
        $user=User::where('email', $email)->first()
            ?? User::whereRaw('lower(email)=?', [$email])->first();
        if (! $user || $user->firebase_disabled || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email'=>['Invalid credentials.']]);
        }
        return $this->success($this->tokens($user, $request));
    }

    public function me(Request $request)
    {
        $user = $request->user();
        $userDataQuery = \DB::table('user_data')->where('user_id', $user->id);
        if ($user->firebase_uid) $userDataQuery->orWhere('firebase_uid', $user->firebase_uid);
        $userData = $userDataQuery->latest('id')->first();
        return $this->success(['user' => $user, 'user_data' => $userData]);
    }

    public function logout(Request $request)
    {
        $request->user()?->currentAccessToken()?->delete();
        return $this->success(['logged_out'=>true]);
    }

    private function tokens(User $user, Request $request): array
    {
        if (! $user->password_migrated_at) $user->forceFill(['password_migrated_at' => now()])->save();
        $name=(string) ($request->input('device_name') ?: $request->header('X-Device-ID') ?: 'mobile');
        return ['user'=>$user,'password_migrated'=>true,'access_token'=>$user->createToken($name)->plainTextToken,'token_type'=>'Bearer'];
    }
}
