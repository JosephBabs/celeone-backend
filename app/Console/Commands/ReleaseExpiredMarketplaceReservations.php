<?php

namespace App\Console\Commands;

use App\Models\Marketplace\Order;
use App\Models\Marketplace\StockReservation;
use App\Services\Marketplace\OrderService;
use Illuminate\Console\Command;

class ReleaseExpiredMarketplaceReservations extends Command
{
    protected $signature = 'marketplace:release-expired-reservations {--limit=500}';
    protected $description = 'Release expired Cèlè Market stock reservations';

    public function handle(OrderService $orders): int
    {
        $orderIds = StockReservation::query()
            ->where('status', 'active')
            ->where('expires_at', '<=', now())
            ->limit(max(1, (int) $this->option('limit')))
            ->pluck('order_id')
            ->unique();

        $count = 0;
        foreach ($orderIds as $orderId) {
            $order = Order::find($orderId);
            if ($order && $order->status === 'pending_payment') {
                $orders->releaseReservations($order, 'expired');
                $count++;
            }
        }

        $this->info("Released {$count} expired marketplace order reservation(s).");
        return self::SUCCESS;
    }
}
