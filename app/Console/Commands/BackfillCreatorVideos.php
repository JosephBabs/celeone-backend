<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillCreatorVideos extends Command
{
    protected $signature = 'creator:backfill-videos {--dry-run : Show the reconciliation plan without writing changes}';

    protected $description = 'Copy legacy movie catalog documents to videos while preserving existing video metrics.';

    public function handle(): int
    {
        $movies = DB::table('firebase_documents')
            ->where('collection_path', 'movies')
            ->orderBy('id')
            ->get(['document_id', 'payload', 'firebase_created_at', 'firebase_updated_at', 'created_at', 'updated_at']);

        $copied = 0;
        $merged = 0;
        $skipped = 0;
        $removedLegacy = 0;

        foreach ($movies as $movie) {
            $source = $this->payload($movie->payload);
            $video = DB::table('firebase_documents')
                ->where('collection_path', 'videos')
                ->where('document_id', $movie->document_id)
                ->first(['id', 'payload']);

            if ($video) {
                $target = $this->payload($video->payload);
                $mergedPayload = $this->mergePreservingVideoData($source, $target);
                $relational = DB::table('videos')->where('public_id', $movie->document_id)->exists();
                if ($mergedPayload === $target && $relational) {
                    $skipped++;
                } else {
                    $merged++;
                }

                if (! $this->option('dry-run')) {
                    DB::transaction(function () use ($video, $movie, $mergedPayload): void {
                        if ($mergedPayload !== $this->payload($video->payload)) {
                            $now = now();
                            DB::table('firebase_documents')->where('id', $video->id)->update([
                                'payload' => json_encode($mergedPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                                'firebase_updated_at' => $now,
                                'updated_at' => $now,
                            ]);
                        } else {
                            $now = now();
                        }
                        $this->syncRelationalVideo($movie, $mergedPayload, $now);
                        DB::table('firebase_documents')
                            ->where('collection_path', 'movies')
                            ->where('document_id', $movie->document_id)
                            ->delete();
                    }, 3);
                }

                $removedLegacy++;
                continue;
            }

            $copied++;

            if (! $this->option('dry-run')) {
                DB::transaction(function () use ($movie, $source): void {
                    $video = DB::table('firebase_documents')
                        ->where('collection_path', 'videos')
                        ->where('document_id', $movie->document_id)
                        ->lockForUpdate()
                        ->first(['id', 'payload']);

                    $payload = $video
                        ? $this->mergePreservingVideoData($source, $this->payload($video->payload))
                        : $source;
                    $now = now();

                    if ($video) {
                        DB::table('firebase_documents')->where('id', $video->id)->update([
                            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'firebase_updated_at' => $now,
                            'updated_at' => $now,
                        ]);
                    } else {
                        DB::table('firebase_documents')->insert([
                            'collection_path' => 'videos',
                            'document_id' => $movie->document_id,
                            'parent_document_path' => null,
                            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'firebase_created_at' => $movie->firebase_created_at,
                            'firebase_updated_at' => $now,
                            'created_at' => $movie->created_at,
                            'updated_at' => $now,
                        ]);
                    }

                    $this->syncRelationalVideo($movie, $payload, $now);
                    DB::table('firebase_documents')
                        ->where('collection_path', 'movies')
                        ->where('document_id', $movie->document_id)
                        ->delete();
                }, 3);
            }
            $removedLegacy++;
        }

        $action = $this->option('dry-run') ? 'Would reconcile' : 'Reconciled';
        $this->info("{$action}: {$copied} new videos, {$merged} merged with existing videos, {$skipped} already present, {$removedLegacy} legacy movie duplicates removed.");

        return self::SUCCESS;
    }

    private function mergePreservingVideoData(array $movie, array $video): array
    {
        foreach ($movie as $key => $value) {
            if (! array_key_exists($key, $video) || $video[$key] === null || $video[$key] === '' || $video[$key] === []) {
                $video[$key] = $value;
                continue;
            }

            if (is_array($value) && ! array_is_list($value) && is_array($video[$key]) && ! array_is_list($video[$key])) {
                $video[$key] = $this->mergePreservingVideoData($value, $video[$key]);
            }
        }

        return $video;
    }

    private function payload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (is_object($payload)) {
            return (array) $payload;
        }

        $decoded = json_decode((string) $payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function ownerId(array $payload): ?int
    {
        $owner = trim((string) ($payload['ownerId'] ?? $payload['userId'] ?? ''));

        if ($owner === '' || ! ctype_digit($owner)) {
            return null;
        }

        return DB::table('users')->where('id', (int) $owner)->exists() ? (int) $owner : null;
    }

    private function syncRelationalVideo(object $movie, array $payload, mixed $now): void
    {
        $existing = DB::table('videos')->where('public_id', $movie->document_id)->first();
        $incoming = [
            'user_id' => $this->ownerId($payload),
            'status' => (string) ($payload['status'] ?? 'published'),
            'title' => (string) ($payload['title'] ?? ''),
            'body' => (string) ($payload['description'] ?? ''),
            'data' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        if ($existing) {
            $values = [];
            foreach ($incoming as $key => $value) {
                $current = $existing->{$key} ?? null;
                if ($current === null || $current === '' || $current === '[]' || $current === '{}') {
                    $values[$key] = $value;
                }
            }
            if ($values !== []) {
                $values['updated_at'] = $now;
                DB::table('videos')->where('id', $existing->id)->update($values);
            }

            return;
        }

        DB::table('videos')->insertOrIgnore($incoming + [
            'public_id' => $movie->document_id,
            'created_at' => $movie->created_at ?? $now,
            'updated_at' => $now,
        ]);
    }
}
