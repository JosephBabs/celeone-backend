<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaAsset extends Model
{
    protected $fillable = ['user_id', 'post_id', 'type', 'url', 'firebase_storage_path', 'mime_type', 'size_bytes', 'metadata'];
    protected $casts = ['metadata' => 'array'];
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function post(): BelongsTo { return $this->belongsTo(Post::class); }
}
