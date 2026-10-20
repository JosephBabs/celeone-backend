<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProfile extends Model
{
    protected $fillable = ['user_id', 'username', 'bio', 'phone', 'location', 'legacy_id', 'legacy_collection', 'legacy_path', 'legacy_payload', 'legacy_created_at', 'legacy_updated_at', 'migrated_at', 'migration_checksum'];

    protected function casts(): array
    {
        return ['legacy_payload' => 'array', 'legacy_created_at' => 'datetime', 'legacy_updated_at' => 'datetime', 'migrated_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
