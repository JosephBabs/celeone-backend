<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Page extends Model
{
    protected $fillable = ['firebase_id', 'owner_id', 'name', 'description', 'avatar_url', 'status', 'legacy_data'];
    protected $casts = ['legacy_data' => 'array'];
    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_id'); }
    public function followers(): BelongsToMany { return $this->belongsToMany(User::class, 'page_follows')->withPivot('status')->withTimestamps(); }
}
