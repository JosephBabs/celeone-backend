<?php

namespace App\Http\Controllers\Api\V1\Marketplace;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminCareerController extends Controller
{
    use PresentsMarketplaceCareer;

    public function moderation(Request $request)
    {
        $kind = $request->validate([
            'kind' => 'required|in:jobs,profiles,companies,applications',
            'status' => 'nullable|in:pending_review,approved,rejected',
        ])['kind'];

        if ($kind === 'jobs') {
            $status = $request->input('status', 'pending_review');
            $rows = DB::table('marketplace_job_offers')->where('status', $status)
                ->oldest('submitted_at')->limit(100)->get()
                ->map(fn ($x) => $this->jobPayload($x, true));
        } elseif ($kind === 'profiles') {
            $status = $request->input('status', 'pending_review');
            $rows = DB::table('marketplace_professional_profiles')->where('status', $status)
                ->oldest('submitted_at')->limit(100)->get()
                ->map(fn ($x) => $this->professionalPayload($x, true));
        } elseif ($kind === 'companies') {
            $status = $request->input('status', 'pending_review');
            $rows = DB::table('marketplace_shops')
                ->whereIn('profile_type', ['company','mixed'])
                ->where('status', $status)
                ->oldest('submitted_at')->limit(100)->get()
                ->map(fn ($x) => [
                    'id' => $x->public_id,
                    'title' => $x->title,
                    'description' => $x->description,
                    'businessType' => $x->business_type,
                    'profileType' => $x->profile_type,
                    'websiteUrl' => $x->website_url,
                    'registrationNumber' => $x->registration_number,
                    'hiringEnabled' => (bool) $x->hiring_enabled,
                    'serviceFixerEnabled' => (bool) $x->service_fixer_enabled,
                    'phone' => $x->phone,
                    'whatsapp' => $x->whatsapp,
                    'email' => $x->email,
                    'city' => $x->city,
                    'country' => $x->country,
                    'status' => $x->status,
                    'logoUrl' => $x->logo_url,
                    'rejectionReason' => $x->rejection_reason,
                ]);
        } else {
            $registered = DB::table('marketplace_job_applications as a')
                ->join('marketplace_job_offers as j', 'j.id', '=', 'a.job_offer_id')
                ->join('users as u', 'u.id', '=', 'a.user_id')
                ->where('j.managed_by_celeone', true)
                ->whereIn('a.status', match ($request->input('status', 'pending_review')) {
                    'approved' => ['shortlisted', 'accepted'],
                    'rejected' => ['rejected', 'closed'],
                    default => ['interested', 'screening', 'contacted'],
                })
                ->orderByDesc('a.created_at')
                ->limit(100)
                ->get([
                    'a.*',
                    'j.public_id as job_public_id',
                    'j.title as job_title',
                    'u.name',
                    'u.email',
                ])
                ->map(fn ($x) => [
                    'id' => $x->public_id,
                    'jobId' => $x->job_public_id,
                    'jobTitle' => $x->job_title,
                    'candidateName' => $x->name,
                    'candidateEmail' => $x->email,
                    'message' => $x->message,
                    'status' => $x->status,
                    'cvDocumentId' => $x->cv_document_public_id,
                    'cvUrl' => $x->cv_document_public_id
                        ? url('/api/v1/marketplace/documents/' . $x->cv_document_public_id)
                        : null,
                    'createdAt' => $x->created_at,
                ]);

            $guest = DB::table('marketplace_public_job_applications as a')
                ->join('marketplace_job_offers as j', 'j.id', '=', 'a.job_offer_id')
                ->where('j.managed_by_celeone', true)
                ->whereIn('a.status', match ($request->input('status', 'pending_review')) {
                    'approved' => ['shortlisted', 'accepted'],
                    'rejected' => ['rejected', 'closed'],
                    default => ['interested', 'screening', 'contacted'],
                })
                ->orderByDesc('a.created_at')
                ->limit(100)
                ->get(['a.public_id','a.applicant_name','a.applicant_email','a.applicant_phone','a.message','a.status','a.created_at','j.public_id as job_public_id','j.title as job_title'])
                ->map(fn ($x) => [
                    'id' => $x->public_id,
                    'jobId' => $x->job_public_id,
                    'jobTitle' => $x->job_title,
                    'candidateName' => $x->applicant_name,
                    'candidateEmail' => $x->applicant_email,
                    'candidatePhone' => $x->applicant_phone,
                    'message' => $x->message,
                    'status' => $x->status,
                    'cvDocumentId' => null,
                    'cvUrl' => null,
                    'createdAt' => $x->created_at,
                ]);

            $rows = $registered->concat($guest)->sortByDesc('createdAt')->take(100)->values();
        }

        return response()->json(['data' => $rows->values()]);
    }

