<?php

namespace App\Http\Controllers\Api\V1\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Marketplace\Order;
use App\Models\Marketplace\Shop;
use App\Services\Marketplace\Audit;
use App\Services\Marketplace\OrderService;
use App\Support\MarketplacePresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    public function store(Request $request, OrderService $orders)
    {
        $data = $request->validate([
            'listingId' => 'nullable|string|max:36',
            'quantity' => 'nullable|integer|min:1|max:99',
            'items' => 'nullable|array|min:1|max:30',
            'items.*.listingId' => 'required_with:items|string|max:36',
            'items.*.quantity' => 'required_with:items|integer|min:1|max:99',
            'deliveryMethod' => 'required|in:pickup,delivery,service',
            'deliveryAddress' => 'nullable|string|max:2000',
            'buyerPhone' => 'required|string|max:40',
        ]);

        $order = $orders->create($request->user(), $data);
        return response()->json(['data' => MarketplacePresenter::order($order)], 201);
    }

    public function mine(Request $request)
    {
        $scope = $request->query('scope', 'buyer');
        if ($scope === 'seller') {
            $shop = Shop::where('owner_id', $request->user()->id)->firstOrFail();
            $query = Order::with(['items','shop'])->where('shop_id', $shop->id);
        } else {
            $query = Order::with(['items','shop'])->where('buyer_id', $request->user()->id);
        }

        $rows = $query->latest()->paginate(50);
        return response()->json([
            'data' => $rows->getCollection()->map(fn ($x) => MarketplacePresenter::order($x))->values(),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()],
        ]);
    }

    public function show(Request $request, string $order)
    {
        $model = Order::with(['items','shop'])->where('public_id', $order)->firstOrFail();
        $owns = $model->buyer_id === $request->user()->id || $model->shop?->owner_id === $request->user()->id || $request->user()->isAdmin();
        abort_unless($owns, 403);
        return response()->json(['data' => MarketplacePresenter::order($model)]);
    }

    public function sellerStatus(Request $request, string $order)
    {
        $data = $request->validate(['status' => 'required|in:processing,ready,seller_completed']);
        $model = Order::with('shop')->where('public_id', $order)->firstOrFail();
        abort_unless($model->shop?->owner_id === $request->user()->id, 403);

        $allowed = [
            'paid' => 'processing',
            'processing' => 'ready',
            'ready' => 'seller_completed',
        ];
        abort_unless(($allowed[$model->status] ?? null) === $data['status'], 409, 'Invalid order status transition.');

        $model->status = $data['status'];
        if ($data['status'] === 'seller_completed') $model->seller_completed_at = now();
        $model->save();

        Audit::log($request->user()->id, 'order.seller_status', 'order', $model->public_id, ['status' => $model->status]);
        return response()->json(['data' => MarketplacePresenter::order($model)]);
    }

    public function received(Request $request, string $order)
    {
        $model = Order::where('public_id', $order)->where('buyer_id', $request->user()->id)->firstOrFail();
        abort_unless(in_array($model->status, ['ready','seller_completed'], true), 409, 'Order cannot be marked received yet.');

        $model->update(['status' => 'buyer_received', 'buyer_received_at' => now()]);
        Audit::log($request->user()->id, 'order.buyer_received', 'order', $model->public_id);

        return response()->json(['data' => MarketplacePresenter::order($model)]);
    }

    public function dispute(Request $request, string $order)
    {
        $data = $request->validate(['reason' => 'required|string|min:8|max:5000']);
        $model = Order::where('public_id', $order)->where('buyer_id', $request->user()->id)->firstOrFail();
        abort_unless(!in_array($model->status, ['completed','cancelled','refunded'], true), 409, 'Order is already closed.');

        DB::table('marketplace_disputes')->insert([
            'public_id' => (string) Str::uuid(),
            'order_id' => $model->id,
            'opened_by' => $request->user()->id,
            'reason' => $data['reason'],
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $model->update(['status' => 'disputed']);

        Audit::log($request->user()->id, 'order.disputed', 'order', $model->public_id);
        return response()->json(['data' => MarketplacePresenter::order($model)]);
    }
}
