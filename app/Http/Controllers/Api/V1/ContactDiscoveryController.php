<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContactDiscoveryController extends Controller
{
    use ApiResponses;

    public function match(Request $request)
    {
        $input = $request->validate(['phoneNumbers' => 'required|array|max:500', 'phoneNumbers.*' => ['required', 'string', 'regex:/^\+[1-9][0-9]{6,14}$/']]);
        $wanted = array_fill_keys($input['phoneNumbers'], true);
        $matches = [];
        // The address book stays on the device: only normalized numbers arrive,
        // and only matching, discoverable profiles are returned.
        foreach (DB::table('users')->leftJoin('user_data', 'user_data.user_id', '=', 'users.id')
            ->where('users.id', '!=', $request->user()->id)->where('users.firebase_disabled', false)
            ->select('users.id', 'users.firebase_uid', 'users.legacy_firebase_uid', 'users.name', 'users.avatar_url', 'users.phone_number', 'user_data.profile_data')->cursor() as $row) {
            $profile = json_decode((string) ($row->profile_data ?? '{}'), true) ?: [];
            if (($profile['phoneDiscoveryEnabled'] ?? true) === false || ($profile['phoneVerified'] ?? true) === false) continue;
            foreach ([$row->phone_number, $profile['phoneE164'] ?? null, $profile['phoneNumber'] ?? null, $profile['phone'] ?? null, $profile['mobile'] ?? null] as $raw) {
                $phone = preg_replace('/[^+0-9]/', '', (string) $raw);
                if (str_starts_with($phone, '00')) $phone = '+'.substr($phone, 2);
                if (!str_starts_with($phone, '+')) {
                    $region = strtoupper((string)($profile['phoneCountryCode'] ?? $profile['countryCode'] ?? $profile['country'] ?? ''));
                    $region = ['FRANCE'=>'FR','BENIN'=>'BJ','NIGERIA'=>'NG','UNITED STATES'=>'US','USA'=>'US','UNITED KINGDOM'=>'GB'][$region] ?? $region;
                    static $regions; $regions ??= json_decode(file_get_contents(config_path('phone_regions.json')), true);
                    $plan = $regions[$region] ?? null;
                    if ($plan) {
                        $national = $phone;
                        if ($plan['prefix'] !== '' && str_starts_with($national, $plan['prefix'])) $national = substr($national, strlen($plan['prefix']));
                        if (preg_match('~^(?:'.$plan['pattern'].')$~D', $national)) $phone = '+'.$plan['code'].$national;
                    }
                }
                if (! isset($wanted[$phone])) continue;
                $uid = (string) ($row->firebase_uid ?: $row->legacy_firebase_uid ?: $row->id);
                $matches[$uid] = ['uid' => $uid, 'phoneE164' => $phone, 'displayName' => $profile['displayName'] ?? $row->name,
                    'photoURL' => $profile['photoURL'] ?? $profile['avatar'] ?? $row->avatar_url, 'username' => $profile['username'] ?? null];
                break;
            }
        }
        return $this->success(array_values($matches));
    }
}
