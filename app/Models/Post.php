<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Post extends Model
{
    protected $fillable = ['firebase_id', 'user_id', 'page_id', 'text_content', 'post_type', 'audience', 'status', 'legacy_data'];
    protected $casts = ['legacy_data' => 'array'];
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function page(): BelongsTo { return $this->belongsTo(Page::class); }
    public function comments(): HasMany { return $this->hasMany(Comment::class); }
    public function reactions(): HasMany { return $this->hasMany(Reaction::class); }
    public function media(): HasMany { return $this->hasMany(MediaAsset::class); }

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $key = $field ?: $this->getRouteKeyName();
        return $query->where(fn ($q) => $q->where($key, $value)->orWhere('firebase_id', $value));
    }
}
