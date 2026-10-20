<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfilePrivacy
{
    private array $profiles = [];

    private ?Request $request = null;

    public function settings(User $user): array
    {
        $row = DB::table('profile_privacy_settings')->where('user_id', $user->id)->first();

        return ['hideEmail' => (bool) ($row?->hide_email ?? false), 'hidePhone' => (bool) ($row?->hide_phone ?? false)];
    }

    public function prime(array $keys): void
    {
        if ($this->request !== request()) {
            $this->profiles = [];
            $this->request = request();
        }
        $keys = array_values(array_diff(array_unique(array_map('strval', $keys)), array_keys($this->profiles)));
        if (! $keys) {
            return;
        }
        foreach ($keys as $key) {
            $this->profiles[$key] = null;
        }
        $ids = array_values(array_filter($keys, fn ($key) => ctype_digit($key) && strlen($key) < 19));
        $users = DB::table('users')->leftJoin('profile_privacy_settings as privacy', 'privacy.user_id', '=', 'users.id')
            ->where(function ($q) use ($keys, $ids) {
                $q->whereIn('users.firebase_uid', $keys)->orWhereIn('users.legacy_firebase_uid', $keys);
                if ($ids) {
                    $q->orWhereIn('users.id', $ids);
                }
            })->select('users.id', 'users.firebase_uid', 'users.legacy_firebase_uid', 'privacy.hide_email', 'privacy.hide_phone')->get();
        foreach ($users as $user) {
            foreach ([$user->id, $user->firebase_uid, $user->legacy_firebase_uid] as $key) {
                if ($key !== null && $key !== '') {
                    $this->profiles[(string) $key] = $user;
                }
            }
        }
    }

    public function redact(array $data, string $key, ?User $viewer): array
    {
        $this->prime([$key]);
        $owner = $this->profiles[$key] ?? null;
        $email = (bool) ($owner?->hide_email ?? false);
        $phone = (bool) ($owner?->hide_phone ?? false);
        $self = $viewer && $owner && (string) $viewer->id === (string) $owner->id;
        $data = $this->contacts($data, $email && ! $self, $phone && ! $self);
        $data['hideEmail'] = $email;
        $data['hidePhone'] = $phone;
        $data['contactDetailsRedacted'] = ! $self;

        return $data;
    }

    private function contacts(array $data, bool $email, bool $phone): array
    {
        if ($email) {
            foreach (['email', 'emailAddress', 'email_address'] as $key) {
                $data[$key] = null;
            }
        }
        if ($phone) {
            foreach (['phone', 'phoneNumber', 'phone_number', 'phoneE164', 'mobile', 'telephone'] as $key) {
                $data[$key] = null;
            }
        }
        foreach (['profile_data', 'legacy_payload', 'legacy_data', 'user_data', 'user', 'profile', 'data', 'contact', 'contacts'] as $key) {
            if (! isset($data[$key])) {
                continue;
            }
            $string = is_string($data[$key]);
            $nested = $string ? json_decode($data[$key], true) : $data[$key];
            if (is_array($nested)) {
                $nested = $this->contacts($nested, $email, $phone);
                $data[$key] = $string ? json_encode($nested) : $nested;
            }
        }

        return $data;
    }
}
