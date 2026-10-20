<?php

namespace App\Http\Controllers\Api\V1\Marketplace;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProfessionalController extends Controller
{
    use PresentsMarketplaceCareer;

    public function photo(Request $request)
    {
        return app(UploadController::class)->image($request);
    }

    public function mine(Request $request)
    {
        $row = DB::table('marketplace_professional_profiles')->where('user_id', $request->user()->id)->first();
        return response()->json(['data' => $row ? $this->professionalPayload($row, true) : null]);
    }

    public function show(Request $request, string $profile)
    {
        $row = DB::table('marketplace_professional_profiles')->where('public_id', $profile)->first();
        abort_unless($row, 404);

        $user = $request->user();
        $includeContact = (int) $row->user_id === (int) $user->id || $user->isAdmin();

        if (!$includeContact) {
            $company = $this->approvedCompanyForUser($user->id);
            abort_unless(
                $company &&
                $row->status === 'approved' &&
                $row->visibility === 'companies',
                403
            );

            DB::table('marketplace_candidate_views')->insert([
                'shop_id' => $company->id,
                'professional_profile_id' => $row->id,
                'viewer_user_id' => $user->id,
                'viewed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $includeContact = $row->contact_visibility === 'companies';
        }

        return response()->json(['data' => $this->professionalPayload($row, $includeContact)]);
    }

    public function upsert(Request $request)
    {
        $data = $request->validate([
            'headline' => 'required|string|min:3|max:255',
            'summary' => 'required|string|min:30|max:8000',
            'city' => 'nullable|string|max:128',
            'country' => 'nullable|string|max:128',
            'phone' => 'nullable|string|max:64',
            'whatsapp' => 'nullable|string|max:64',
            'email' => 'nullable|email|max:255',
            'portfolioUrl' => 'nullable|url:http,https|max:1000',
            'profilePhotoPath' => 'nullable|string|max:1000',
            'projects' => 'nullable|array|max:20',
            'projects.*.title' => 'required|string|max:160',
            'projects.*.url' => 'required|url:http,https|max:1000',
            'projects.*.description' => 'nullable|string|max:1000',
            'cvDocumentId' => 'nullable|uuid',
            'skills' => 'nullable|array|max:80',
            'skills.*.name' => 'required_with:skills|string|max:128',
            'skills.*.source' => 'nullable|string|max:255',
            'education' => 'nullable|array|max:30',
            'training' => 'nullable|array|max:30',
            'certifications' => 'nullable|array|max:30',
            'experience' => 'nullable|array|max:40',
            'availability' => 'nullable|in:open,available_soon,not_available',
            'visibility' => 'nullable|in:companies,private',
            'contactVisibility' => 'nullable|in:companies,after_interest,private',
        ]);

        if (!empty($data['cvDocumentId'])) {
            $owned = DB::table('marketplace_documents')
                ->where('public_id', $data['cvDocumentId'])
                ->where('owner_id', $request->user()->id)
                ->where('kind', 'cv')
                ->exists();
            abort_unless($owned, 422, 'The selected CV document is not valid.');
        }

        $existing = DB::table('marketplace_professional_profiles')->where('user_id', $request->user()->id)->first();
        $photoPath = array_key_exists('profilePhotoPath', $data) ? $data['profilePhotoPath'] : ($existing->profile_photo_path ?? null);
        $photoUrl = null;
        if ($photoPath) {
            $prefix = 'marketplace/' . $request->user()->id . '/';
            abort_unless(str_starts_with($photoPath, $prefix) && !str_contains($photoPath, '..') && preg_match('/\.(jpg|jpeg|png|webp)$/i', $photoPath), 422, 'Invalid profile photo.');
            $disk = Storage::disk((string) config('chat_media.disk', 'public'));
            abort_unless($disk->exists($photoPath), 422, 'Profile photo was not found. Please upload it again.');
            $base = trim((string) config('chat_media.public_base_url', ''));
            $photoUrl = $base !== '' ? rtrim($base, '/') . '/' . $photoPath : $disk->url($photoPath);
            if (!preg_match('#^https?://#i', $photoUrl)) {
                $photoUrl = rtrim((string) config('app.url'), '/') . '/' . ltrim($photoUrl, '/');
            }
        }
        $status = $existing && $existing->status === 'approved' ? 'pending_review' : ($existing->status ?? 'draft');

        $values = [
            'headline' => trim($data['headline']),
            'summary' => trim($data['summary']),
            'city' => $data['city'] ?? null,
            'country' => $data['country'] ?? null,
            'phone' => $data['phone'] ?? null,
            'whatsapp' => $data['whatsapp'] ?? null,
            'email' => $data['email'] ?? null,
            'portfolio_url' => $data['portfolioUrl'] ?? null,
            'profile_photo_path' => $photoPath,
            'profile_photo_url' => $photoUrl,
            'projects' => array_key_exists('projects', $data) ? json_encode(array_values($data['projects'] ?? [])) : ($existing->projects ?? '[]'),
            'cv_document_public_id' => $data['cvDocumentId'] ?? null,
            'skills' => json_encode(array_values($data['skills'] ?? [])),
            'education' => json_encode(array_values($data['education'] ?? [])),
            'training' => json_encode(array_values($data['training'] ?? [])),
            'certifications' => json_encode(array_values($data['certifications'] ?? [])),
            'experience' => json_encode(array_values($data['experience'] ?? [])),
            'availability' => $data['availability'] ?? 'open',
            'visibility' => $data['visibility'] ?? 'companies',
            'contact_visibility' => $data['contactVisibility'] ?? 'after_interest',
            'status' => $status,
            'rejection_reason' => null,
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('marketplace_professional_profiles')->where('id', $existing->id)->update($values);
            $id = $existing->id;
        } else {
            $values['public_id'] = (string) Str::uuid();
            $values['user_id'] = $request->user()->id;
            $values['created_at'] = now();
            $id = DB::table('marketplace_professional_profiles')->insertGetId($values);
        }

        $row = DB::table('marketplace_professional_profiles')->where('id', $id)->first();
        return response()->json(['data' => $this->professionalPayload($row, true)]);
    }

    public function submit(Request $request)
    {
        $row = DB::table('marketplace_professional_profiles')->where('user_id', $request->user()->id)->first();
        abort_unless($row, 404);
        abort_unless(in_array($row->status, ['draft','rejected'], true), 409, 'Profile is already under review.');

        DB::table('marketplace_professional_profiles')->where('id', $row->id)->update([
            'status' => 'pending_review',
            'submitted_at' => now(),
            'rejection_reason' => null,
            'updated_at' => now(),
        ]);

        return $this->mine($request);
    }

    public function directory(Request $request)
    {
        $company = $this->approvedCompanyForUser($request->user()->id);
        abort_unless($company && $company->hiring_enabled, 403, 'An approved hiring company profile is required.');

        $data = $request->validate([
            'q' => 'nullable|string|max:120',
            'skill' => 'nullable|string|max:128',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $query = DB::table('marketplace_professional_profiles')
            ->where('status', 'approved')
            ->where('visibility', 'companies')
            ->orderByDesc('verified_at');

        if (!empty($data['q'])) {
            $needle = '%' . strtolower(trim($data['q'])) . '%';
            $query->where(function ($q) use ($needle) {
                $q->whereRaw('LOWER(headline) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(summary) LIKE ?', [$needle]);
            });
        }

        if (!empty($data['skill'])) {
            $skill = strtolower(trim($data['skill']));
            $query->whereRaw(
                "EXISTS (SELECT 1 FROM jsonb_array_elements(skills) s WHERE LOWER(COALESCE(s->>'name','')) = ?)",
                [$skill]
            );
        }

        $rows = $query->limit((int) ($data['limit'] ?? 30))->get()
            ->map(fn ($row) => $this->professionalPayload(
                $row,
                $row->contact_visibility === 'companies'
            ));

        return response()->json(['data' => $rows]);
    }
}
