<?php

namespace App\Http\Controllers\Api\V1\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Marketplace\Shop;
use App\Services\Marketplace\Audit;
use App\Support\MarketplacePresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ShopController extends Controller
{
    private function rules(): array
    {
        return [
            'title' => 'required|string|min:3|max:160',
            'description' => 'required|string|min:30|max:5000',
            'businessType' => ['required', 'string', Rule::in(config('marketplace.business_types', []))],
            'customBusinessType' => 'nullable|string|max:160',
            'mode' => 'required|in:products,services,both',
            'phone' => 'nullable|string|max:40',
            'whatsapp' => 'nullable|string|max:40',
            'email' => 'nullable|email|max:190',
            'city' => 'nullable|string|max:120',
            'country' => 'nullable|string|max:120',
            'address' => 'nullable|string|max:1000',
            'logoUrl' => 'nullable|url|max:3000',
            'coverUrl' => 'nullable|url|max:3000',
            'profileType' => 'nullable|in:shop,company,provider,mixed',
            'websiteUrl' => 'nullable|url|max:1000',
            'registrationNumber' => 'nullable|string|max:191',
            'hiringEnabled' => 'nullable|boolean',
            'serviceFixerEnabled' => 'nullable|boolean',
        ];
    }

    public function mine(Request $request)
    {
        $shop = Shop::where('owner_id', $request->user()->id)->first();
        return response()->json(['data' => $shop ? MarketplacePresenter::shop($shop) : null]);
    }

    public function store(Request $request)
    {
        abort_if(Shop::where('owner_id', $request->user()->id)->exists(), 409, 'You already have a marketplace shop.');

        $data = $request->validate($this->rules());
        if ($data['businessType'] === 'other' && trim((string) ($data['customBusinessType'] ?? '')) === '') {
            abort(422, 'Custom business type is required.');
        }

        $shop = Shop::create([
            'owner_id' => $request->user()->id,
            'public_id' => (string) Str::uuid(),
            'title' => trim($data['title']),
            'slug' => Str::slug($data['title']) . '-' . Str::lower(Str::random(6)),
            'description' => trim($data['description']),
            'business_type' => $data['businessType'],
            'custom_business_type' => $data['customBusinessType'] ?? null,
            'mode' => $data['mode'],
            'phone' => $data['phone'] ?? null,
            'whatsapp' => $data['whatsapp'] ?? null,
            'email' => $data['email'] ?? $request->user()->email,
            'city' => $data['city'] ?? null,
            'country' => $data['country'] ?? null,
            'address' => $data['address'] ?? null,
            'logo_url' => $data['logoUrl'] ?? null,
            'cover_url' => $data['coverUrl'] ?? null,
            'profile_type' => $data['profileType'] ?? 'shop',
            'website_url' => $data['websiteUrl'] ?? null,
            'registration_number' => $data['registrationNumber'] ?? null,
            'hiring_enabled' => (bool) ($data['hiringEnabled'] ?? false),
            'service_fixer_enabled' => (bool) ($data['serviceFixerEnabled'] ?? false),
            'status' => 'draft',
        ]);

        Audit::log($request->user()->id, 'shop.created', 'shop', $shop->public_id);
        return response()->json(['data' => MarketplacePresenter::shop($shop)], 201);
    }

    public function update(Request $request, string $shop)
    {
        $model = Shop::where('public_id', $shop)->where('owner_id', $request->user()->id)->firstOrFail();
        $data = $request->validate($this->rules());

        $materialChange = collect([
            'title','description','businessType','customBusinessType','mode',
            'profileType','websiteUrl','registrationNumber','hiringEnabled','serviceFixerEnabled'
        ])
            ->contains(fn ($key) => array_key_exists($key, $data));

        $model->fill([
            'title' => trim($data['title']),
            'description' => trim($data['description']),
            'business_type' => $data['businessType'],
            'custom_business_type' => $data['customBusinessType'] ?? null,
            'mode' => $data['mode'],
            'phone' => $data['phone'] ?? null,
            'whatsapp' => $data['whatsapp'] ?? null,
            'email' => $data['email'] ?? $model->email,
            'city' => $data['city'] ?? null,
            'country' => $data['country'] ?? null,
            'address' => $data['address'] ?? null,
            'logo_url' => $data['logoUrl'] ?? null,
            'cover_url' => $data['coverUrl'] ?? null,
            'profile_type' => $data['profileType'] ?? ($model->profile_type ?? 'shop'),
            'website_url' => $data['websiteUrl'] ?? null,
            'registration_number' => $data['registrationNumber'] ?? null,
            'hiring_enabled' => (bool) ($data['hiringEnabled'] ?? false),
            'service_fixer_enabled' => (bool) ($data['serviceFixerEnabled'] ?? false),
        ]);

        if ($materialChange && $model->status === 'approved') {
            $model->status = 'pending_review';
            $model->verified_at = null;
            $model->reviewed_by = null;
        }

        $model->save();
        Audit::log($request->user()->id, 'shop.updated', 'shop', $model->public_id);
        return response()->json(['data' => MarketplacePresenter::shop($model)]);
    }

    public function submit(Request $request, string $shop)
    {
        $model = Shop::where('public_id', $shop)->where('owner_id', $request->user()->id)->firstOrFail();
        abort_unless(in_array($model->status, ['draft','rejected'], true), 409, 'Shop cannot be submitted from its current status.');

        $model->update([
            'status' => 'pending_review',
            'submitted_at' => now(),
            'rejection_reason' => null,
        ]);

        Audit::log($request->user()->id, 'shop.submitted', 'shop', $model->public_id);
        return response()->json(['data' => MarketplacePresenter::shop($model)]);
    }
}
