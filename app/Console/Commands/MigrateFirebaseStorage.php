<?php

namespace App\Console\Commands;

use App\Models\Post;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MigrateFirebaseStorage extends Command
{
    protected $signature = 'firebase:migrate-storage {--limit=100} {--dry-run}';
    protected $description = 'Copy legacy Firebase media to the local public disk without loading files into memory.';

    public function handle(): int
    {
        $posts = Post::where(function ($query): void { $query->where('legacy_data->mediaUrl', 'like', '%googleapis.com%')->orWhere('legacy_data->media_url', 'like', '%googleapis.com%'); })->limit((int) $this->option('limit'))->get();
        foreach ($posts as $post) {
            $url = $post->legacy_data['mediaUrl'] ?? $post->legacy_data['media_url'] ?? null;
            if (! $url) continue;
            if ($this->option('dry-run')) { $this->line("Would migrate post {$post->id}"); continue; }
            $response = Http::timeout(60)->sink($tmp = storage_path('app/'.Str::uuid().'.tmp'))->get($url);
            if (! $response->successful()) { $this->warn("Failed post {$post->id}: HTTP {$response->status()}"); @unlink($tmp); continue; }
            $extension = pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION) ?: 'bin';
            $path = 'uploads/migrated/'.Str::uuid().'.'.$extension;
            Storage::disk('public')->put($path, fopen($tmp, 'rb'));
            @unlink($tmp);
            $legacy = $post->legacy_data ?? [];
            $legacy['firebaseMediaUrl'] = $url;
            $post->update(['legacy_data' => $legacy]);
            $this->info("Migrated post {$post->id} to ".Storage::disk('public')->url($path));
        }
        return self::SUCCESS;
    }
}
