<?php

namespace App\Http\Controllers\Api\V1\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Marketplace\Listing;
use App\Models\Marketplace\QuoteRequest;
use App\Services\Marketplace\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class QuoteController extends Controller
{
    public function store(Request $request, string $listing)
    {
        $offer = Listing::with('shop')->where('public_id', $listing)->where('status', 'approved')->where('available', true)->firstOrFail();
        abort_unless($offer->kind === 'service' && $offer->pricing_mode === 'quote', 422, 'This offer does not accept quote requests.');

        $data = $request->validate([
            'description' => 'required|string|min:15|max:6000',
            'budget' => 'nullable|integer|min:0|max:999999999999',
            'preferredDate' => 'nullable|date',
            'phone' => 'required|string|max:40',
        ]);

        $quote = QuoteRequest::create([
            'public_id' => (string) Str::uuid(),
            'listing_id' => $offer->id,
            'buyer_id' => $request->user()->id,
            'description' => $data['description'],
            'budget' => $data['budget'] ?? null,
            'preferred_date' => $data['preferredDate'] ?? null,
            'phone' => $data['phone'],
            'status' => 'pending',
        ]);

        Audit::log($request->user()->id, 'quote.created', 'quote', $quote->public_id);
        return response()->json(['data' => [
            'id' => $quote->public_id,
            'listingId' => $offer->public_id,
            'buyerId' => (string) $quote->buyer_id,
            'description' => $quote->description,
            'budget' => $quote->budget,
            'preferredDate' => optional($quote->preferred_date)->format('Y-m-d'),
            'phone' => $quote->phone,
            'status' => $quote->status,
            'createdAt' => optional($quote->created_at)->toIso8601String(),
        ]], 201);
    }
}
