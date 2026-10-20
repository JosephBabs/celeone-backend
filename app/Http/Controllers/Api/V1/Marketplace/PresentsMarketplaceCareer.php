<?php

namespace App\Http\Controllers\Api\V1\Marketplace;

use Illuminate\Support\Facades\DB;

trait PresentsMarketplaceCareer
{
    private function professionalPayload(object $row, bool $includeContact = false): array
    {
        $user = DB::table('users')->where('id', $row->user_id)->first(['id','name','email']);

        $payload = [
            'id' => $row->public_id,
            'userId' => (string) $row->user_id,
            'name' => $user?->name,
            'headline' => $row->headline,
            'summary' => $row->summary,
            'city' => $row->city,
            'country' => $row->country,
            'portfolioUrl' => $row->portfolio_url,
            'photoUrl' => $row->profile_photo_url ?? null,
            'profilePhotoPath' => $includeContact ? ($row->profile_photo_path ?? null) : null,
            'projects' => json_decode((string) ($row->projects ?? '[]'), true) ?: [],
            'cvDocumentId' => $row->cv_document_public_id,
            'cvUrl' => $row->cv_document_public_id
                ? url('/api/v1/marketplace/documents/' . $row->cv_document_public_id)
                : null,
            'skills' => json_decode((string) $row->skills, true) ?: [],
            'education' => json_decode((string) $row->education, true) ?: [],
            'training' => json_decode((string) $row->training, true) ?: [],
            'certifications' => json_decode((string) $row->certifications, true) ?: [],
            'experience' => json_decode((string) $row->experience, true) ?: [],
            'availability' => $row->availability,
            'visibility' => $row->visibility,
            'contactVisibility' => $row->contact_visibility,
            'status' => $row->status,
            'rejectionReason' => $row->rejection_reason,
            'verifiedAt' => $row->verified_at,
            'createdAt' => $row->created_at,
            'updatedAt' => $row->updated_at,
        ];

        if ($includeContact) {
            $payload['phone'] = $row->phone;
            $payload['whatsapp'] = $row->whatsapp;
            $payload['email'] = $row->email ?: $user?->email;
        }

        return $payload;
    }

    private function jobPayload(object $row, bool $includeContacts = true): array
    {
        $shop = DB::table('marketplace_shops')->where('id', $row->shop_id)->first();

        return [
            'id' => $row->public_id,
            'shopId' => $shop?->public_id,
            'companyName' => $shop?->title,
            'companyLogoUrl' => $shop?->logo_url,
            'companyVerified' => $shop?->status === 'approved',
            'offerType' => $row->offer_type,
            'title' => $row->title,
            'description' => $row->description,
            'category' => $row->category,
            'location' => $row->location,
            'workMode' => $row->work_mode,
            'employmentType' => $row->employment_type,
            'salaryMin' => $row->salary_min === null ? null : (int) $row->salary_min,
            'salaryMax' => $row->salary_max === null ? null : (int) $row->salary_max,
            'currency' => $row->currency,
            'salaryPeriod' => $row->salary_period,
            'skills' => json_decode((string) $row->skills, true) ?: [],
            'requirements' => json_decode((string) $row->requirements, true) ?: [],
            'contactMode' => $row->contact_mode,
            'contactEmail' => $includeContacts ? $row->contact_email : null,
            'contactPhone' => $includeContacts ? $row->contact_phone : null,
            'contactWhatsapp' => $includeContacts ? $row->contact_whatsapp : null,
            'managedByCeleOne' => (bool) $row->managed_by_celeone,
            'available' => (bool) $row->available,
            'deadlineAt' => $row->deadline_at,
            'status' => $row->status,
            'rejectionReason' => $row->rejection_reason,
            'verifiedAt' => $row->verified_at,
            'createdAt' => $row->created_at,
            'updatedAt' => $row->updated_at,
        ];
    }

    private function approvedCompanyForUser(int $userId): ?object
    {
        return DB::table('marketplace_shops')
            ->where('owner_id', $userId)
            ->where('status', 'approved')
            ->whereIn('profile_type', ['company', 'mixed'])
            ->first();
    }
}
