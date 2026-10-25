<?php

namespace App\Http\Controllers\Api\V1\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Marketplace\Shop;
use App\Services\Marketplace\RecommendationService;
use App\Support\MarketplacePresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CatalogController extends Controller
{
    use PresentsMarketplaceCareer;
    public function feed(Request $request, RecommendationService $recommendations)
    {
        $data = $request->validate([
            'query' => 'nullable|string|max:120',
            'category' => 'nullable|string|max:120',
            'kind' => 'nullable|in:product,service',
            'limit' => 'nullable|integer|min:1|max:60',
            'seed' => 'nullable|string|max:80',
        ]);

        $limit = min(60, max(1, $request->integer('limit', 30)));

        // The feed remains publicly readable, but when a valid Sanctum bearer
        // token is present we use that account's server-side interest profile.
        $user = null;
        try {
            $user = Auth::guard('sanctum')->user();
        } catch (\Throwable) {
            $user = null;
        }

        $rows = $recommendations->recommend($user, [
            'query' => trim((string) ($data['query'] ?? '')),
            'category' => $data['category'] ?? null,
            'kind' => $data['kind'] ?? null,
            'seed' => $data['seed'] ?? null,
        ], $limit);

        $shops = Shop::query()
            ->where('status', 'approved')
            ->whereIn('id', $rows->pluck('shop_id')->unique())
            ->get()
            ->sortBy(function ($shop) use ($rows) {
                return $rows->search(fn ($listing) => $listing->shop_id === $shop->id);
            })
            ->take(12)
            ->values();

        $presented = $rows->map(fn ($item) => MarketplacePresenter::listing($item))->values();

        $jobs = DB::table('marketplace_job_offers')
            ->where('status', 'approved')
            ->where('available', true)
            ->where(function ($q) {
                $q->whereNull('deadline_at')->orWhere('deadline_at', '>=', now());
            })
            ->orderByRaw("md5(public_id || ?)", [now()->format('Y-m-d') . ':' . ($user?->id ?? 'guest')])
            ->limit(12)
            ->get()
            ->map(fn ($job) => $this->jobPayload($job, true))
            ->values();

        return response()->json([
            'data' => [
                'shops' => $shops->map(fn ($shop) => MarketplacePresenter::shop($shop))->values(),
                'featured' => $presented->take(6)->values(),
                'listings' => $presented,
                'recommended' => $presented,
                'personalized' => (bool) $user,
                'jobs' => $jobs,
                'nextCursor' => null,
            ],
        ]);
    }

    public function recommendations(Request $request, RecommendationService $recommendations)
    {
        $data = $request->validate([
            'category' => 'nullable|string|max:120',
            'kind' => 'nullable|in:product,service',
            'limit' => 'nullable|integer|min:1|max:60',
            'surface' => 'nullable|string|max:60',
            'seed' => 'nullable|string|max:80',
        ]);

        $rows = $recommendations->recommend($request->user(), [
            'category' => $data['category'] ?? null,
            'kind' => $data['kind'] ?? null,
            'seed' => ($data['surface'] ?? 'marketplace') . ':' . ($data['seed'] ?? 'default'),
        ], min(60, max(1, $request->integer('limit', 24))));

        return response()->json([
            'data' => $rows->map(fn ($item) => MarketplacePresenter::listing($item))->values(),
        ]);
    }

    public function shop(string $shop)
    {
        $model = Shop::where('public_id', $shop)->where('status', 'approved')->firstOrFail();
        return response()->json(['data' => MarketplacePresenter::shop($model)]);
    }

    public function shopListings(string $shop, RecommendationService $recommendations)
    {
        $model = Shop::where('public_id', $shop)->where('status', 'approved')->firstOrFail();

        $rows = \App\Models\Marketplace\Listing::with('shop')
            ->where('shop_id', $model->id)
            ->where('status', 'approved')
            ->where('available', true)
            ->orderByRaw("md5(public_id || ?)", [now()->format('Y-m-d')])
            ->limit(60)
            ->get();

        return response()->json([
            'data' => $rows->map(fn ($item) => MarketplacePresenter::listing($item))->values(),
        ]);
    }

    public function listing(string $listing)
    {
        $model = \App\Models\Marketplace\Listing::with('shop')
            ->where('public_id', $listing)
            ->where('status', 'approved')
            ->where('available', true)
            ->whereHas('shop', fn ($query) => $query->where('status', 'approved'))
            ->firstOrFail();

        return response()->json(['data' => MarketplacePresenter::listing($model)]);
    }
}
