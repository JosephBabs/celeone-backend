<?php

namespace App\Services\Marketplace;

use App\Models\Marketplace\Listing;
use App\Models\Marketplace\Order;
use App\Models\Marketplace\OrderItem;
use App\Models\Marketplace\StockReservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class OrderService
{
    public function create(User $buyer, array $payload): Order
    {
        return DB::transaction(function () use ($buyer, $payload) {
            $requested = $payload['items'] ?? null;
            if (!$requested) {
                $requested = [[
                    'listingId' => $payload['listingId'] ?? null,
                    'quantity' => $payload['quantity'] ?? 1,
                ]];
            }

            if (!is_array($requested) || count($requested) < 1 || count($requested) > 30) {
                throw ValidationException::withMessages(['items' => ['Provide between 1 and 30 items.']]);
            }

            $normalized = [];
            foreach ($requested as $row) {
                $id = trim((string) ($row['listingId'] ?? ''));
                $qty = max(1, min(99, (int) ($row['quantity'] ?? 1)));
                if ($id === '') throw ValidationException::withMessages(['items' => ['Each item requires listingId.']]);
                $normalized[$id] = ($normalized[$id] ?? 0) + $qty;
            }

            /** @var \Illuminate\Support\Collection<int, Listing> $listings */
            $listings = Listing::query()
                ->whereIn('public_id', array_keys($normalized))
                ->with('shop')
                ->lockForUpdate()
                ->get();

            if ($listings->count() !== count($normalized)) {
                throw ValidationException::withMessages(['items' => ['One or more offers no longer exist.']]);
            }

            $shopIds = $listings->pluck('shop_id')->unique();
            if ($shopIds->count() !== 1) {
                throw ValidationException::withMessages(['items' => ['A checkout can contain offers from only one shop.']]);
            }

            $subtotal = 0;
            foreach ($listings as $listing) {
                if ($listing->status !== 'approved' || !$listing->available) {
                    throw ValidationException::withMessages(['items' => ["{$listing->title} is not available for purchase."]]);
                }
                if ($listing->pricing_mode === 'quote' || $listing->price === null) {
                    throw ValidationException::withMessages(['items' => ["{$listing->title} requires a quote before payment."]]);
                }

                $qty = $normalized[$listing->public_id];
                if ($listing->kind === 'service' && $qty !== 1) {
                    $qty = $normalized[$listing->public_id] = 1;
                }

                if ($listing->stock_mode === 'tracked') {
                    $available = max(0, (int) $listing->stock_quantity - (int) $listing->reserved_quantity);
                    if ($available < $qty) {
                        throw ValidationException::withMessages(['items' => ["Insufficient stock for {$listing->title}."]]);
                    }
                }
                if ($listing->stock_mode === 'unavailable') {
                    throw ValidationException::withMessages(['items' => ["{$listing->title} is unavailable."]]);
                }

                $subtotal += (int) $listing->price * $qty;
            }

            $shop = $listings->first()->shop;
            $deliveryMethod = (string) ($payload['deliveryMethod'] ?? 'pickup');
            if (!in_array($deliveryMethod, ['pickup', 'delivery', 'service'], true)) {
                throw ValidationException::withMessages(['deliveryMethod' => ['Invalid delivery method.']]);
            }

            if ($deliveryMethod === 'delivery' && trim((string) ($payload['deliveryAddress'] ?? '')) === '') {
                throw ValidationException::withMessages(['deliveryAddress' => ['Delivery address is required.']]);
            }

            $deliveryFee = $deliveryMethod === 'delivery' ? (int) $shop->delivery_fee : 0;
            $feePercent = max(0, (float) config('marketplace.platform_fee_percent', 0));
            $platformFee = (int) round(($subtotal * $feePercent / 100) + max(0, (int) config('marketplace.platform_fee_flat', 0)));
            $sellerNet = max(0, $subtotal - $platformFee);
            $total = $subtotal + $deliveryFee;

            $order = Order::create([
                'public_id' => (string) Str::uuid(),
                'buyer_id' => $buyer->id,
                'shop_id' => $shop->id,
                'business_type' => $shop->business_type,
                'status' => 'pending_payment',
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'platform_fee' => $platformFee,
                'seller_net' => $sellerNet,
                'total' => $total,
                'currency' => config('marketplace.currency', 'XOF'),
                'delivery_method' => $deliveryMethod,
                'delivery_address' => $payload['deliveryAddress'] ?? null,
                'buyer_phone' => $payload['buyerPhone'] ?? null,
                'payment_status' => 'pending',
            ]);

            $expiresAt = now()->addMinutes(max(5, (int) config('marketplace.reservation_minutes', 20)));

            foreach ($listings as $listing) {
                $qty = $normalized[$listing->public_id];

                OrderItem::create([
                    'order_id' => $order->id,
                    'listing_id' => $listing->id,
                    'listing_public_id' => $listing->public_id,
                    'kind' => $listing->kind,
                    'category' => $listing->category,
                    'title' => $listing->title,
                    'image_urls' => $listing->images ?: [],
                    'quantity' => $qty,
                    'unit_price' => (int) $listing->price,
                    'line_total' => (int) $listing->price * $qty,
                    'currency' => $listing->currency,
                ]);

                if ($listing->stock_mode === 'tracked') {
                    $listing->increment('reserved_quantity', $qty);
                    StockReservation::create([
                        'order_id' => $order->id,
                        'listing_id' => $listing->id,
                        'quantity' => $qty,
                        'status' => 'active',
                        'expires_at' => $expiresAt,
                    ]);
                }
            }

            Audit::log($buyer->id, 'order.created', 'order', $order->public_id, ['total' => $total]);
            return $order->fresh(['items', 'shop']);
        }, 3);
    }

    public function markPaid(Order $order, string $providerTransactionId, array $providerPayload = []): Order
    {
        return DB::transaction(function () use ($order, $providerTransactionId, $providerPayload) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (in_array($order->payment_status, ['paid', 'approved'], true)) return $order->fresh(['items', 'shop']);

            if ($order->status !== 'pending_payment') {
                throw ValidationException::withMessages(['order' => ['Order can no longer be paid.']]);
            }

            $reservations = StockReservation::query()
                ->where('order_id', $order->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->get();

            foreach ($reservations as $reservation) {
                $listing = Listing::query()->whereKey($reservation->listing_id)->lockForUpdate()->first();
                if (!$listing) throw ValidationException::withMessages(['stock' => ['An item is no longer available.']]);

                $qty = (int) $reservation->quantity;
                if ($listing->stock_mode === 'tracked') {
                    if ((int) $listing->stock_quantity < $qty) {
                        throw ValidationException::withMessages(['stock' => ["Insufficient final stock for {$listing->title}."]]);
                    }
                    $listing->stock_quantity = max(0, (int) $listing->stock_quantity - $qty);
                    $listing->reserved_quantity = max(0, (int) $listing->reserved_quantity - $qty);
                    if ($listing->stock_quantity === 0) $listing->available = false;
                    $listing->save();
                }

                $reservation->update([
                    'status' => 'consumed',
                    'consumed_at' => now(),
                ]);
            }

            $order->update([
                'status' => 'paid',
                'payment_status' => 'paid',
                'provider' => 'fedapay',
                'provider_transaction_id' => $providerTransactionId,
                'paid_at' => now(),
            ]);

            Audit::log($order->buyer_id, 'order.paid', 'order', $order->public_id, [
                'provider_transaction_id' => $providerTransactionId,
            ]);

            $paidOrder = $order->fresh(['items', 'shop']);
            foreach ($paidOrder->items as $item) {
                if (!$item->listing_id) continue;
                $listing = Listing::with('shop')->find($item->listing_id);
                if (!$listing) continue;
                app(DiscoveryService::class)->record(
                    $order->buyer_id,
                    $listing,
                    'purchase',
                    'checkout',
                    $order->public_id
                );
            }

            return $paidOrder;
        }, 3);
    }

    public function releaseReservations(Order $order, string $reason = 'expired'): void
    {
        DB::transaction(function () use ($order, $reason) {
            $reservations = StockReservation::query()
                ->where('order_id', $order->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->get();

            foreach ($reservations as $reservation) {
                $listing = Listing::query()->whereKey($reservation->listing_id)->lockForUpdate()->first();
                if ($listing && $listing->stock_mode === 'tracked') {
                    $listing->reserved_quantity = max(0, (int) $listing->reserved_quantity - (int) $reservation->quantity);
                    $listing->save();
                }
                $reservation->update(['status' => 'released', 'released_at' => now()]);
            }

            if ($order->status === 'pending_payment') {
                $order->update(['status' => 'cancelled', 'payment_status' => $reason]);
            }
        }, 3);
    }
}
