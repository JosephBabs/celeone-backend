<?php

namespace App\Http\Controllers\Api\V1\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Marketplace\Payment;
use App\Services\Marketplace\FedaPayService;
use App\Services\Marketplace\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FedaPayWebhookController extends Controller
{
    public function __invoke(Request $request, FedaPayService $fedapay, OrderService $orders)
    {
        $secret = (string) config('marketplace.fedapay.webhook_secret');
        abort_if($secret === '', 503, 'FedaPay webhook secret is not configured.');

        $signature = (string) $request->header('X-FedaPay-Signature', '');
        abort_if($signature === '', 400, 'Missing FedaPay signature.');

        if (!class_exists(\FedaPay\Webhook::class)) {
            abort(503, 'FedaPay PHP SDK is missing.');
        }

        try {
            $event = \FedaPay\Webhook::constructEvent($request->getContent(), $signature, $secret);
        } catch (\Throwable $e) {
            Log::warning('MARKETPLACE_FEDAPAY_WEBHOOK_REJECTED', ['message' => $e->getMessage()]);
            abort(400, 'Invalid webhook signature.');
        }

        $payload = json_decode(json_encode($event), true) ?: [];
        $providerId = (string) (
            data_get($payload, 'entity.id')
            ?? data_get($payload, 'data.entity.id')
            ?? data_get($payload, 'transaction.id')
            ?? ''
        );

        if ($providerId === '') return response()->json(['ok' => true]);

        $payment = Payment::with('order')->where('provider_transaction_id', $providerId)->first();
        if (!$payment) return response()->json(['ok' => true]);

        // Retrieve from FedaPay rather than trusting event body fields.
        $remote = $fedapay->retrieve($providerId);
        $status = $fedapay->statusFromPayload($remote);

        $payment->update([
            'status' => $fedapay->isApproved($status) ? 'approved' : $status,
            'provider_payload' => $remote,
            'confirmed_at' => $fedapay->isApproved($status) ? now() : $payment->confirmed_at,
        ]);

        if ($fedapay->isApproved($status)) {
            $orders->markPaid($payment->order, $providerId, $remote);
        } elseif (in_array($status, ['failed','canceled','cancelled','declined'], true)) {
            $orders->releaseReservations($payment->order, $status);
        }

        return response()->json(['ok' => true]);
    }
}
