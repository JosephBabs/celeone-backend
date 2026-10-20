<?php

namespace App\Http\Controllers\Api\V1\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Marketplace\Order;
use App\Models\Marketplace\Payment;
use App\Services\Marketplace\FedaPayService;
use App\Services\Marketplace\OrderService;
use App\Support\MarketplacePresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function checkout(Request $request, string $order, FedaPayService $fedapay)
    {
        $request->validate(['provider' => 'nullable|in:fedapay']);
        $model = Order::where('public_id', $order)->where('buyer_id', $request->user()->id)->firstOrFail();
        abort_unless($model->status === 'pending_payment' && $model->payment_status === 'pending', 409, 'Order is not payable.');

        $existing = Payment::where('order_id', $model->id)->where('status', 'pending')->latest()->first();
        if ($existing && $existing->checkout_url) {
            return response()->json(['data' => [
                'id' => $existing->public_id,
                'url' => $existing->checkout_url,
                'order' => MarketplacePresenter::order($model),
            ]]);
        }

        $payment = $fedapay->createCheckout($model, $request->user());
        $model->update([
            'provider' => 'fedapay',
            'provider_transaction_id' => $payment->provider_transaction_id,
        ]);

        return response()->json(['data' => [
            'id' => $payment->public_id,
            'url' => $payment->checkout_url,
            'order' => MarketplacePresenter::order($model),
        ]], 201);
    }

    public function confirm(Request $request, string $payment, FedaPayService $fedapay, OrderService $orders)
    {
        $model = Payment::with('order')->where('public_id', $payment)->where('user_id', $request->user()->id)->firstOrFail();

        if ($model->status === 'approved') {
            return response()->json(['data' => ['paid' => true, 'order' => MarketplacePresenter::order($model->order)]]);
        }

        $payload = $fedapay->retrieve((string) $model->provider_transaction_id);
        $status = $fedapay->statusFromPayload($payload);

        $model->update([
            'status' => $fedapay->isApproved($status) ? 'approved' : $status,
            'provider_payload' => $payload,
            'confirmed_at' => $fedapay->isApproved($status) ? now() : null,
        ]);

        if ($fedapay->isApproved($status)) {
            $order = $orders->markPaid($model->order, (string) $model->provider_transaction_id, $payload);
            return response()->json(['data' => ['paid' => true, 'order' => MarketplacePresenter::order($order)]]);
        }

        if (in_array($status, ['failed','canceled','cancelled','declined'], true)) {
            $orders->releaseReservations($model->order, $status);
        }

        return response()->json(['data' => ['paid' => false, 'status' => $status, 'order' => MarketplacePresenter::order($model->order->fresh())]]);
    }
}
