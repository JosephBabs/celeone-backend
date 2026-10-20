<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ManagementAuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required','email'],
            'password' => ['required','string'],
        ]);

        $user = User::whereRaw('lower(email) = ?', [strtolower($data['email'])])->first();

        abort_unless(
            $user && Hash::check($data['password'], (string) $user->password),
            422,
            'Invalid credentials.'
        );

        $role = strtolower((string) ($user->role ?? ''));
        abort_unless(in_array($role, ['admin','super_admin'], true), 403, 'Administrator role required.');

        $user->tokens()->where('name', 'management-console')->delete();
        $token = $user->createToken('management-console')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => $this->present($user),
            ],
        ]);
    }

    public function me(Request $request)
    {
        return response()->json(['data' => ['user' => $this->present($request->user())]]);
    }

    public function logout(Request $request)
    {
        $request->user()?->currentAccessToken()?->delete();
        return response()->json(['data' => ['logged_out' => true]]);
    }

    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'uid' => (string) $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'displayName' => $user->name,
            'role' => $user->role,
            'status' => $user->status ?? 'active',
        ];
    }
}
