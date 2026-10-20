<?php

namespace App\Http\Controllers\Api\V1\Marketplace;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class UploadController extends Controller
{
    public function image(Request $request)
    {
        $data = $request->validate([
            'file' => ['required','file','image','mimes:jpg,jpeg,png,webp','max:10240'],
        ]);

        $file = $data['file'];
        abort_unless($file->isValid(), 422, 'Invalid image upload.');

        $mime = strtolower((string) $file->getMimeType());
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        abort_unless(isset($extensions[$mime]), 422, 'Unsupported image type.');

        $relative = sprintf(
            'marketplace/%d/%s/%s.%s',
            $request->user()->id,
            now()->format('Y/m'),
            Str::uuid(),
            $extensions[$mime]
        );

        $diskName = (string) config('chat_media.disk', 'public');
        $disk = Storage::disk($diskName);
        $stored = $disk->putFileAs(dirname($relative), $file, basename($relative), ['visibility' => 'public']);
        abort_if(!$stored, 500, 'Image could not be stored.');

        $base = trim((string) config('chat_media.public_base_url', ''));
        $url = $base !== ''
            ? rtrim($base, '/') . '/' . ltrim($relative, '/')
            : $disk->url($relative);

        if (!preg_match('#^https?://#i', $url)) {
            $url = rtrim((string) config('app.url'), '/') . '/' . ltrim($url, '/');
        }

        return response()->json(['data' => [
            'url' => $url,
            'path' => $relative,
            'mime' => $mime,
            'size' => (int) $file->getSize(),
        ]], 201);
    }

    public function document(Request $request)
    {
        $data = $request->validate([
            'file' => ['required','file','mimetypes:application/pdf','mimes:pdf','max:3072'],
            'kind' => ['nullable','in:cv,certificate,portfolio_document'],
        ]);

        $file = $data['file'];
        abort_unless($file->isValid(), 422, 'Invalid PDF upload.');
        abort_unless(strtolower((string) $file->getMimeType()) === 'application/pdf', 422, 'Only PDF documents are accepted.');

        $publicId = (string) Str::uuid();
        $relative = sprintf(
            'marketplace-private/%d/%s/%s.pdf',
            $request->user()->id,
            now()->format('Y/m'),
            $publicId
        );

        // CV/certification documents are private. They are never exposed through
        // the public CDN directly; access is authorized by downloadDocument().
        $diskName = 'local';
        $stored = Storage::disk($diskName)->putFileAs(
            dirname($relative),
            $file,
            basename($relative)
        );
        abort_if(!$stored, 500, 'Document could not be stored.');

        $sha256 = hash_file('sha256', $file->getRealPath());

        DB::table('marketplace_documents')->insert([
            'public_id' => $publicId,
            'owner_id' => $request->user()->id,
            'kind' => $data['kind'] ?? 'cv',
            'disk' => $diskName,
            'path' => $relative,
            'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
            'mime' => 'application/pdf',
            'size_bytes' => (int) $file->getSize(),
            'sha256' => $sha256,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['data' => [
            'id' => $publicId,
            'documentId' => $publicId,
            'url' => url('/api/v1/marketplace/documents/' . $publicId),
            'name' => $file->getClientOriginalName(),
            'mime' => 'application/pdf',
            'size' => (int) $file->getSize(),
            'maxSize' => 3 * 1024 * 1024,
        ]], 201);
    }

    public function documentLink(Request $request, string $document)
    {
        $doc = $this->authorizedDocument($request, $document);

        return response()->json(['data' => [
            'id' => $doc->public_id,
            'url' => URL::temporarySignedRoute(
                'marketplace.documents.download',
                now()->addMinutes(5),
                ['document' => $doc->public_id]
            ),
            'expiresInSeconds' => 300,
        ]]);
    }

    public function downloadSignedDocument(Request $request, string $document)
    {
        $doc = DB::table('marketplace_documents')->where('public_id', $document)->first();
        abort_unless($doc, 404);

        $disk = Storage::disk($doc->disk);
        abort_unless($disk->exists($doc->path), 404);

        return $disk->download(
            $doc->path,
            $doc->original_name,
            ['Content-Type' => $doc->mime]
        );
    }

    private function authorizedDocument(Request $request, string $document): object
    {
        $doc = DB::table('marketplace_documents')->where('public_id', $document)->first();
        abort_unless($doc, 404);

        $user = $request->user();
        $allowed = (int) $doc->owner_id === (int) $user->id || $user->isAdmin();

        if (!$allowed) {
            $company = DB::table('marketplace_shops')
                ->where('owner_id', $user->id)
                ->where('status', 'approved')
                ->whereIn('profile_type', ['company','mixed'])
                ->first();

            if ($company) {
                $profile = DB::table('marketplace_professional_profiles')
                    ->where('cv_document_public_id', $document)
                    ->first();

                if ($profile) {
                    $appliedToCompany = DB::table('marketplace_job_applications as a')
                        ->join('marketplace_job_offers as j', 'j.id', '=', 'a.job_offer_id')
                        ->where('a.professional_profile_id', $profile->id)
                        ->where('j.shop_id', $company->id)
                        ->exists();

                    $directoryVisible =
                        $profile->status === 'approved' &&
                        $profile->visibility === 'companies';

                    $allowed = $appliedToCompany || $directoryVisible;
                }
            }
        }

        abort_unless($allowed, 403, 'You are not allowed to access this document.');
        return $doc;
    }

    public function downloadDocument(Request $request, string $document)
    {
        $doc = $this->authorizedDocument($request, $document);

        $disk = Storage::disk($doc->disk);
        abort_unless($disk->exists($doc->path), 404);

        return $disk->download(
            $doc->path,
            $doc->original_name,
            ['Content-Type' => $doc->mime]
        );
    }
}