    public function showEntity(Request $request, string $kind, string $id)
    {
        if ($kind === 'job') {
            $row = DB::table('marketplace_job_offers')->where('public_id', $id)->first();
            abort_unless($row, 404);
            return response()->json(['data' => $this->jobPayload($row, true)]);
        }

        if ($kind === 'profile') {
            $row = DB::table('marketplace_professional_profiles')->where('public_id', $id)->first();
            abort_unless($row, 404);
            return response()->json(['data' => $this->professionalPayload($row, true)]);
        }

        if ($kind === 'listing') {
            $row = DB::table('marketplace_listings as l')
                ->leftJoin('marketplace_shops as s', 's.id', '=', 'l.shop_id')
                ->where('l.public_id', $id)
                ->first(['l.*','s.public_id as shop_public_id','s.title as shop_title','s.owner_id']);
            abort_unless($row, 404);
            return response()->json(['data' => [
                'id' => $row->public_id,
                'shopId' => $row->shop_public_id,
                'shopTitle' => $row->shop_title,
                'ownerId' => (string) $row->owner_id,
                'kind' => $row->kind,
                'title' => $row->title,
                'description' => $row->description,
                'category' => $row->category,
                'customCategory' => $row->custom_category,
                'images' => json_decode((string) $row->images, true) ?: [],
                'pricingMode' => $row->pricing_mode,
                'price' => $row->price === null ? null : (int) $row->price,
                'compareAtPrice' => $row->compare_at_price === null ? null : (int) $row->compare_at_price,
                'currency' => $row->currency,
                'unitLabel' => $row->unit_label,
                'stockMode' => $row->stock_mode,
                'stockQuantity' => $row->stock_quantity,
                'available' => (bool) $row->available,
                'status' => $row->status,
                'rejectionReason' => $row->rejection_reason,
                'createdAt' => $row->created_at,
                'updatedAt' => $row->updated_at,
            ]]);
        }

        abort(404);
    }

    public function reviewJob(Request $request, string $job)
    {
        $data = $request->validate([
            'decision' => 'required|in:approved,rejected',
            'reason' => 'nullable|string|max:5000',
        ]);
        if ($data['decision'] === 'rejected' && trim((string) ($data['reason'] ?? '')) === '') {
            abort(422, 'A rejection reason is required.');
        }

        $row = DB::table('marketplace_job_offers')->where('public_id', $job)->first();
        abort_unless($row && $row->status === 'pending_review', 409);

        DB::table('marketplace_job_offers')->where('id', $row->id)->update([
            'status' => $data['decision'],
            'rejection_reason' => $data['decision'] === 'rejected' ? $data['reason'] : null,
            'reviewed_by' => $request->user()->id,
            'verified_at' => $data['decision'] === 'approved' ? now() : null,
            'updated_at' => now(),
        ]);

        $updated = DB::table('marketplace_job_offers')->where('id', $row->id)->first();
        return response()->json(['data' => $this->jobPayload($updated, true)]);
    }

    public function reviewProfile(Request $request, string $profile)
    {
        $data = $request->validate([
            'decision' => 'required|in:approved,rejected',
            'reason' => 'nullable|string|max:5000',
        ]);
        if ($data['decision'] === 'rejected' && trim((string) ($data['reason'] ?? '')) === '') {
            abort(422, 'A rejection reason is required.');
        }

        $row = DB::table('marketplace_professional_profiles')->where('public_id', $profile)->first();
        abort_unless($row && $row->status === 'pending_review', 409);

        DB::table('marketplace_professional_profiles')->where('id', $row->id)->update([
            'status' => $data['decision'],
            'rejection_reason' => $data['decision'] === 'rejected' ? $data['reason'] : null,
            'reviewed_by' => $request->user()->id,
            'verified_at' => $data['decision'] === 'approved' ? now() : null,
            'updated_at' => now(),
        ]);

        $updated = DB::table('marketplace_professional_profiles')->where('id', $row->id)->first();
        return response()->json(['data' => $this->professionalPayload($updated, true)]);
    }

    public function applicationStatus(Request $request, string $application)
    {
        $data = $request->validate([
            'status' => 'required|in:interested,screening,shortlisted,contacted,accepted,rejected,closed',
            'contactUnlocked' => 'nullable|boolean',
            'note' => 'nullable|string|max:5000',
        ]);

        $row = DB::table('marketplace_job_applications')->where('public_id', $application)->first();
        if (!$row) {
            $guest = DB::table('marketplace_public_job_applications')->where('public_id', $application)->first();
            abort_unless($guest, 404);
            DB::table('marketplace_public_job_applications')->where('id', $guest->id)->update([
                'status' => $data['status'],
                'contact_unlocked' => (bool) ($data['contactUnlocked'] ?? $guest->contact_unlocked),
                'admin_note' => $data['note'] ?? null,
                'handled_by' => $request->user()->id,
                'updated_at' => now(),
            ]);

            return response()->json(['data' => ['id' => $application, 'status' => $data['status']]]);
        }

        DB::table('marketplace_job_applications')->where('id', $row->id)->update([
            'status' => $data['status'],
            'contact_unlocked' => (bool) ($data['contactUnlocked'] ?? $row->contact_unlocked),
            'admin_note' => $data['note'] ?? null,
            'handled_by' => $request->user()->id,
            'updated_at' => now(),
        ]);

        return response()->json(['data' => ['id' => $application, 'status' => $data['status']]]);
    }
}
