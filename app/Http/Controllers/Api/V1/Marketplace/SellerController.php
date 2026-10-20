<?php

namespace App\Http\Controllers\Api\V1\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Marketplace\Listing;
use App\Models\Marketplace\Order;
use App\Models\Marketplace\Shop;
use App\Support\MarketplacePresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SellerController extends Controller
{
    private function shop(Request $request): Shop
    {
        return Shop::where('owner_id', $request->user()->id)->firstOrFail();
    }

    public function listings(Request $request)
    {
        $shop = $this->shop($request);
        $rows = Listing::with('shop')->where('shop_id', $shop->id)->latest()->get();
        return response()->json(['data' => $rows->map(fn ($x) => MarketplacePresenter::listing($x))->values()]);
    }

    public function dashboard(Request $request)
    {
        $shop = $this->shop($request);
        return response()->json(['data' => [
            'ordersCount' => Order::where('shop_id', $shop->id)->count(),
            'pendingOrders' => Order::where('shop_id', $shop->id)->whereIn('status', ['paid','processing','ready'])->count(),
            'activeListings' => Listing::where('shop_id', $shop->id)->where('status', 'approved')->where('available', true)->count(),
            'openDisputes' => DB::table('marketplace_disputes')
                ->join('marketplace_orders', 'marketplace_orders.id', '=', 'marketplace_disputes.order_id')
                ->where('marketplace_orders.shop_id', $shop->id)
                ->where('marketplace_disputes.status', 'open')
                ->count(),
            'payoutRequestPending' => DB::table('marketplace_payout_requests')
                ->where('shop_id', $shop->id)
                ->whereIn('status', ['pending','approved','batched'])
                ->exists(),
        ]]);
    }

    public function revenue(Request $request)
    {
        $shop = $this->shop($request);

        $gross = (int) Order::where('shop_id', $shop->id)->whereIn('payment_status', ['paid','approved'])->sum('subtotal');
        $fees = (int) Order::where('shop_id', $shop->id)->whereIn('payment_status', ['paid','approved'])->sum('platform_fee');
        $pending = (int) Order::where('shop_id', $shop->id)
            ->whereIn('payment_status', ['paid','approved'])
            ->whereNotIn('status', ['completed','cancelled','refunded'])
            ->sum('seller_net');

        $eligible = (int) DB::table('marketplace_ledger_entries')
            ->where('shop_id', $shop->id)->where('type', 'seller_credit')->where('status', 'eligible')->sum('amount');
        $reserved = (int) DB::table('marketplace_ledger_entries')
            ->where('shop_id', $shop->id)->where('type', 'seller_credit')->whereIn('status', ['reserved','batched'])->sum('amount');
        $paidOut = (int) DB::table('marketplace_ledger_entries')
            ->where('shop_id', $shop->id)->where('type', 'seller_credit')->where('status', 'settled')->sum('amount');

        $requestRow = DB::table('marketplace_payout_requests')
            ->where('shop_id', $shop->id)
            ->whereIn('status', ['pending','approved','batched'])
            ->latest('id')
            ->first();

        return response()->json(['data' => [
            'currency' => config('marketplace.currency', 'XOF'),
            'grossPaid' => $gross,
            'platformFees' => $fees,
            'pendingSettlement' => $pending,
            'eligibleForNextPayout' => $eligible,
            'withdrawalReserved' => $reserved,
            'paidOut' => $paidOut,
            'payoutRequest' => $requestRow ? [
                'id' => $requestRow->public_id,
                'amount' => (int) $requestRow->amount,
                'status' => $requestRow->status,
            ] : null,
            'nextPayoutAt' => null,
            'payoutScheduleLabel' => sprintf(
                'Règlement Cèlè One chaque %s à %s après validation des transactions.',
                config('marketplace.payout_weekday', 'friday'),
                config('marketplace.payout_time', '17:00')
            ),
        ]]);
    }
}
