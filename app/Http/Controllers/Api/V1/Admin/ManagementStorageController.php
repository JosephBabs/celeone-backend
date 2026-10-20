<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\URL;

class ManagementStorageController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'file' => ['required','file','max:5242880'],
            'folder' => ['nullable','string','max:500'],
            'collection' => ['nullable','string','max:191'],
            'entityId' => ['nullable','string','max:191'],
            'field' => ['nullable','string','max:191'],
            'visibility' => ['nullable','in:public,private'],
        ]);

        $file = $request->file('file');
        $visibility = $request->input('visibility', 'public');
        $diskName = $visibility === 'private'
            ? config('management.private_upload_disk', 'local')
            : config('management.upload_disk', 'public');

        $folder = trim((string) $request->input('folder', 'admin'), '/');
        $folder = preg_replace('#[^a-zA-Z0-9/_-]+#', '_', $folder) ?: 'admin';
        $name = now()->format('YmdHis') . '-' . Str::random(8) . '-' .
            preg_replace('/[^a-zA-Z0-9._-]+/', '_', $file->getClientOriginalName());

        $path = Storage::disk($diskName)->putFileAs($folder, $file, $name);
        $url = $visibility === 'public'
            ? Storage::disk($diskName)->url($path)
            : null;

        $assetId = null;
        if (Schema::hasTable('media_assets')) {
            $assetId = DB::table('media_assets')->insertGetId([
                'name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType(),
                'kind' => $this->kind((string) $file->getMimeType()),
                'size' => $file->getSize(),
                'url' => $visibility === 'public' ? $url : null,
                'cdn_url' => $visibility === 'public' ? $url : null,
                'thumbnail_url' => null,
                'storage_path' => $diskName . ':' . $path,
                'visibility' => $visibility,
                'collection_name' => $request->input('collection'),
                'entity_id' => $request->input('entityId'),
                'field_name' => $request->input('field'),
                'uploaded_by' => $request->user()->id,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($visibility === 'private' && $assetId) {
            $url = URL::temporarySignedRoute(
                'management.media.download',
                now()->addMinutes(10),
                ['asset' => $assetId]
            );
        }

        return response()->json(['data' => [
            'id' => $assetId,
            'provider' => 'laravel',
            'name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'path' => $diskName . ':' . $path,
            'storagePath' => $diskName . ':' . $path,
            'url' => $url,
            'cdnUrl' => $url,
            'downloadUrl' => $url,
            'visibility' => $visibility,
        ]], 201);
    }

    public function index(Request $request)
    {
        $limit = min(1000, max(1, (int) $request->query('limit', 250)));
        if (! Schema::hasTable('media_assets')) return response()->json(['data' => []]);

        $rows = DB::table('media_assets')->orderByDesc('id')->limit($limit)->get()->map(
            fn ($row) => [
                'id' => $row->id,
                'name' => $row->name,
                'mime' => $row->mime,
                'kind' => $row->kind,
                'size' => $row->size,
                'url' => $row->url ?: URL::temporarySignedRoute(
                    'management.media.download',
                    now()->addMinutes(10),
                    ['asset' => $row->id]
                ),
                'cdnUrl' => $row->cdn_url,
                'thumbnailUrl' => $row->thumbnail_url,
                'storagePath' => $row->storage_path,
                'visibility' => $row->visibility,
                'collection' => $row->collection_name,
                'entityId' => $row->entity_id,
                'field' => $row->field_name,
                'status' => $row->status,
                'createdAt' => $row->created_at,
            ]
        );

        return response()->json(['data' => $rows]);
    }

    public function destroy(Request $request)
    {
        $storagePath = trim((string) $request->query('path', ''));
        abort_unless($storagePath !== '', 422, 'path is required.');

        $row = Schema::hasTable('media_assets')
            ? DB::table('media_assets')->where('storage_path', $storagePath)->first()
            : null;

        [$disk, $path] = $this->splitStoragePath($storagePath);
        Storage::disk($disk)->delete($path);

        if ($row) DB::table('media_assets')->where('id', $row->id)->delete();

        return response()->json(['data' => ['deleted' => true, 'path' => $storagePath]]);
    }

    public function download(Request $request, int $asset)
    {
        $row = DB::table('media_assets')->where('id', $asset)->first();
        abort_unless($row, 404);
        [$disk, $path] = $this->splitStoragePath($row->storage_path);

        if ($row->visibility === 'public') {
            return redirect(Storage::disk($disk)->url($path));
        }

        return Storage::disk($disk)->download($path, $row->name);
    }

    private function splitStoragePath(string $value): array
    {
        if (str_contains($value, ':')) {
            [$disk, $path] = explode(':', $value, 2);
            return [$disk ?: config('management.upload_disk', 'public'), ltrim($path, '/')];
        }
        return [config('management.upload_disk', 'public'), ltrim($value, '/')];
    }

    private function kind(string $mime): string
    {
        if (str_starts_with($mime, 'image/')) return 'image';
        if (str_starts_with($mime, 'video/')) return 'video';
        if (str_starts_with($mime, 'audio/')) return 'audio';
        if (str_contains($mime, 'pdf') || str_starts_with($mime, 'text/')) return 'document';
        return 'file';
    }

}
