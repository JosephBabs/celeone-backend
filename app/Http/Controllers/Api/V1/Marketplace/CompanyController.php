<?php

namespace App\Http\Controllers\Api\V1\Marketplace;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CompanyController extends Controller
{
    use PresentsMarketplaceCareer;

    public function mine(Request $request)
    {
        $shop = DB::table('marketplace_shops')->where('owner_id', $request->user()->id)->first();
        if (!$shop) return response()->json(['data' => null]);

        return response()->json(['data' => [
            'id' => $shop->public_id,
            'title' => $shop->title,
            'description' => $shop->description,
            'profileType' => $shop->profile_type,
            'businessType' => $shop->business_type,
            'websiteUrl' => $shop->website_url,
            'registrationNumber' => $shop->registration_number,
            'hiringEnabled' => (bool) $shop->hiring_enabled,
            'serviceFixerEnabled' => (bool) $shop->service_fixer_enabled,
            'status' => $shop->status,
            'logoUrl' => $shop->logo_url,
            'phone' => $shop->phone,
            'whatsapp' => $shop->whatsapp,
            'email' => $shop->email,
            'city' => $shop->city,
            'country' => $shop->country,
        ]]);
    }

    public function updateCapabilities(Request $request)
    {
        $shop = DB::table('marketplace_shops')->where('owner_id', $request->user()->id)->first();
        abort_unless($shop, 404);

        $data = $request->validate([
            'profileType' => 'required|in:shop,company,provider,mixed',
            'websiteUrl' => 'nullable|url|max:1000',
            'registrationNumber' => 'nullable|string|max:191',
            'hiringEnabled' => 'nullable|boolean',
            'serviceFixerEnabled' => 'nullable|boolean',
        ]);

        $status = $shop->status === 'approved' ? 'pending_review' : $shop->status;

        DB::table('marketplace_shops')->where('id', $shop->id)->update([
            'profile_type' => $data['profileType'],
            'website_url' => $data['websiteUrl'] ?? null,
            'registration_number' => $data['registrationNumber'] ?? null,
            'hiring_enabled' => (bool) ($data['hiringEnabled'] ?? false),
            'service_fixer_enabled' => (bool) ($data['serviceFixerEnabled'] ?? false),
            'status' => $status,
            'rejection_reason' => null,
            'updated_at' => now(),
        ]);

        return $this->mine($request);
    }
}
