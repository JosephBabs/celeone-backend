<?php

namespace App\Support;

use App\Models\Marketplace\Listing;
use App\Models\Marketplace\Order;
use App\Models\Marketplace\Shop;

class MarketplacePresenter
{
    public static function shop(Shop $shop): array
    {
        return [
            'id' => $shop->public_id,
            'ownerId' => (string) $shop->owner_id,
            'title' => $shop->title,
            'slug' => $shop->slug,
            'description' => $shop->description,
            'businessType' => $shop->business_type,
            'customBusinessType' => $shop->custom_business_type,
            'mode' => $shop->mode,
            'phone' => $shop->phone,
            'whatsapp' => $shop->whatsapp,
            'email' => $shop->email,
            'city' => $shop->city,
            'country' => $shop->country,
            'address' => $shop->address,
            'logoUrl' => $shop->logo_url,
            'coverUrl' => $shop->cover_url,
            'profileType' => $shop->profile_type ?? 'shop',
            'websiteUrl' => $shop->website_url,
            'registrationNumber' => $shop->registration_number,
            'hiringEnabled' => (bool) ($shop->hiring_enabled ?? false),
            'serviceFixerEnabled' => (bool) ($shop->service_fixer_enabled ?? false),
            'deliveryFee' => (int) $shop->delivery_fee,
            'status' => $shop->status,
            'rejectionReason' => $shop->rejection_reason,
            'verifiedAt' => optional($shop->verified_at)->toIso8601String(),
            'createdAt' => optional($shop->created_at)->toIso8601String(),
        ];
    }

    public static function listing(Listing $listing): array
    {
        $shop = $listing->relationLoaded('shop') ? $listing->shop : null;
        return [
            'id' => $listing->public_id,
            'shopId' => $shop?->public_id ?? (string) $listing->shop_id,
            'shopTitle' => $shop?->title,
            'kind' => $listing->kind,
            'title' => $listing->title,
            'description' => $listing->description,
            'category' => $listing->category,
            'customCategory' => $listing->custom_category,
            'images' => $listing->images ?: [],
            'pricingMode' => $listing->pricing_mode,
            'price' => $listing->price === null ? null : (int) $listing->price,
            'compareAtPrice' => $listing->compare_at_price === null ? null : (int) $listing->compare_at_price,
            'currency' => $listing->currency,
            'unitLabel' => $listing->unit_label,
            'stockMode' => $listing->stock_mode,
            'stockQuantity' => $listing->stock_quantity === null ? null : (int) $listing->stock_quantity,
            'availableQuantity' => $listing->availableQuantity(),
            'available' => (bool) $listing->available,
            'status' => $listing->status,
            'rejectionReason' => $listing->rejection_reason,
            'verifiedAt' => optional($listing->verified_at)->toIso8601String(),
            'createdAt' => optional($listing->created_at)->toIso8601String(),
        ];
    }

    public static function order(Order $order): array
    {
        $order->loadMissing(['items', 'shop']);
        return [
            'id' => $order->public_id,
            'publicId' => $order->public_id,
            'buyerId' => (string) $order->buyer_id,
            'shopId' => $order->shop?->public_id ?? (string) $order->shop_id,
            'shopTitle' => $order->shop?->title,
            'status' => $order->status,
            'lines' => $order->items->map(fn ($item) => [
                'listingId' => $item->listing_public_id,
                'title' => $item->title,
                'imageUrl' => data_get($item->image_urls, '0'),
                'quantity' => (int) $item->quantity,
                'unitPrice' => (int) $item->unit_price,
                'currency' => $item->currency,
            ])->values()->all(),
            'subtotal' => (int) $order->subtotal,
            'deliveryFee' => (int) $order->delivery_fee,
            'platformFee' => (int) $order->platform_fee,
            'sellerNet' => (int) $order->seller_net,
            'total' => (int) $order->total,
            'currency' => $order->currency,
            'deliveryMethod' => $order->delivery_method,
            'deliveryAddress' => $order->delivery_address,
            'buyerPhone' => $order->buyer_phone,
            'paymentStatus' => $order->payment_status,
            'provider' => $order->provider,
            'providerTransactionId' => $order->provider_transaction_id,
            'createdAt' => optional($order->created_at)->toIso8601String(),
            'updatedAt' => optional($order->updated_at)->toIso8601String(),
        ];
    }
}
