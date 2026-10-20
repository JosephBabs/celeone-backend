<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaUploadController extends Controller
{
    use ApiResponses;

    public function direct(Request $request)
    {
        $data = $request->validate([
            'file' => 'required|file|max:1228800',

            'storage_folder' => [
                'nullable',
                'string',
                'max:160',
                'regex:/^[A-Za-z0-9_\/-]+$/',
            ],

            'purpose' => [
                'nullable',
                'string',
                'max:190',
            ],
        ]);

        $file = $data['file'];

        $folder = trim(
            (string) (
                $data['storage_folder']
                ?? ''
            ),
            '/'
        );

        abort_if(
            str_contains($folder, '..')
            || str_contains($folder, '\\'),
            422,
            'Invalid storage folder.'
        );

        /*
         * Compatibility with old/new status clients.
         */
        $folder = match ($folder) {
            'status',
            'statuses' =>
                'social/statuses',

            'status/image',
            'status/images',
            'statuses/image',
            'statuses/images' =>
                'social/statuses/images',

            'status/video',
            'status/videos',
            'statuses/video',
            'statuses/videos' =>
                'social/statuses/videos',

            'status/thumbnail',
            'status/thumbnails',
            'statuses/thumbnail',
            'statuses/thumbnails' =>
                'social/statuses/thumbnails',

            default =>
                $folder,
        };

        /*
         * Choose a sensible default folder by MIME
         * when the mobile client doesn't send one.
         */
        if ($folder === '') {
            $mime = strtolower(
                (string) $file->getMimeType()
            );

            $purpose = strtolower(
                trim(
                    (string) (
                        $data['purpose']
                        ?? ''
                    )
                )
            );

            if (
                str_contains($purpose, 'status')
            ) {
                if (
                    str_starts_with(
                        $mime,
                        'image/'
                    )
                ) {
                    $folder =
                        'social/statuses/images';
                } elseif (
                    str_starts_with(
                        $mime,
                        'video/'
                    )
                ) {
                    $folder =
                        'social/statuses/videos';
                } else {
                    $folder =
                        'social/statuses/files';
                }
            } else {
                $folder =
                    str_starts_with(
                        $mime,
                        'image/'
                    )
                        ? 'chat/images'
                        : (
                            str_starts_with(
                                $mime,
                                'video/'
                            )
                                ? 'chat/videos'
                                : (
                                    str_starts_with(
                                        $mime,
                                        'audio/'
                                    )
                                        ? 'chat/audio'
                                        : 'chat/files'
                                )
                        );
            }
        }

        /*
         * Application-owned namespaces only.
         */
        $allowedRoots = [
            'chat/',
            'social/',
            'streaming/',
            'profiles/',
            'uploads/',
        ];

        $allowed = collect(
            $allowedRoots
        )->contains(
            fn ($root) =>
                $folder
                    === rtrim(
                        $root,
                        '/'
                    )
                || str_starts_with(
                    $folder,
                    $root
                )
        );

        abort_unless(
            $allowed,
            422,
            'Unsupported storage folder.'
        );

        $extension = strtolower(
            (string) $file
                ->getClientOriginalExtension()
        );

        if ($extension === '') {
            $extension = match (
                strtolower(
                    (string) $file
                        ->getMimeType()
                )
            ) {
                'audio/mp4',
                'audio/m4a',
                'audio/x-m4a' =>
                    'm4a',

                'audio/mpeg' =>
                    'mp3',

                'video/mp4' =>
                    'mp4',

                'video/quicktime' =>
                    'mov',

                'image/jpeg' =>
                    'jpg',

                'image/png' =>
                    'png',

                'image/webp' =>
                    'webp',

                'image/heic',
                'image/heif' =>
                    'heic',

                'application/pdf' =>
                    'pdf',

                default =>
                    'bin',
            };
        }

        $safeExtension = preg_replace(
            '/[^a-z0-9]/i',
            '',
            $extension
        );

        $name =
            (string) Str::uuid()
            . (
                $safeExtension
                    ? '.' . $safeExtension
                    : ''
            );

        /*
         * Always use the same path layout:
         *
         * uploads/{folder}/{user_id}/YYYY/MM/file
         */
        $path =
            'uploads/'
            . trim($folder, '/')
            . '/'
            . $request->user()->id
            . '/'
            . date('Y/m')
            . '/'
            . $name;

        /*
         * IMPORTANT:
         * Use one single media disk everywhere.
         */
        $diskName =
            $this->mediaDiskName();

        $disk =
            Storage::disk(
                $diskName
            );

        $stream = fopen(
            $file->getRealPath(),
            'r'
        );

        abort_unless(
            $stream !== false,
            422,
            'Unable to read uploaded file.'
        );

        try {
            $stored = $disk->put(
                $path,
                $stream,
                [
                    'visibility' =>
                        'public',

                    'ContentType' =>
                        $file->getMimeType()
                        ?: 'application/octet-stream',
                ]
            );
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        abort_unless(
            $stored,
            500,
            'Unable to store uploaded file.'
        );

        $url =
            $this->publicUrl(
                $path
            );

        return $this->success([
            'url' =>
                $url,

            'public_url' =>
                $url,

            'path' =>
                $path,

            'storage_path' =>
                $path,

            'mime' =>
                $file->getMimeType()
                ?: 'application/octet-stream',

            'mime_type' =>
                $file->getMimeType()
                ?: 'application/octet-stream',

            'size' =>
                (int) $file->getSize(),

            'size_bytes' =>
                (int) $file->getSize(),

            'name' =>
                $file->getClientOriginalName(),

            'file_name' =>
                $file->getClientOriginalName(),

            'provider' =>
                'vps',

            'disk' =>
                $diskName,
        ], 201);
    }

    public function presign(
        Request $request
    ) {
        /*
         * Multipart mode:
         * directly upload to VPS storage.
         */
        if (
            $request->hasFile('file')
        ) {
            return $this->direct(
                $request
            );
        }

        /*
         * JSON compatibility mode.
         */
        $request->merge([
            'file_name' =>
                $request->input(
                    'file_name',
                    $request->input(
                        'filename'
                    )
                ),

            'mime_type' =>
                $request->input(
                    'mime_type',
                    $request->input(
                        'content_type'
                    )
                ),

            'size_bytes' =>
                $request->input(
                    'size_bytes',
                    $request->input(
                        'size'
                    )
                ),
        ]);

        $data = $request->validate([
            'file_name' =>
                'required|string|max:190',

            'mime_type' =>
                'required|string|max:120',

            'size_bytes' =>
                'required|integer|min:1|max:62914560',

            'storage_folder' => [
                'nullable',
                'string',
                'max:160',
                'regex:/^[A-Za-z0-9_\/-]+$/',
            ],

            'purpose' =>
                'nullable|string|max:190',
        ]);

        $id =
            (string) Str::ulid();

        $extension = pathinfo(
            $data['file_name'],
            PATHINFO_EXTENSION
        );

        $folder = trim(
            (string) (
                $data['storage_folder']
                ?? ''
            ),
            '/'
        );

        $folder = match ($folder) {
            'status',
            'statuses' =>
                'social/statuses',

            'status/image',
            'status/images',
            'statuses/image',
            'statuses/images' =>
                'social/statuses/images',

            'status/video',
            'status/videos',
            'statuses/video',
            'statuses/videos' =>
                'social/statuses/videos',

            'status/thumbnail',
            'status/thumbnails',
            'statuses/thumbnail',
            'statuses/thumbnails' =>
                'social/statuses/thumbnails',

            default =>
                $folder,
        };

        if ($folder === '') {
            $mime = strtolower(
                (string) $data['mime_type']
            );

            $purpose = strtolower(
                trim(
                    (string) (
                        $data['purpose']
                        ?? ''
                    )
                )
            );

            if (
                str_contains(
                    $purpose,
                    'status'
                )
            ) {
                $folder =
                    str_starts_with(
                        $mime,
                        'video/'
                    )
                        ? 'social/statuses/videos'
                        : 'social/statuses/images';
            } else {
                $folder =
                    'uploads/files';
            }
        }

        $path =
            'uploads/'
            . trim($folder, '/')
            . '/'
            . $request->user()->id
            . '/'
            . date('Y/m')
            . '/'
            . $id
            . (
                $extension
                    ? '.'
                        . preg_replace(
                            '/[^a-z0-9]/i',
                            '',
                            $extension
                        )
                    : ''
            );

        $disk =
            Storage::disk(
                $this->mediaDiskName()
            );

        abort_unless(
            method_exists(
                $disk,
                'temporaryUploadUrl'
            ),
            501,
            'Configured storage does not support signed uploads.'
        );

        $signed =
            $disk->temporaryUploadUrl(
                $path,
                now()->addMinutes(15),
                [
                    'ContentType' =>
                        $data['mime_type'],
                ]
            );

        $url = is_array($signed)
            ? ($signed['url'] ?? null)
            : $signed;

        $headers = is_array($signed)
            ? ($signed['headers'] ?? [])
            : [];

        DB::table('upload_sessions')
            ->insert([
                'public_id' =>
                    $id,

                'user_id' =>
                    $request->user()->id,

                'status' =>
                    'pending',

                'title' =>
                    $data['file_name'],

                'data' =>
                    json_encode([
                        'path' =>
                            $path,

                        'mime_type' =>
                            $data['mime_type'],

                        'size_bytes' =>
                            $data['size_bytes'],

                        'disk' =>
                            $this->mediaDiskName(),
                    ]),

                'created_at' =>
                    now(),

                'updated_at' =>
                    now(),
            ]);

        return $this->success([
            'id' =>
                $id,

            'upload_id' =>
                $id,

            'path' =>
                $path,

            'storage_path' =>
                $path,

            'method' =>
                'PUT',

            'upload_url' =>
                $url,

            'headers' =>
                $headers,

            'upload' => [
                'url' =>
                    $url,

                'headers' =>
                    $headers,

                'method' =>
                    'PUT',
            ],

            'public_url' =>
                $this->publicUrl(
                    $path
                ),
        ]);
    }

    public function complete(
        Request $request,
        string $id
    ) {
        $row =
            DB::table('upload_sessions')
                ->where([
                    'public_id' =>
                        $id,

                    'user_id' =>
                        $request->user()->id,
                ])
                ->first();

        abort_unless(
            $row,
            404,
            'Upload session not found.'
        );

        $asset =
            json_decode(
                (string) $row->data,
                true
            )
            ?: [];

        $path =
            (string) (
                $asset['path']
                ?? $request->input(
                    'path',
                    ''
                )
            );

        abort_unless(
            $path !== '',
            422,
            'Upload path missing.'
        );

        /*
         * Confirm file presence whenever the
         * configured disk supports it.
         */
        $disk =
            Storage::disk(
                $this->mediaDiskName()
            );

        if (
            method_exists(
                $disk,
                'exists'
            )
        ) {
            abort_unless(
                $disk->exists($path),
                422,
                'Uploaded file was not found.'
            );
        }

        DB::table('upload_sessions')
            ->where(
                'id',
                $row->id
            )
            ->update([
                'status' =>
                    'complete',

                'updated_at' =>
                    now(),
            ]);

        $url =
            $this->publicUrl(
                $path
            );

        return $this->success([
            'id' =>
                $id,

            'upload_id' =>
                $id,

            'status' =>
                'complete',

            'path' =>
                $path,

            'storage_path' =>
                $path,

            'url' =>
                $url,

            'public_url' =>
                $url,

            'asset' =>
                array_merge(
                    $asset,
                    [
                        'url' =>
                            $url,

                        'public_url' =>
                            $url,
                    ]
                ),
        ]);
    }

    public function url(
        Request $request
    ) {
        $path =
            $this->authorizedPath(
                $request,
                (string) $request->query(
                    'path',
                    ''
                )
            );

        abort_unless(
            $path !== '',
            422,
            'path is required.'
        );

        return $this->success([
            'path' =>
                $path,

            'url' =>
                $this->publicUrl(
                    $path
                ),
        ]);
    }

    public function destroy(
        Request $request
    ) {
        $path =
            $this->authorizedPath(
                $request,
                (string) $request->query(
                    'path',
                    ''
                )
            );

        abort_unless(
            $path !== '',
            422,
            'path is required.'
        );

        Storage::disk(
            $this->mediaDiskName()
        )->delete(
            $path
        );

        return $this->success([
            'deleted' =>
                true,

            'path' =>
                $path,
        ]);
    }

    private function authorizedPath(
        Request $request,
        string $path
    ): string {
        $path = ltrim(
            rawurldecode($path),
            '/'
        );

        abort_if(
            $path === ''
            || Str::contains(
                $path,
                ['..', '\\']
            ),
            422,
            'Invalid upload path.'
        );

        if (
            $request->user()->isAdmin()
        ) {
            return $path;
        }

        $userId =
            (string) $request
                ->user()
                ->id;

        /*
         * Native presigned path:
         *
         * uploads/{userId}/...
         */
        $ownedRoot =
            Str::startsWith(
                $path,
                'uploads/'
                . $userId
                . '/'
            );

        /*
         * Direct VPS path:
         *
         * uploads/social/statuses/images/{userId}/...
         * uploads/chat/images/{userId}/...
         * etc.
         */
        $segments =
            explode(
                '/',
                trim(
                    $path,
                    '/'
                )
            );

        $ownedNested =
            in_array(
                $userId,
                $segments,
                true
            )
            && (
                str_starts_with(
                    $path,
                    'uploads/chat/'
                )
                || str_starts_with(
                    $path,
                    'uploads/social/'
                )
                || str_starts_with(
                    $path,
                    'uploads/streaming/'
                )
                || str_starts_with(
                    $path,
                    'uploads/profiles/'
                )
                || str_starts_with(
                    $path,
                    'uploads/uploads/'
                )
            );

        abort_unless(
            $ownedRoot
            || $ownedNested,
            403,
            'You do not own this upload.'
        );

        return $path;
    }

    private function mediaDiskName(): string
    {
        return (string) config(
            'chat_media.disk',
            config(
                'filesystems.default',
                'public'
            )
        );
    }

    private function publicUrl(
        string $path
    ): string {
        $base =
            rtrim(
                (string) config(
                    'chat_media.public_base_url',
                    ''
                ),
                '/'
            );

        if ($base !== '') {
            return $base
                . '/'
                . ltrim(
                    $path,
                    '/'
                );
        }

        return Storage::disk(
            $this->mediaDiskName()
        )->url(
            $path
        );
    }
}
