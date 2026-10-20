<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use ApiResponses;

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    public function confirmPassword(Request $request)
    {
        $data = $request->validate([
            'password' => 'required|string|max:1024',
        ]);

        if (!Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages([
                'password' => ['Incorrect password.'],
            ]);
        }

        return $this->success(['confirmed' => true]);
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => 'nullable|string|max:120',
            'email' => 'required|email|max:190|unique:users,email',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $email = strtolower(trim($data['email']));

        $user = User::create([
            'name' => $data['name'] ?? Str::before($email, '@'),
            'email' => $email,
            'password' => $data['password'],
            'role' => 'user',
        ]);

        return $this->success($this->tokens($user, $request), 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'device_name' => 'nullable|string|max:120',
        ]);

        $email = strtolower(trim($data['email']));

        $user = User::where('email', $email)->first()
            ?? User::whereRaw('lower(email) = ?', [$email])->first();

        if (
            !$user ||
            $user->firebase_disabled ||
            !Hash::check($data['password'], $user->password)
        ) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        return $this->success($this->tokens($user, $request));
    }

    public function logout(Request $request)
    {
        $request->user()?->currentAccessToken()?->delete();

        return $this->success(['logged_out' => true]);
    }

    private function tokens(User $user, Request $request): array
    {
        if (!$user->password_migrated_at) {
            $user->forceFill([
                'password_migrated_at' => now(),
            ])->save();
        }

        $name = (string) (
            $request->input('device_name')
            ?: $request->header('X-Device-ID')
            ?: 'mobile'
        );

        return [
            'user' => $user,
            'password_migrated' => true,
            'access_token' => $user->createToken($name)->plainTextToken,
            'token_type' => 'Bearer',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Current profile
    |--------------------------------------------------------------------------
    */

    public function me(Request $request)
    {
        $user = $request->user();

        return $this->success([
            'user' => $user,
            'user_data' => $this->profileData($user),
        ]);
    }

    /**
     * Find the most recent profile record associated with this user.
     */

    private function profileData(User $user): ?object
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('user_data')) {
            return null;
        }

        $hasUserId = \Illuminate\Support\Facades\Schema::hasColumn(
            'user_data',
            'user_id'
        );

        $hasFirebaseUid = \Illuminate\Support\Facades\Schema::hasColumn(
            'user_data',
            'firebase_uid'
        );

        $query = \Illuminate\Support\Facades\DB::table('user_data');

        if ($hasUserId) {
            $profile = (clone $query)
                ->where('user_id', $user->id)
                ->when(
                    \Illuminate\Support\Facades\Schema::hasColumn('user_data', 'id'),
                    fn($q) => $q->orderByDesc('id')
                )
                ->first();

            if ($profile) {
                return $profile;
            }
        }

        if ($hasFirebaseUid && $user->firebase_uid) {
            return $query
                ->where('firebase_uid', $user->firebase_uid)
                ->when(
                    \Illuminate\Support\Facades\Schema::hasColumn('user_data', 'id'),
                    fn($q) => $q->orderByDesc('id')
                )
                ->first();
        }

        return null;
    }


    /*
    |--------------------------------------------------------------------------
    | General profile update
    |--------------------------------------------------------------------------
    */

    /**
     * PATCH /me/profile
     *
     * Compatible with the currently published app:
     * firstName, lastName, displayName, parish, phone, email, photoURL. 
     */

    /**
     * PATCH /me/profile
     *
     * Updates the same user_data record used by profileData().
     * Compatible with the published Cèlè One app.
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'firstName'   => 'sometimes|required|string|max:120',
            'lastName'    => 'sometimes|required|string|max:120',
            'displayName' => 'sometimes|required|string|max:240',
            'parish'      => 'sometimes|nullable|string|max:190',
            'phone'       => 'sometimes|nullable|string|max:30',
            'email'       => 'sometimes|required|email|max:190',
            'photoURL'    => 'sometimes|nullable|url|max:2048',
        ]);

        if (empty($data)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'profile' => ['No profile fields were provided.'],
            ]);
        }

        if (isset($data['email'])) {
            $data['email'] = strtolower(trim($data['email']));

            // Check the account email for another user.
            $exists = User::query()
                ->whereRaw('LOWER(email) = ?', [$data['email']])
                ->where('id', '<>', $user->id)
                ->exists();

            if ($exists) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'email' => ['This email address is already in use.'],
                ]);
            }
        }

        // API field => actual possible columns in user_data.
        $mapping = [
            'firstName'   => ['firstName', 'first_name'],
            'lastName'    => ['lastName', 'last_name'],
            'displayName' => ['displayName', 'display_name'],
            'parish'      => ['parish'],
            'phone'       => ['phone', 'phone_number'],
            'email'       => ['email'],
            'photoURL'    => ['photoURL', 'photo_url', 'avatar_url', 'avatar'],
        ];

        if (!\Illuminate\Support\Facades\Schema::hasTable('user_data')) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'profile' => ['The user_data table does not exist.'],
            ]);
        }

        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('user_data');
        $updates = [];
        $unmapped = [];

        foreach ($data as $field => $value) {
            $column = null;

            foreach ($mapping[$field] ?? [] as $candidate) {
                if (in_array($candidate, $columns, true)) {
                    $column = $candidate;
                    break;
                }
            }

            if ($column === null) {
                $unmapped[] = $field;
                continue;
            }

            $updates[$column] = $value;
        }

        if ($unmapped) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'profile' => [
                    'No matching user_data column exists for: '
                        . implode(', ', $unmapped),
                ],
            ]);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($user, $updates) {
            $profile = $this->profileData($user);

            if (!$profile) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'profile' => ['No user_data profile was found for this account.'],
                ]);
            }

            $query = \Illuminate\Support\Facades\DB::table('user_data');

            // Update the exact record returned by profileData().
            if (
                \Illuminate\Support\Facades\Schema::hasColumn('user_data', 'id')
                && isset($profile->id)
            ) {
                $query->where('id', $profile->id);
            } elseif (
                \Illuminate\Support\Facades\Schema::hasColumn('user_data', 'user_id')
                && isset($profile->user_id)
            ) {
                $query->where('user_id', $profile->user_id);
            } elseif (
                \Illuminate\Support\Facades\Schema::hasColumn('user_data', 'firebase_uid')
                && isset($profile->firebase_uid)
            ) {
                $query->where('firebase_uid', $profile->firebase_uid);
            } else {
                throw new \RuntimeException(
                    'Cannot uniquely identify the user_data profile record.'
                );
            }

            if (in_array('updated_at', \Illuminate\Support\Facades\Schema::getColumnListing('user_data'), true)) {
                $updates['updated_at'] = now();
            }

            $query->update($updates);
        });

        // Return the same structure expected by the published app.
        return $this->success([
            'message' => 'Profile updated successfully.',
            'user' => $user->fresh(),
            'user_data' => $this->profileData($user),
        ]);
    }


    /**
     * Locate the existing legacy profile record for this authenticated user.
     */
    private function getUserData(User $user): ?object
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('user_data')) {
            return null;
        }

        $hasUserId = \Illuminate\Support\Facades\Schema::hasColumn(
            'user_data',
            'user_id'
        );

        $hasFirebaseUid = \Illuminate\Support\Facades\Schema::hasColumn(
            'user_data',
            'firebase_uid'
        );

        if (!$hasUserId && (!$hasFirebaseUid || !$user->firebase_uid)) {
            return null;
        }

        $query = \Illuminate\Support\Facades\DB::table('user_data');

        $query->where(function ($q) use ($user, $hasUserId, $hasFirebaseUid) {
            if ($hasUserId) {
                $q->where('user_id', $user->id);
            }

            if ($hasFirebaseUid && $user->firebase_uid) {
                if ($hasUserId) {
                    $q->orWhere('firebase_uid', $user->firebase_uid);
                } else {
                    $q->where('firebase_uid', $user->firebase_uid);
                }
            }
        });

        if (\Illuminate\Support\Facades\Schema::hasColumn('user_data', 'id')) {
            $query->orderByDesc('id');
        }

        return $query->first();
    }

    /**
     * Update the matching user_data record, or create one when necessary.
     */
    private function updateUserDataRow(User $user, array $updates): void
    {
        $schema = \Illuminate\Support\Facades\Schema::class;
        $db = \Illuminate\Support\Facades\DB::class;

        if (!$schema::hasTable('user_data')) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'profile' => ['The user_data table does not exist.'],
            ]);
        }

        $hasUserId = $schema::hasColumn('user_data', 'user_id');
        $hasFirebaseUid = $schema::hasColumn('user_data', 'firebase_uid');

        if (!$hasUserId && (!$hasFirebaseUid || !$user->firebase_uid)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'profile' => ['Cannot associate the profile with the authenticated user.'],
            ]);
        }

        $query = $db::table('user_data');

        $query->where(function ($q) use ($user, $hasUserId, $hasFirebaseUid) {
            if ($hasUserId) {
                $q->where('user_id', $user->id);
            }

            if ($hasFirebaseUid && $user->firebase_uid) {
                if ($hasUserId) {
                    $q->orWhere('firebase_uid', $user->firebase_uid);
                } else {
                    $q->where('firebase_uid', $user->firebase_uid);
                }
            }
        });

        if ($schema::hasColumn('user_data', 'id')) {
            $query->orderByDesc('id');
        }

        $existing = $query->first();

        if ($schema::hasColumn('user_data', 'updated_at')) {
            $updates['updated_at'] = now();
        }

        if ($existing) {
            $db::table('user_data')
                ->where('id', $existing->id)
                ->update($updates);

            return;
        }

        if ($hasUserId) {
            $updates['user_id'] = $user->id;
        }

        if ($hasFirebaseUid && $user->firebase_uid) {
            $updates['firebase_uid'] = $user->firebase_uid;
        }

        if ($schema::hasColumn('user_data', 'created_at')) {
            $updates['created_at'] = now();
        }

        $db::table('user_data')->insert($updates);
    }

    /*
    |--------------------------------------------------------------------------
    | Avatar and cover
    |--------------------------------------------------------------------------
    */

    public function updateAvatar(Request $request)
    {
        $request->validate([
            'avatar' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        return $this->updateProfileImage($request, 'avatar');
    }

    public function updateCover(Request $request)
    {
        $request->validate([
            'cover' => 'required|image|mimes:jpg,jpeg,png,webp|max:10240',
        ]);

        return $this->updateProfileImage($request, 'cover');
    }

    private function updateProfileImage(Request $request, string $field)
    {
        $user = $request->user();

        $file = $request->file($field);
        $path = $file->store("profiles/{$user->id}", 'public');

        $oldPath = $this->getProfileValue($user, $field);

        try {
            $this->saveProfileFields($user, [
                $field => $path,
            ]);
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($path);
            throw $e;
        }

        if (
            is_string($oldPath) &&
            $oldPath !== '' &&
            !Str::startsWith($oldPath, ['http://', 'https://'])
        ) {
            Storage::disk('public')->delete($oldPath);
        }

        return $this->profileResponse(
            $user,
            ucfirst($field) . ' updated successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Password
    |--------------------------------------------------------------------------
    */

    public function updatePassword(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => 'required|string|max:1024',
            'password' => 'required|string|min:8|confirmed|max:1024',
        ]);

        if (!Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($data['password']),
        ])->save();

        return $this->success([
            'message' => 'Password updated successfully.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Email, phone and username
    |--------------------------------------------------------------------------
    */

    public function updateEmail(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'email' => [
                'required',
                'email',
                'max:190',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'current_password' => 'required|string|max:1024',
        ]);

        if (!Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $email = strtolower(trim($data['email']));

        $user->forceFill(['email' => $email])->save();

        /*
         * If your application has email-verification columns,
         * clear the verified timestamp here and send a verification email.
         */

        return $this->profileResponse($user, 'Email updated successfully.');
    }

    public function updatePhone(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'phone' => 'required|string|max:30',
        ]);

        $this->assertUniqueIfColumnExists(
            'users',
            'phone',
            $data['phone'],
            $user->id
        );

        $this->saveProfileFields($user, $data);

        return $this->profileResponse($user, 'Phone updated successfully.');
    }

    public function updateUsername(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'username' => [
                'required',
                'string',
                'min:3',
                'max:30',
                'regex:/^[A-Za-z0-9_.]+$/',
                Rule::unique('users', 'username')->ignore($user->id),
            ],
        ]);

        $data['username'] = strtolower($data['username']);

        $this->saveProfileFields($user, $data);

        return $this->profileResponse($user, 'Username updated successfully.');
    }

    public function updateBio(Request $request)
    {
        $data = $request->validate([
            'bio' => 'present|nullable|string|max:1000',
        ]);

        $this->saveProfileFields($request->user(), $data);

        return $this->profileResponse($request->user(), 'Bio updated successfully.');
    }

    public function updateLocation(Request $request)
    {
        $data = $request->validate([
            'location' => 'present|nullable|string|max:190',
        ]);

        $this->saveProfileFields($request->user(), $data);

        return $this->profileResponse($request->user(), 'Location updated successfully.');
    }

    public function updateBirthdate(Request $request)
    {
        $data = $request->validate([
            'birthdate' => 'present|nullable|date|before:today',
        ]);

        $this->saveProfileFields($request->user(), $data);

        return $this->profileResponse($request->user(), 'Birthdate updated successfully.');
    }

    public function updateGender(Request $request)
    {
        $data = $request->validate([
            'gender' => [
                'present',
                'nullable',
                Rule::in(['male', 'female', 'other', 'prefer_not_to_say']),
            ],
        ]);

        $this->saveProfileFields($request->user(), $data);

        return $this->profileResponse($request->user(), 'Gender updated successfully.');
    }

    /*
    |--------------------------------------------------------------------------
    | Interests, notifications and privacy
    |--------------------------------------------------------------------------
    */

    public function updateInterests(Request $request)
    {
        $data = $request->validate([
            'interests' => 'required|array|max:100',
            'interests.*' => 'string|max:100',
        ]);

        $this->saveProfileFields($request->user(), $data);

        return $this->profileResponse($request->user(), 'Interests updated successfully.');
    }

    public function updateNotifications(Request $request)
    {
        $data = $request->validate([
            'notifications' => 'required|array',
            'notifications.*' => 'boolean',
        ]);

        $this->saveProfileFields($request->user(), $data);

        return $this->profileResponse(
            $request->user(),
            'Notification preferences updated successfully.'
        );
    }

    public function updatePrivacy(Request $request)
    {
        $data = $request->validate([
            'privacy' => 'required|array',
            'privacy.*' => [
                'string',
                Rule::in(['public', 'friends', 'private', 'followers']),
            ],
        ]);

        $this->saveProfileFields($request->user(), $data);

        return $this->profileResponse($request->user(), 'Privacy settings updated successfully.');
    }

    /*
    |--------------------------------------------------------------------------
    | Connected and linked accounts
    |--------------------------------------------------------------------------
    */

    public function updateConnectedAccounts(Request $request)
    {
        $data = $request->validate([
            'connected_accounts' => 'required|array|max:20',
        ]);

        /*
         * Do not trust client-supplied verification status or provider IDs.
         * Add provider-specific validation and OAuth verification before
         * allowing an account to be marked as connected.
         */

        $this->saveProfileFields($request->user(), $data);

        return $this->profileResponse(
            $request->user(),
            'Connected accounts updated successfully.'
        );
    }

    public function updateLinkedAccounts(Request $request)
    {
        $data = $request->validate([
            'linked_accounts' => 'required|array|max:20',
        ]);

        $this->saveProfileFields($request->user(), $data);

        return $this->profileResponse(
            $request->user(),
            'Linked accounts updated successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Blocked users and hidden content
    |--------------------------------------------------------------------------
    */

    public function updateBlockedUsers(Request $request)
    {
        $data = $request->validate([
            'blocked_users' => 'required|array|max:500',
            'blocked_users.*' => 'integer|distinct|exists:users,id',
        ]);

        $userId = $request->user()->id;

        if (in_array($userId, $data['blocked_users'], true)) {
            throw ValidationException::withMessages([
                'blocked_users' => ['You cannot block your own account.'],
            ]);
        }

        $this->saveProfileFields($request->user(), $data);

        return $this->profileResponse(
            $request->user(),
            'Blocked users updated successfully.'
        );
    }

    public function updateHiddenContent(Request $request)
    {
        $data = $request->validate([
            'hidden_content' => 'required|array|max:500',
        ]);

        $this->saveProfileFields($request->user(), $data);

        return $this->profileResponse(
            $request->user(),
            'Hidden content updated successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Protected account statuses
    |--------------------------------------------------------------------------
    */

    public function updateVerifiedStatus(Request $request)
    {
        /*
         * Verification must be granted by a trusted verification workflow,
         * not by a normal authenticated user's PATCH request.
         */
        abort(403, 'Verified status can only be changed by an authorized verification workflow.');
    }

    public function updateCreatorStatus(Request $request)
    {
        /*
         * Creator approval must be managed by the creator review workflow.
         */
        abort(403, 'Creator status can only be changed by an authorized review workflow.');
    }

    /*
    |--------------------------------------------------------------------------
    | Persistence helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Store each field in users when that column exists; otherwise store
     * it in user_data when the corresponding column exists there.
     *
     * This allows the controller to tolerate different schemas, but it
     * does not replace proper database migrations.
     */
    private function saveProfileFields(User $user, array $fields): void
    {
        $userUpdates = [];
        $profileUpdates = [];

        foreach ($fields as $field => $value) {
            if (
                Schema::hasTable('users') &&
                Schema::hasColumn('users', $field)
            ) {
                $userUpdates[$field] = $value;
            } elseif (
                Schema::hasTable('user_data') &&
                Schema::hasColumn('user_data', $field)
            ) {
                $profileUpdates[$field] = $value;
            } else {
                throw ValidationException::withMessages([
                    $field => [
                        "The profile field '{$field}' is not configured in the database schema.",
                    ],
                ]);
            }
        }

        DB::transaction(function () use (
            $user,
            $userUpdates,
            $profileUpdates
        ) {
            if ($userUpdates) {
                /*
                 * Query Builder avoids mass-assignment problems, but only
                 * validated fields passed by this controller are written.
                 */
                DB::table('users')
                    ->where('id', $user->id)
                    ->update($this->databaseValues($userUpdates));

                $user->refresh();
            }

            if ($profileUpdates) {
                $this->writeUserData($user, $profileUpdates);
            }
        });
    }

    private function writeUserData(User $user, array $fields): void
    {
        if (!Schema::hasTable('user_data')) {
            throw ValidationException::withMessages([
                'profile' => ['The user_data table does not exist.'],
            ]);
        }

        $query = DB::table('user_data');

        if (Schema::hasColumn('user_data', 'user_id')) {
            $query->where('user_id', $user->id);
        } elseif (
            $user->firebase_uid &&
            Schema::hasColumn('user_data', 'firebase_uid')
        ) {
            $query->where('firebase_uid', $user->firebase_uid);
        } else {
            throw ValidationException::withMessages([
                'profile' => [
                    'user_data must contain user_id or firebase_uid.',
                ],
            ]);
        }

        if (Schema::hasColumn('user_data', 'id')) {
            $query->orderByDesc('id');
        }

        $existing = $query->first();

        $values = $this->databaseValues($fields);

        if ($existing) {
            DB::table('user_data')
                ->where('id', $existing->id)
                ->update($values);
        } else {
            if (Schema::hasColumn('user_data', 'user_id')) {
                $values['user_id'] = $user->id;
            }

            if (
                $user->firebase_uid &&
                Schema::hasColumn('user_data', 'firebase_uid')
            ) {
                $values['firebase_uid'] = $user->firebase_uid;
            }

            if (Schema::hasColumn('user_data', 'created_at')) {
                $values['created_at'] = now();
            }

            if (Schema::hasColumn('user_data', 'updated_at')) {
                $values['updated_at'] = now();
            }

            DB::table('user_data')->insert($values);
        }
    }

    /**
     * Convert PHP arrays to JSON for Query Builder writes.
     */
    private function databaseValues(array $fields): array
    {
        $values = [];

        foreach ($fields as $key => $value) {
            $values[$key] = is_array($value)
                ? json_encode($value, JSON_THROW_ON_ERROR)
                : $value;
        }

        if (Schema::hasTable('users')) {
            if (Schema::hasColumn('users', 'updated_at')) {
                $values['updated_at'] = now();
            }
        }

        return $values;
    }

    private function assertUniqueIfColumnExists(
        string $table,
        string $column,
        ?string $value,
        int|string $userId
    ): void {
        if (
            $value !== null &&
            Schema::hasColumn($table, $column) &&
            DB::table($table)
            ->where($column, $value)
            ->where('id', '<>', $userId)
            ->exists()
        ) {
            throw ValidationException::withMessages([
                $column => ['This value is already in use.'],
            ]);
        }
    }

    private function getProfileValue(User $user, string $field): mixed
    {
        if (Schema::hasColumn('users', $field)) {
            return $user->getAttribute($field);
        }

        $profile = $this->profileData($user);

        return $profile->{$field} ?? null;
    }

    private function profileResponse(User $user, string $message)
    {
        $user->refresh();

        return $this->success([
            'message' => $message,
            'user' => $user,
            'user_data' => $this->profileData($user),
        ]);
    }
}
