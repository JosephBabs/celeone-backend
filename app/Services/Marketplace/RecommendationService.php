<?php

namespace App\Services\Marketplace;

use App\Models\Marketplace\Listing;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RecommendationService
{
    public function recommend(?User $user, array $filters = [], int $limit = 36): Collection
    {
        $limit = max(1, min(60, $limit));
        $candidateLimit = max(80, min(240, $limit * 5));
        $seed = ($user?->id ?? 'guest') . ':' . now()->format('Y-m-d') . ':' . ($filters['seed'] ?? 'default');

        $query = Listing::query()
            ->with('shop')
            ->where('marketplace_listings.status', 'approved')
            ->where('marketplace_listings.available', true)
            ->whereHas('shop', fn ($q) => $q->where('status', 'approved'));

        if (!empty($filters['kind'])) {
            $query->where('marketplace_listings.kind', $filters['kind']);
        }
        if (!empty($filters['category'])) {
            $query->where('marketplace_listings.category', $filters['category']);
        }
        if (!empty($filters['query'])) {
            $needle = '%' . mb_strtolower(trim((string) $filters['query'])) . '%';
            $query->where(function ($q) use ($needle) {
                $q->whereRaw('LOWER(marketplace_listings.title) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(marketplace_listings.description) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(marketplace_listings.category) LIKE ?', [$needle]);
            });
        }

        // PostgreSQL deterministic shuffle. This prevents creation-time ordering
        // from becoming a hidden sales advantage.
        $candidates = $query
            ->orderByRaw("md5(marketplace_listings.public_id || ?)", [$seed])
            ->limit($candidateLimit)
            ->get();

        if ($candidates->isEmpty()) return collect();

        $shopIds = $candidates->pluck('shop_id')->unique()->values();
        $listingIds = $candidates->pluck('id')->unique()->values();

        $categoryScores = collect();
        $sellerSignals = collect();
        if ($user) {
            $categoryScores = DB::table('marketplace_user_interest_profiles')
                ->where('user_id', $user->id)
                ->whereIn('category', $candidates->pluck('category')->unique())
                ->get()
                ->keyBy('category');

            $sellerSignals = DB::table('marketplace_user_seller_signals')
                ->where('user_id', $user->id)
                ->whereIn('shop_id', $shopIds)
                ->get()
                ->keyBy(fn ($x) => (int) $x->shop_id);
        }

        $globalSellerExposure = DB::table('marketplace_discovery_events')
            ->selectRaw('shop_id, COUNT(*) FILTER (WHERE event = ?) AS impressions', ['impression'])
            ->whereIn('shop_id', $shopIds)
            ->where('occurred_at', '>=', now()->subDays(14))
            ->groupBy('shop_id')
            ->get()
            ->keyBy(fn ($x) => (int) $x->shop_id);

        $quality = DB::table('marketplace_discovery_events')
            ->selectRaw("
                listing_id,
                COUNT(*) FILTER (WHERE event = 'impression') AS impressions,
                COUNT(*) FILTER (WHERE event = 'open') AS opens,
                COUNT(*) FILTER (WHERE event = 'cart') AS carts,
                COUNT(*) FILTER (WHERE event = 'purchase') AS purchases
            ")
            ->whereIn('listing_id', $listingIds)
            ->where('occurred_at', '>=', now()->subDays(30))
            ->groupBy('listing_id')
            ->get()
            ->keyBy(fn ($x) => (int) $x->listing_id);

        $maxGlobalExposure = max(
            1,
            (int) $globalSellerExposure->max(fn ($row) => (int) ($row->impressions ?? 0))
        );

        $scored = $candidates->map(function (Listing $listing) use (
            $user,
            $seed,
            $categoryScores,
            $sellerSignals,
            $globalSellerExposure,
            $quality,
            $maxGlobalExposure
        ) {
            $interest = $categoryScores->get($listing->category);
            $seller = $sellerSignals->get((int) $listing->shop_id);
            $global = $globalSellerExposure->get((int) $listing->shop_id);
            $q = $quality->get((int) $listing->id);

            $categoryAffinity = $user
                ? min(1, max(0, (float) ($interest->score ?? 0) / 20))
                : 0;

            $sellerAffinity = $user
                ? min(1, max(0, (float) ($seller->score ?? 0) / 15))
                : 0;

            $personalImpressions = (int) ($seller->impressions ?? 0);
            $personalExposurePenalty = min(1, $personalImpressions / 12);

            $globalExposure = (int) ($global->impressions ?? 0);
            $globalExposurePenalty = min(1, $globalExposure / $maxGlobalExposure);

            $impressions = max(1, (int) ($q->impressions ?? 0));
            $opens = (int) ($q->opens ?? 0);
            $carts = (int) ($q->carts ?? 0);
            $purchases = (int) ($q->purchases ?? 0);
            $qualityScore = min(1, (($opens * 0.20) + ($carts * 1.25) + ($purchases * 3.0)) / ($impressions + 8));

            $exploration = $this->stableRandom($seed . ':' . $listing->public_id);

            // The weights deliberately leave a large exploration/fairness share.
            // Personalization should help relevance without turning into a winner-
            // takes-all loop for already popular sellers.
            $score =
                ($categoryAffinity * 0.34) +
                ($sellerAffinity * 0.08) +
                ($exploration * 0.28) +
                ($qualityScore * 0.12) +
                ((1 - $globalExposurePenalty) * 0.12) +
                ((1 - $personalExposurePenalty) * 0.06);

            return [
                'listing' => $listing,
                'score' => $score,
                'exploration' => $exploration,
                'seller_exposure' => $globalExposure,
            ];
        })->sortByDesc('score')->values();

        // Diversity guard: a single seller cannot fill the recommendation page.
        $sellerCap = $limit <= 8 ? 1 : max(2, (int) ceil($limit / 12));
        $sellerCounts = [];
        $selected = collect();

        foreach ($scored as $row) {
            /** @var Listing $listing */
            $listing = $row['listing'];
            $count = $sellerCounts[$listing->shop_id] ?? 0;
            if ($count >= $sellerCap) continue;

            $selected->push($listing);
            $sellerCounts[$listing->shop_id] = $count + 1;
            if ($selected->count() >= $limit) break;
        }

        // Fill any unused capacity without seller cap only if the catalog is too
        // small; no creation-date ordering is introduced.
        if ($selected->count() < $limit) {
            $existing = $selected->pluck('id')->all();
            foreach ($scored as $row) {
                $listing = $row['listing'];
                if (in_array($listing->id, $existing, true)) continue;
                $selected->push($listing);
                if ($selected->count() >= $limit) break;
            }
        }

        return $selected->values();
    }

    private function stableRandom(string $value): float
    {
        $unsigned = sprintf('%u', crc32($value));
        return ((float) $unsigned) / 4294967295;
    }
}
