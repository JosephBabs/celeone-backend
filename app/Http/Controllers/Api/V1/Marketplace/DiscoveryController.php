<?php

namespace App\Http\Controllers\Api\V1\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Marketplace\Listing;
use App\Services\Marketplace\DiscoveryService;
use Illuminate\Http\Request;

class DiscoveryController extends Controller
{
    public function store(Request $request, DiscoveryService $discovery)
    {
        $data = $request->validate([
            'listingId' => 'required|string|max:36',
            'event' => 'required|in:impression,open,favorite,cart,quote',
            'surface' => 'nullable|string|max:60',
            'contextId' => 'nullable|string|max:120',
            // shopId/category may be sent by older clients, but are deliberately
            // ignored. The server derives them from the approved listing.
            'shopId' => 'nullable|string|max:36',
            'category' => 'nullable|string|max:120',
        ]);

        $listing = Listing::with('shop')
            ->where('public_id', $data['listingId'])
            ->where('status', 'approved')
            ->where('available', true)
            ->whereHas('shop', fn ($q) => $q->where('status', 'approved'))
            ->firstOrFail();

        $recorded = $discovery->record(
            $request->user(),
            $listing,
            $data['event'],
            $data['surface'] ?? 'marketplace',
            $data['contextId'] ?? null
        );

        return response()->json([
            'data' => [
                'recorded' => $recorded,
                'listingId' => $listing->public_id,
                'shopId' => $listing->shop?->public_id,
                'category' => $listing->category,
            ],
        ], $recorded ? 201 : 200);
    }
}
