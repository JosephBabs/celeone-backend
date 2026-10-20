<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ImportFirebaseAuth extends Command
{
    protected $signature = 'celeone:import-firebase-auth {file=storage/app/firebase-auth.ndjson} {--dry-run} {--resume} {--batch=250}';
    protected $description = 'Import Firebase Auth accounts while preserving UID, providers and claims.';

    public function handle(): int
    {
        $argument = (string) $this->argument('file');
        $path = Str::startsWith($argument, ['/','\\']) || preg_match('/^[A-Za-z]:[\\\\\/]/', $argument) ? $argument : base_path($argument);
        if (! is_file($path) || filesize($path) === 0) {
            $this->error("Firebase Auth export is missing or empty: {$path}");
            return self::FAILURE;
        }
        $processed = $created = $updated = $conflicts = $skipped = 0;
        $handle = fopen($path, 'rb');
        while (($line = fgets($handle)) !== false) {
            $row = json_decode($line, true);
            $uid = is_array($row) ? (string) ($row['uid'] ?? $row['localId'] ?? '') : '';
            if ($uid === '') continue;
            $processed++;
            $sourcePath = 'firebase_auth/'.$uid;
            $checksum = hash('sha256', $line);
            if (! $this->option('dry-run') && $this->option('resume') && DB::table('migration_ledger')->where('source_path', $sourcePath)->where('source_checksum', $checksum)->where('status', 'processed')->exists()) {
                $skipped++;
                continue;
            }
            $claims = $this->claims($row['customAttributes'] ?? []);
            $claimedRole = $claims['role'] ?? (($claims['super_admin'] ?? false) ? 'super_admin' : (($claims['admin'] ?? false) ? 'admin' : null));
            $role = strtolower((string) ($claimedRole ?? 'user'));
            $role = in_array($role, ['admin', 'super_admin'], true) ? $role : 'user';
            $email = isset($row['email']) && filter_var($row['email'], FILTER_VALIDATE_EMAIL) ? strtolower($row['email']) : null;
            $user = User::where('legacy_firebase_uid', $uid)
                ->orWhere('firebase_uid', $uid)
                ->first();

            /*
             * Firebase may contain an email which is already owned by a
             * different Laravel account. Check this even when the UID already
             * resolves to a Laravel user.
             */
            if ($email) {
                $emailOwner = User::query()
                    ->whereRaw('LOWER(email) = ?', [strtolower($email)])
                    ->first();

                if (
                    $emailOwner
                    && (
                        ! $user
                        || (int) $emailOwner->id !== (int) $user->id
                    )
                ) {
                    $conflicts++;

                    if (! $this->option('dry-run')) {
                        DB::table('migration_conflicts')->updateOrInsert(
                            [
                                'source_path' => $sourcePath,
                                'conflict_type' => 'auth_email_collision',
                            ],
                            [
                                'source_values' => json_encode([
                                    'uid' => $uid,
                                    'email' => $email,
                                    'existing_user_id' => $emailOwner->id,
                                    'firebase_uid_user_id' => $user?->id,
                                ]),
                                'status' => 'open',
                                'updated_at' => now(),
                                'created_at' => now(),
                            ]
                        );
                    }

                    /*
                     * If the Firebase UID already belongs to a Laravel user,
                     * preserve its existing email instead of attempting to
                     * steal another user's unique email.
                     */
                    $email = $user && filter_var(
                        $user->email,
                        FILTER_VALIDATE_EMAIL
                    )
                        ? strtolower((string) $user->email)
                        : null;
                }
            }

            $email ??= $uid.'@firebase.local';
            if ($this->option('dry-run')) continue;
            DB::transaction(function () use ($row, $uid, $sourcePath, $checksum, $claims, $role, $email, &$user, &$created, &$updated): void {
                $wasExisting = (bool) $user;
                $user ??= new User();
                $user->forceFill([
                    'firebase_uid' => $user->firebase_uid ?: $uid, 'legacy_firebase_uid' => $uid,
                    'name' => $row['displayName'] ?? $user->name ?: 'CeleOne user', 'email' => $email,
                    // Firebase Auth exports do not contain a Laravel-compatible hash.
                    // Firebase-token exchange preserves the existing sign-in without a reset.
                    'password' => $user->password ?: Hash::make(Str::random(64)), 'avatar_url' => $row['photoUrl'] ?? $user->avatar_url,
                    'role' => $role, 'email_verified_at' => ($row['emailVerified'] ?? false) ? ($user->email_verified_at ?: now()) : $user->email_verified_at,
                    'firebase_disabled' => (bool) ($row['disabled'] ?? false), 'phone_number' => $row['phoneNumber'] ?? null,
                    'firebase_custom_claims' => $claims, 'firebase_created_at' => $this->parseDate($row['createdAt'] ?? null),
                    'firebase_last_sign_in_at' => $this->parseDate($row['lastLoginAt'] ?? null), 'legacy_collection' => 'firebase_auth',
                    'legacy_path' => $sourcePath, 'legacy_payload' => $row, 'migrated_at' => now(),
                ])->save();
                $wasExisting ? $updated++ : $created++;
                foreach (($row['providerUserInfo'] ?? []) as $provider) {
                    $providerId = (string) ($provider['providerId'] ?? ''); $subject = (string) ($provider['rawId'] ?? '');
                    if ($providerId === '' || $subject === '') continue;
                    DB::table('auth_identities')->updateOrInsert(['provider' => $providerId, 'provider_subject' => $subject], ['user_id' => $user->id, 'claims' => json_encode($provider), 'disabled' => (bool) ($row['disabled'] ?? false), 'last_sign_in_at' => $this->parseDate($row['lastLoginAt'] ?? null), 'legacy_id' => $uid, 'legacy_collection' => 'firebase_auth', 'legacy_path' => $sourcePath, 'legacy_payload' => json_encode($row), 'migration_checksum' => $checksum, 'updated_at' => now(), 'created_at' => now()]);
                }
                DB::table('migration_ledger')->updateOrInsert(['source_path' => $sourcePath, 'source_checksum' => $checksum], ['target_type' => User::class, 'target_id' => (string) $user->id, 'status' => 'processed', 'processed_at' => now(), 'updated_at' => now(), 'created_at' => now()]);
            });
            if ($processed % (int) $this->option('batch') === 0) $this->info("Processed {$processed} auth accounts...");
        }
        fclose($handle);
        $this->info("Firebase Auth accounts: {$processed}; created {$created}; updated {$updated}; conflicts {$conflicts}; skipped {$skipped}.");
        return self::SUCCESS;
    }

    private function claims(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (! is_string($value) || $value === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function parseDate(?string $value): ?string
    {
        if (! $value) return null;
        if (ctype_digit($value)) return \Carbon\Carbon::createFromTimestampMs((int) $value)->utc()->toDateTimeString();
        try { return now()->parse($value)->utc()->toDateTimeString(); } catch (\Throwable) { return null; }
    }
}
