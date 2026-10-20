<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\ProfilePrivacy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** @tags Profile privacy */
class ProfilePrivacyController
{
    /** Get your profile contact visibility. Hidden fields remain visible to their owner. */
    public function show(Request $request, ProfilePrivacy $privacy): JsonResponse
    {
        return response()->json($privacy->settings($request->user()));
    }

    /** Update your profile contact visibility. Omitted settings remain unchanged. */
    public function update(Request $request, ProfilePrivacy $privacy): JsonResponse
    {
        $data = $request->validate(['hideEmail' => ['sometimes', 'required', 'boolean'], 'hidePhone' => ['sometimes', 'required', 'boolean']]);
        DB::transaction(function () use ($request, $data) {
            DB::table('profile_privacy_settings')->insertOrIgnore(['user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $update = ['updated_at' => now()];
            foreach (['hideEmail' => 'hide_email', 'hidePhone' => 'hide_phone'] as $key => $column) {
                if (array_key_exists($key, $data)) {
                    $update[$column] = $data[$key];
                }
            }
            DB::table('profile_privacy_settings')->where('user_id', $request->user()->id)->update($update);
        });

        return response()->json($privacy->settings($request->user()));
    }
}
