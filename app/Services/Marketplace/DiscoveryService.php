<?php

namespace App\Services\Marketplace;

use App\Models\Marketplace\Listing;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DiscoveryService
{
    private const WEIGHTS = [
        'impression' => 0.00,
        'open' => 1.00,
        'favorite' => 2.00,
        'cart' => 3.00,
        'quote' => 3.50,
        'purchase' => 6.00,
    ];

    public function record(
        User|int $user,
        Listing $listing,
        string $event,
        string $surface = 'marketplace',
        ?string $contextId = null
    ): bool {
        $event = strtolower(trim($event));
        abort_unless(array_key_exists($event, self::WEIGHTS), 422, 'Unsupported marketplace discovery event.');

        $userId = $user instanceof User ? (int) $user->id : (int) $user;
        $listing->loadMissing('shop');

        $bucket = $this->bucket($event);
        $dedupeKey = hash('sha256', implode('|', [
            $userId,
            $listing->id,
            $event,
            $surface,
            $contextId ?: '-',
            $bucket,
        ]));

        $now = now();
        $inserted = DB::table('marketplace_discovery_events')->insertOrIgnore([
            'dedupe_key' => $dedupeKey,
            'user_id' => $userId,
            'listing_id' => $listing->id,
            'shop_id' => $listing->shop_id,
            'category' => $listing->category,
            'event' => $event,
            'surface' => mb_substr($surface ?: 'marketplace', 0, 60),
            'context_id' => $contextId ? mb_substr($contextId, 0, 120) : null,
            'weight' => self::WEIGHTS[$event],
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if (!$inserted) return false;

        $this->incrementInterest($userId, (string) $listing->category, $event, $now);
        $this->incrementSellerSignal($userId, (int) $listing->shop_id, $event, $now);

        return true;
    }

    public function recordByPublicId(
        User|int $user,
        string $listingPublicId,
        string $event,
        string $surface = 'marketplace',
        ?string $contextId = null
    ): bool {
        $listing = Listing::with('shop')->where('public_id', $listingPublicId)->firstOrFail();
        return $this->record($user, $listing, $event, $surface, $contextId);
    }

    private function incrementInterest(int $userId, string $category, string $event, $now): void
    {
        $column = $this->counterColumn($event);
        $weight = (float) self::WEIGHTS[$event];

        DB::statement(
            "
            INSERT INTO marketplace_user_interest_profiles
                (user_id, category, score, {$column}, last_event_at, created_at, updated_at)
            VALUES (?, ?, ?, 1, ?, ?, ?)
            ON CONFLICT (user_id, category)
            DO UPDATE SET
                score = LEAST(100, marketplace_user_interest_profiles.score + EXCLUDED.score),
                {$column} = marketplace_user_interest_profiles.{$column} + 1,
                last_event_at = EXCLUDED.last_event_at,
                updated_at = EXCLUDED.updated_at
            ",
            [$userId, $category, $weight, $now, $now, $now]
        );
    }

    private function incrementSellerSignal(int $userId, int $shopId, string $event, $now): void
    {
        $column = $this->counterColumn($event);
        $weight = (float) self::WEIGHTS[$event];

        DB::statement(
            "
            INSERT INTO marketplace_user_seller_signals
                (user_id, shop_id, score, {$column}, last_event_at, created_at, updated_at)
            VALUES (?, ?, ?, 1, ?, ?, ?)
            ON CONFLICT (user_id, shop_id)
            DO UPDATE SET
                score = LEAST(100, marketplace_user_seller_signals.score + EXCLUDED.score),
                {$column} = marketplace_user_seller_signals.{$column} + 1,
                last_event_at = EXCLUDED.last_event_at,
                updated_at = EXCLUDED.updated_at
            ",
            [$userId, $shopId, $weight, $now, $now, $now]
        );
    }

    private function counterColumn(string $event): string
    {
        return match ($event) {
            'impression' => 'impressions',
            'open' => 'opens',
            'favorite' => 'favorites',
            'cart' => 'carts',
            'quote' => 'quotes',
            'purchase' => 'purchases',
            default => 'opens',
        };
    }

    private function bucket(string $event): string
    {
        $now = now();

        return match ($event) {
            'impression' => $now->format('YmdH'),
            'open' => $now->format('YmdH') . ':' . intdiv((int) $now->format('i'), 10),
            'cart' => $now->format('YmdH') . ':' . intdiv((int) $now->format('i'), 30),
            'favorite', 'quote' => $now->format('Ymd'),
            // Purchases pass the order id as contextId, so the context keeps
            // each distinct order while retries remain idempotent.
            'purchase' => 'purchase',
            default => $now->format('YmdH'),
        };
    }
}
