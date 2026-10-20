<?php

namespace App\Http\Controllers\Api\V1\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Marketplace\Listing;
use App\Models\Marketplace\Shop;
use App\Services\Marketplace\Audit;
use App\Support\MarketplacePresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ListingController extends Controller
{
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'kind' => 'required|in:product,service',
            'title' => 'required|string|min:3|max:180',
            'description' => 'required|string|min:15|max:8000',
            'category' => 'required|string|max:120',
            'customCategory' => 'nullable|string|max:180',
            'images' => 'nullable|array|max:6',
            'images.*' => 'url|max:3000',
            'pricingMode' => 'required|in:fixed,from,quote',
            'price' => 'nullable|integer|min:0|max:999999999999',
            'compareAtPrice' => 'nullable|integer|min:0|max:999999999999',
            'currency' => 'nullable|string|size:3',
            'unitLabel' => 'nullable|string|max:80',
            'stockMode' => 'required|in:tracked,unlimited,unavailable',
            'stockQuantity' => 'nullable|integer|min:0|max:100000000',
            'available' => 'required|boolean',
        ]);

        if ($data['pricingMode'] !== 'quote' && (!isset($data['price']) || (int) $data['price'] <= 0)) {
            abort(422, 'A positive price is required.');
        }
        if ($data['kind'] === 'product' && $data['stockMode'] === 'tracked' && !isset($data['stockQuantity'])) {
            abort(422, 'Stock quantity is required for tracked products.');
        }
        return $data;
    }

    public function store(Request $request, string $shop)
    {
        $ownerShop = Shop::where('public_id', $shop)->where('owner_id', $request->user()->id)->firstOrFail();
        abort_unless(in_array($ownerShop->status, ['approved','pending_review','draft'], true), 409, 'Shop is not allowed to create offers.');

        $data = $this->validated($request);
        $listing = Listing::create([
            'public_id' => (string) Str::uuid(),
            'shop_id' => $ownerShop->id,
            'kind' => $data['kind'],
            'title' => trim($data['title']),
            'description' => trim($data['description']),
            'category' => $data['category'],
            'custom_category' => $data['customCategory'] ?? null,
            'images' => $data['images'] ?? [],
            'pricing_mode' => $data['pricingMode'],
            'price' => $data['pricingMode'] === 'quote' ? null : (int) $data['price'],
            'compare_at_price' => $data['compareAtPrice'] ?? null,
            'currency' => strtoupper($data['currency'] ?? config('marketplace.currency', 'XOF')),
            'unit_label' => $data['unitLabel'] ?? null,
            'stock_mode' => $data['kind'] === 'service' ? 'unlimited' : $data['stockMode'],
            'stock_quantity' => $data['kind'] === 'service' ? null : ($data['stockQuantity'] ?? null),
            'available' => (bool) $data['available'],
            'status' => 'draft',
        ]);

        Audit::log($request->user()->id, 'listing.created', 'listing', $listing->public_id);
        return response()->json(['data' => MarketplacePresenter::listing($listing->load('shop'))], 201);
    }

    public function update(Request $request, string $listing)
    {
        $model = Listing::where('public_id', $listing)
            ->whereHas('shop', fn ($q) => $q->where('owner_id', $request->user()->id))
            ->with('shop')
            ->firstOrFail();

        $data = $this->validated($request);
        $model->fill([
            'kind' => $data['kind'],
            'title' => trim($data['title']),
            'description' => trim($data['description']),
            'category' => $data['category'],
            'custom_category' => $data['customCategory'] ?? null,
            'images' => $data['images'] ?? [],
            'pricing_mode' => $data['pricingMode'],
            'price' => $data['pricingMode'] === 'quote' ? null : (int) $data['price'],
            'compare_at_price' => $data['compareAtPrice'] ?? null,
            'currency' => strtoupper($data['currency'] ?? $model->currency),
            'unit_label' => $data['unitLabel'] ?? null,
            'stock_mode' => $data['kind'] === 'service' ? 'unlimited' : $data['stockMode'],
            'stock_quantity' => $data['kind'] === 'service' ? null : ($data['stockQuantity'] ?? null),
            'available' => (bool) $data['available'],
            'status' => $model->status === 'approved' ? 'pending_review' : $model->status,
            'verified_at' => $model->status === 'approved' ? null : $model->verified_at,
        ]);
        $model->save();

        Audit::log($request->user()->id, 'listing.updated', 'listing', $model->public_id);
        return response()->json(['data' => MarketplacePresenter::listing($model)]);
    }

    public function submit(Request $request, string $listing)
    {
        $model = Listing::where('public_id', $listing)
            ->whereHas('shop', fn ($q) => $q->where('owner_id', $request->user()->id))
            ->with('shop')
            ->firstOrFail();

        abort_unless($model->shop->status === 'approved', 409, 'Shop must be approved before an offer can be submitted.');
        abort_unless(in_array($model->status, ['draft','rejected'], true), 409, 'Offer cannot be submitted from its current status.');

        $model->update(['status' => 'pending_review', 'submitted_at' => now(), 'rejection_reason' => null]);
        Audit::log($request->user()->id, 'listing.submitted', 'listing', $model->public_id);

        return response()->json(['data' => MarketplacePresenter::listing($model)]);
    }
}
