<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'firebase_uid', 'legacy_firebase_uid', 'name', 'email', 'password', 'google_id', 'avatar_url', 'role', 'legacy_data', 'legacy_payload', 'legacy_collection', 'legacy_path', 'legacy_created_at', 'legacy_updated_at', 'migrated_at', 'firebase_disabled', 'phone_number', 'firebase_custom_claims', 'firebase_created_at', 'firebase_last_sign_in_at',
    ];

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(Page::class, 'owner_id');
    }

    public function followedPages(): BelongsToMany
    {
        return $this->belongsToMany(Page::class, 'page_follows')->withPivot('status')->withTimestamps();
    }

    public function isAdmin(): bool
    {
        return in_array(strtolower((string) $this->role), ['admin', 'super_admin'], true);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'legacy_data' => 'array',
            'legacy_payload' => 'array',
            'legacy_created_at' => 'datetime',
            'legacy_updated_at' => 'datetime',
            'migrated_at' => 'datetime',
            'password_migrated_at' => 'datetime',
            'firebase_disabled' => 'boolean',
            'firebase_custom_claims' => 'array',
            'firebase_created_at' => 'datetime',
            'firebase_last_sign_in_at' => 'datetime',
        ];
    }
}
