<?php

namespace App\Http\Controllers\Api\V1\Marketplace;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class JobController extends Controller
{
    use PresentsMarketplaceCareer;

    public function feed(Request $request)
    {
        $data = $request->validate([
            'q' => 'nullable|string|max:120',
            'type' => 'nullable|in:job,internship,contract,service_request',
            'category' => 'nullable|string|max:128',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $query = DB::table('marketplace_job_offers')
            ->where('status', 'approved')
            ->where('available', true)
            ->where(function ($q) {
                $q->whereNull('deadline_at')->orWhere('deadline_at', '>=', now());
            })
            ->orderByDesc('verified_at')
            ->orderByDesc('created_at');

        if (!empty($data['type'])) $query->where('offer_type', $data['type']);
        if (!empty($data['category'])) $query->where('category', $data['category']);
        if (!empty($data['q'])) {
            $needle = '%' . strtolower(trim($data['q'])) . '%';
            $query->where(function ($q) use ($needle) {
                $q->whereRaw('LOWER(title) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(description) LIKE ?', [$needle]);
            });
        }

        $rows = $query->limit((int) ($data['limit'] ?? 30))->get()
            ->map(fn ($row) => $this->jobPayload($row, true));

        return response()->json(['data' => $rows]);
    }

    public function show(Request $request, string $job)
    {
        $row = DB::table('marketplace_job_offers')->where('public_id', $job)->first();
        abort_unless($row, 404);

        $public = $row->status === 'approved' && $row->available;
        $owner = $request->user() && (int) $row->created_by === (int) $request->user()->id;
        $admin = $request->user()?->isAdmin() ?? false;
        abort_unless($public || $owner || $admin, 404);

        $includeContacts = !$row->managed_by_celeone && in_array($row->contact_mode, ['contact','both'], true);
        return response()->json(['data' => $this->jobPayload($row, $includeContacts)]);
    }

    public function mine(Request $request)
    {
        $company = $this->approvedCompanyForUser($request->user()->id);
        abort_unless($company, 403);

        $rows = DB::table('marketplace_job_offers')
            ->where('shop_id', $company->id)
            ->latest()
            ->get()
            ->map(fn ($row) => $this->jobPayload($row, true));

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request)
    {
        $company = $this->approvedCompanyForUser($request->user()->id);
        abort_unless($company && $company->hiring_enabled, 403, 'Company hiring is not enabled.');

        $data = $this->validated($request);
        $serviceRequest = $data['offerType'] === 'service_request';
        $publicId = (string) Str::uuid();

        DB::table('marketplace_job_offers')->insert([
            'public_id' => $publicId,
            'shop_id' => $company->id,
            'created_by' => $request->user()->id,
            'offer_type' => $data['offerType'],
            'title' => trim($data['title']),
            'description' => trim($data['description']),
            'category' => $data['category'] ?? null,
            'location' => $data['location'] ?? null,
            'work_mode' => $data['workMode'] ?? 'onsite',
            'employment_type' => $data['employmentType'] ?? null,
            'salary_min' => $data['salaryMin'] ?? null,
            'salary_max' => $data['salaryMax'] ?? null,
            'currency' => $data['currency'] ?? 'XOF',
            'salary_period' => $data['salaryPeriod'] ?? null,
            'skills' => json_encode(array_values($data['skills'] ?? [])),
            'requirements' => json_encode(array_values($data['requirements'] ?? [])),
            'contact_mode' => $serviceRequest ? 'interest' : ($data['contactMode'] ?? 'interest'),
            'contact_email' => $serviceRequest ? null : ($data['contactEmail'] ?? null),
            'contact_phone' => $serviceRequest ? null : ($data['contactPhone'] ?? null),
            'contact_whatsapp' => $serviceRequest ? null : ($data['contactWhatsapp'] ?? null),
            'managed_by_celeone' => $serviceRequest,
            'available' => true,
            'deadline_at' => $data['deadlineAt'] ?? null,
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('marketplace_job_offers')->where('public_id', $publicId)->first();
        return response()->json(['data' => $this->jobPayload($row, true)], 201);
    }

    public function update(Request $request, string $job)
    {
        $row = DB::table('marketplace_job_offers')->where('public_id', $job)->first();
        abort_unless($row, 404);
        abort_unless((int) $row->created_by === (int) $request->user()->id, 403);

        $data = $this->validated($request);
        $serviceRequest = $data['offerType'] === 'service_request';
        $nextStatus = $row->status === 'approved' ? 'pending_review' : $row->status;

        DB::table('marketplace_job_offers')->where('id', $row->id)->update([
            'offer_type' => $data['offerType'],
            'title' => trim($data['title']),
            'description' => trim($data['description']),
            'category' => $data['category'] ?? null,
            'location' => $data['location'] ?? null,
            'work_mode' => $data['workMode'] ?? 'onsite',
            'employment_type' => $data['employmentType'] ?? null,
            'salary_min' => $data['salaryMin'] ?? null,
            'salary_max' => $data['salaryMax'] ?? null,
            'currency' => $data['currency'] ?? 'XOF',
            'salary_period' => $data['salaryPeriod'] ?? null,
            'skills' => json_encode(array_values($data['skills'] ?? [])),
            'requirements' => json_encode(array_values($data['requirements'] ?? [])),
            'contact_mode' => $serviceRequest ? 'interest' : ($data['contactMode'] ?? 'interest'),
            'contact_email' => $serviceRequest ? null : ($data['contactEmail'] ?? null),
            'contact_phone' => $serviceRequest ? null : ($data['contactPhone'] ?? null),
            'contact_whatsapp' => $serviceRequest ? null : ($data['contactWhatsapp'] ?? null),
            'managed_by_celeone' => $serviceRequest,
            'available' => (bool) ($data['available'] ?? true),
            'deadline_at' => $data['deadlineAt'] ?? null,
            'status' => $nextStatus,
            'rejection_reason' => null,
            'updated_at' => now(),
        ]);

        $updated = DB::table('marketplace_job_offers')->where('id', $row->id)->first();
        return response()->json(['data' => $this->jobPayload($updated, true)]);
    }

    public function submit(Request $request, string $job)
    {
        $row = DB::table('marketplace_job_offers')->where('public_id', $job)->first();
        abort_unless($row, 404);
        abort_unless((int) $row->created_by === (int) $request->user()->id, 403);
        abort_unless(in_array($row->status, ['draft','rejected'], true), 409, 'Offer is already under review.');

        DB::table('marketplace_job_offers')->where('id', $row->id)->update([
            'status' => 'pending_review',
            'submitted_at' => now(),
            'rejection_reason' => null,
            'updated_at' => now(),
        ]);

        $updated = DB::table('marketplace_job_offers')->where('id', $row->id)->first();
        return response()->json(['data' => $this->jobPayload($updated, true)]);
    }

    public function apply(Request $request, string $job)
    {
        $offer = DB::table('marketplace_job_offers')->where('public_id', $job)->first();
        abort_unless(
            $offer &&
            $offer->status === 'approved' &&
            $offer->available &&
            (!$offer->deadline_at || \Illuminate\Support\Carbon::parse($offer->deadline_at)->isFuture()),
            404
        );

        $data = $request->validate([
            'message' => 'nullable|string|max:6000',
            'cvDocumentId' => 'nullable|uuid',
            'extraDetails' => 'nullable|array',
        ]);

        $profile = DB::table('marketplace_professional_profiles')
            ->where('user_id', $request->user()->id)
            ->first();

        $cvId = $data['cvDocumentId'] ?? $profile?->cv_document_public_id;
        if ($cvId) {
            $owned = DB::table('marketplace_documents')
                ->where('public_id', $cvId)
                ->where('owner_id', $request->user()->id)
                ->exists();
            abort_unless($owned, 422, 'CV document is not valid.');
        }

        $existing = DB::table('marketplace_job_applications')
            ->where('job_offer_id', $offer->id)
            ->where('user_id', $request->user()->id)
            ->first();

        $values = [
            'professional_profile_id' => $profile?->id,
            'cv_document_public_id' => $cvId,
            'message' => $data['message'] ?? null,
            'extra_details' => json_encode($data['extraDetails'] ?? []),
            'status' => 'interested',
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('marketplace_job_applications')->where('id', $existing->id)->update($values);
            $id = $existing->id;
        } else {
            $values += [
                'public_id' => (string) Str::uuid(),
                'job_offer_id' => $offer->id,
                'user_id' => $request->user()->id,
                'contact_unlocked' => false,
                'created_at' => now(),
            ];
            $id = DB::table('marketplace_job_applications')->insertGetId($values);
        }

        $row = DB::table('marketplace_job_applications')->where('id', $id)->first();
        return response()->json(['data' => [
            'id' => $row->public_id,
            'jobId' => $offer->public_id,
            'status' => $row->status,
            'managedByCeleOne' => (bool) $offer->managed_by_celeone,
            'createdAt' => $row->created_at,
        ]], $existing ? 200 : 201);
    }

    public function myApplications(Request $request)
    {
        $rows = DB::table('marketplace_job_applications as a')
            ->join('marketplace_job_offers as j', 'j.id', '=', 'a.job_offer_id')
            ->where('a.user_id', $request->user()->id)
            ->orderByDesc('a.created_at')
            ->get([
                'a.public_id','a.status','a.created_at',
                'j.public_id as job_public_id','j.title','j.offer_type','j.managed_by_celeone'
            ])
            ->map(fn ($x) => [
                'id' => $x->public_id,
                'jobId' => $x->job_public_id,
                'title' => $x->title,
                'offerType' => $x->offer_type,
                'status' => $x->status,
                'managedByCeleOne' => (bool) $x->managed_by_celeone,
                'createdAt' => $x->created_at,
            ]);

        return response()->json(['data' => $rows]);
    }

    public function candidates(Request $request, string $job)
    {
        $offer = DB::table('marketplace_job_offers')->where('public_id', $job)->first();
        abort_unless($offer, 404);
        abort_unless((int) $offer->created_by === (int) $request->user()->id, 403);

        // "Prestation" service requests are fixed/handled by Cèlè One.
        // The company sees progress/counts but candidate contacts remain admin-managed.
        $rows = DB::table('marketplace_job_applications as a')
            ->leftJoin('marketplace_professional_profiles as p', 'p.id', '=', 'a.professional_profile_id')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.job_offer_id', $offer->id)
            ->orderByDesc('a.created_at')
            ->get([
                'a.*',
                'p.public_id as profile_public_id',
                'p.headline',
                'p.status as profile_status',
                'p.city',
                'p.country',
                'p.portfolio_url',
                'p.skills',
                'p.contact_visibility',
                'p.phone',
                'p.whatsapp',
                'p.email as profile_email',
                'u.name',
                'u.email as account_email',
            ])
            ->map(function ($x) use ($offer) {
                // Applying is explicit interest. For normal job offers,
                // "after_interest" contact details become visible to the hiring company.
                // Private contact stays hidden unless Cèlè One explicitly unlocks it.
                $canContact = !$offer->managed_by_celeone &&
                    ($x->contact_unlocked || in_array($x->contact_visibility, ['companies','after_interest'], true));

                return [
                    'id' => $x->public_id,
                    'profileId' => $x->profile_public_id,
                    'name' => $x->name,
                    'headline' => $x->headline,
                    'profileVerified' => $x->profile_status === 'approved',
                    'city' => $x->city,
                    'country' => $x->country,
                    'portfolioUrl' => $x->portfolio_url,
                    'skills' => json_decode((string) $x->skills, true) ?: [],
                    'message' => $x->message,
                    'status' => $x->status,
                    'cvDocumentId' => $x->cv_document_public_id,
                    'cvUrl' => $x->cv_document_public_id
                        ? url('/api/v1/marketplace/documents/' . $x->cv_document_public_id)
                        : null,
                    'contact' => $canContact ? [
                        'phone' => $x->phone,
                        'whatsapp' => $x->whatsapp,
                        'email' => $x->profile_email ?: $x->account_email,
                    ] : null,
                    'managedByCeleOne' => (bool) $offer->managed_by_celeone,
                    'createdAt' => $x->created_at,
                ];
            });

        return response()->json(['data' => $rows]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'offerType' => 'required|in:job,internship,contract,service_request',
            'title' => 'required|string|min:3|max:255',
            'description' => 'required|string|min:30|max:12000',
            'category' => 'nullable|string|max:128',
            'location' => 'nullable|string|max:255',
            'workMode' => 'nullable|in:onsite,remote,hybrid',
            'employmentType' => 'nullable|string|max:40',
            'salaryMin' => 'nullable|integer|min:0|max:999999999',
            'salaryMax' => 'nullable|integer|min:0|max:999999999|gte:salaryMin',
            'currency' => 'nullable|string|max:8',
            'salaryPeriod' => 'nullable|in:hour,day,week,month,year,project',
            'skills' => 'nullable|array|max:80',
            'skills.*' => 'string|max:128',
            'requirements' => 'nullable|array|max:80',
            'requirements.*' => 'string|max:500',
            'contactMode' => 'nullable|in:interest,contact,both',
            'contactEmail' => 'nullable|email|max:255',
            'contactPhone' => 'nullable|string|max:64',
            'contactWhatsapp' => 'nullable|string|max:64',
            'deadlineAt' => 'nullable|date|after:now',
            'available' => 'nullable|boolean',
        ]);
    }
}
