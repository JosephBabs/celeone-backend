<?php

namespace App\Http\Controllers\Api\V1\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Marketplace\Listing;
use App\Models\Marketplace\Order;
use App\Models\Marketplace\Shop;
use App\Services\Marketplace\Audit;
use App\Support\MarketplacePresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminMarketplaceController extends Controller
{
    public function dashboard()
    {
        $paidOrders = Order::query()->whereIn('payment_status', ['paid','approved']);

        $orders = (int) (clone $paidOrders)->count();
        $completed = (int) (clone $paidOrders)->where('status', 'completed')->count();

        $byBusiness = DB::table('marketplace_orders as o')
            ->leftJoin('marketplace_shops as s', 's.id', '=', 'o.shop_id')
            ->whereIn('o.payment_status', ['paid','approved'])
            ->selectRaw("COALESCE(o.business_type, s.business_type, 'other') AS business_type, COUNT(*) AS orders, COALESCE(SUM(o.total),0) AS gross")
            ->groupByRaw("COALESCE(o.business_type, s.business_type, 'other')")
            ->orderByDesc('gross')
            ->limit(30)
            ->get()
            ->map(fn ($row) => [
                'businessType' => $row->business_type,
                'orders' => (int) $row->orders,
                'gross' => (int) $row->gross,
            ]);

        $byCategory = DB::table('marketplace_order_items as oi')
            ->join('marketplace_orders as o', 'o.id', '=', 'oi.order_id')
            ->leftJoin('marketplace_listings as l', 'l.id', '=', 'oi.listing_id')
            ->whereIn('o.payment_status', ['paid','approved'])
            ->selectRaw("COALESCE(oi.category, l.category, 'other') AS category, SUM(oi.quantity) AS units, COALESCE(SUM(oi.line_total),0) AS gross")
            ->groupByRaw("COALESCE(oi.category, l.category, 'other')")
            ->orderByDesc('gross')
            ->limit(40)
            ->get()
            ->map(fn ($row) => [
                'category' => $row->category,
                'units' => (int) $row->units,
                'gross' => (int) $row->gross,
            ]);

        $topShops = DB::table('marketplace_orders as o')
            ->join('marketplace_shops as s', 's.id', '=', 'o.shop_id')
            ->whereIn('o.payment_status', ['paid','approved'])
            ->selectRaw('s.public_id, s.title, s.business_type, COUNT(*) AS orders, COALESCE(SUM(o.total),0) AS gross, COUNT(*) FILTER (WHERE o.status = ?) AS completed', ['completed'])
            ->groupBy('s.public_id', 's.title', 's.business_type')
            ->orderByDesc('gross')
            ->limit(25)
            ->get()
            ->map(fn ($row) => [
                'shopId' => $row->public_id,
                'title' => $row->title,
                'businessType' => $row->business_type,
                'orders' => (int) $row->orders,
                'completed' => (int) $row->completed,
                'gross' => (int) $row->gross,
            ]);

        return response()->json(['data' => [
            'pendingListings' => Listing::where('status', 'pending_review')->count(),
            'pendingShops' => Shop::where('status', 'pending_review')->count(),
            'deliveries' => Order::whereIn('status', ['seller_completed','buyer_received'])->count(),
            'disputes' => DB::table('marketplace_disputes')->where('status', 'open')->count(),
            'payoutRequests' => DB::table('marketplace_payout_requests')->whereIn('status', ['pending','approved'])->count(),
            'draftPayoutBatches' => DB::table('marketplace_payout_batches')->whereIn('status', ['draft','processing'])->count(),
            'orders' => $orders,
            'completedOrders' => $completed,
            'completionRate' => $orders > 0 ? round(($completed / $orders) * 100, 2) : 0,
            'gross' => (int) (clone $paidOrders)->sum('total'),
            'sellerNet' => (int) (clone $paidOrders)->sum('seller_net'),
            'eligiblePayouts' => (int) DB::table('marketplace_ledger_entries')->where('type', 'seller_credit')->where('status', 'eligible')->sum('amount'),
            'reservedPayouts' => (int) DB::table('marketplace_ledger_entries')->where('type', 'seller_credit')->whereIn('status', ['reserved','batched'])->sum('amount'),
            'settledPayouts' => (int) DB::table('marketplace_ledger_entries')->where('type', 'seller_credit')->where('status', 'settled')->sum('amount'),
            'byBusinessType' => $byBusiness,
            'byCategory' => $byCategory,
            'topShops' => $topShops,
        ]]);
    }

    public function notifications(Request $request)
    {
        $limit = min(100, max(10, $request->integer('limit', 50)));
        $items = collect();

        Shop::with('owner:id,name,email')
            ->where('status', 'pending_review')
            ->oldest('submitted_at')
            ->limit(25)
            ->get()
            ->each(function ($shop) use ($items) {
                $items->push([
                    'id' => 'shop:' . $shop->public_id,
                    'type' => 'shop_review',
                    'priority' => 40,
                    'title' => 'Boutique à vérifier',
                    'body' => $shop->title . ' · ' . ($shop->owner?->name ?: $shop->owner?->email ?: 'Utilisateur'),
                    'status' => $shop->status,
                    'entityId' => $shop->public_id,
                    'createdAt' => optional($shop->submitted_at ?: $shop->created_at)->toIso8601String(),
                ]);
            });

        Listing::with('shop')
            ->where('status', 'pending_review')
            ->oldest('submitted_at')
            ->limit(35)
            ->get()
            ->each(function ($listing) use ($items) {
                $items->push([
                    'id' => 'listing:' . $listing->public_id,
                    'type' => 'listing_review',
                    'priority' => 45,
                    'title' => 'Offre à vérifier',
                    'body' => $listing->title . ' · ' . ($listing->shop?->title ?: 'Boutique'),
                    'status' => $listing->status,
                    'entityId' => $listing->public_id,
                    'shopId' => $listing->shop?->public_id,
                    'createdAt' => optional($listing->submitted_at ?: $listing->created_at)->toIso8601String(),
                ]);
            });

        DB::table('marketplace_disputes as d')
            ->join('marketplace_orders as o', 'o.id', '=', 'd.order_id')
            ->join('marketplace_shops as s', 's.id', '=', 'o.shop_id')
            ->where('d.status', 'open')
            ->select('d.*', 'o.public_id as order_public_id', 's.title as shop_title')
            ->oldest('d.created_at')
            ->limit(35)
            ->get()
            ->each(function ($row) use ($items) {
                $items->push([
                    'id' => 'dispute:' . $row->public_id,
                    'type' => 'dispute',
                    'priority' => 100,
                    'title' => 'Litige ouvert',
                    'body' => 'Commande ' . $row->order_public_id . ' · ' . $row->shop_title,
                    'status' => $row->status,
                    'entityId' => $row->public_id,
                    'orderId' => $row->order_public_id,
                    'reason' => $row->reason,
                    'createdAt' => (string) $row->created_at,
                ]);
            });

        Order::with('shop')
            ->whereIn('status', ['seller_completed','buyer_received'])
            ->oldest('updated_at')
            ->limit(40)
            ->get()
            ->each(function ($order) use ($items) {
                $items->push([
                    'id' => 'delivery:' . $order->public_id,
                    'type' => 'delivery_verification',
                    'priority' => $order->status === 'buyer_received' ? 80 : 60,
                    'title' => $order->status === 'buyer_received' ? 'Réception client confirmée' : 'Commande remise par le vendeur',
                    'body' => ($order->shop?->title ?: 'Boutique') . ' · ' . $order->public_id,
                    'status' => $order->status,
                    'entityId' => $order->public_id,
                    'amount' => (int) $order->total,
                    'currency' => $order->currency,
                    'createdAt' => optional($order->updated_at)->toIso8601String(),
                ]);
            });

        DB::table('marketplace_payout_requests as pr')
            ->join('marketplace_shops as s', 's.id', '=', 'pr.shop_id')
            ->join('users as u', 'u.id', '=', 'pr.user_id')
            ->whereIn('pr.status', ['pending','approved'])
            ->select('pr.*', 's.public_id as shop_public_id', 's.title as shop_title', 'u.name as user_name', 'u.email as user_email')
            ->oldest('pr.created_at')
            ->limit(35)
            ->get()
            ->each(function ($row) use ($items) {
                $items->push([
                    'id' => 'payout:' . $row->public_id,
                    'type' => 'payout_request',
                    'priority' => 75,
                    'title' => 'Demande de règlement vendeur',
                    'body' => $row->shop_title . ' · ' . ((int) $row->amount) . ' ' . $row->currency,
                    'status' => $row->status,
                    'entityId' => $row->public_id,
                    'shopId' => $row->shop_public_id,
                    'amount' => (int) $row->amount,
                    'currency' => $row->currency,
                    'createdAt' => (string) $row->created_at,
                ]);
            });

        DB::table('marketplace_payout_batches')
            ->whereIn('status', ['draft','processing'])
            ->oldest('created_at')
            ->limit(10)
            ->get()
            ->each(function ($row) use ($items) {
                $items->push([
                    'id' => 'batch:' . $row->public_id,
                    'type' => 'payout_batch',
                    'priority' => 70,
                    'title' => 'Lot de paiements vendeurs',
                    'body' => ((int) $row->seller_count) . ' vendeur(s) · ' . ((int) $row->total_amount) . ' ' . $row->currency,
                    'status' => $row->status,
                    'entityId' => $row->public_id,
                    'amount' => (int) $row->total_amount,
                    'currency' => $row->currency,
                    'createdAt' => (string) $row->created_at,
                ]);
            });

        $items = $items
            ->sort(function ($a, $b) {
                if ($a['priority'] !== $b['priority']) return $b['priority'] <=> $a['priority'];
                return strcmp((string) $b['createdAt'], (string) $a['createdAt']);
            })
            ->take($limit)
            ->values();

        return response()->json(['data' => $items, 'meta' => ['total' => $items->count()]]);
    }

    public function moderation(Request $request)
    {
        $data = $request->validate([
            'kind' => 'nullable|in:shops,listings,orders',
            'status' => 'nullable|in:pending_review,approved,rejected',
            'shopId' => 'nullable|string|max:100',
        ]);
        $kind = $data['kind'] ?? 'shops';
        $status = $data['status'] ?? 'pending_review';

        if ($kind === 'shops') {
            $rows = Shop::with('owner:id,name,email')
                ->where('status', $status)->oldest('submitted_at')->limit(100)->get()
                ->map(fn ($x) => array_merge(MarketplacePresenter::shop($x), [
                    'ownerName' => $x->owner?->name,
                    'email' => $x->owner?->email,
                ]));
        } elseif ($kind === 'listings') {
            $rows = Listing::with('shop.owner:id,name,email')
                ->when(!empty($data['shopId']), fn ($query) => $query->whereHas('shop', fn ($shop) => $shop->where('public_id', $data['shopId'])))
                ->where('status', $status)->oldest('submitted_at')->limit(100)->get()
                ->map(fn ($x) => array_merge(MarketplacePresenter::listing($x), [
                    'ownerName' => $x->shop?->owner?->name,
                    'email' => $x->shop?->owner?->email,
                ]));
        } else {
            $rows = Order::with(['items','shop'])
                ->whereIn('status', match ($status) {
                    'approved' => ['admin_verified', 'completed'],
                    'rejected' => ['cancelled', 'refunded', 'disputed'],
                    default => ['seller_completed', 'buyer_received'],
                })
                ->oldest('updated_at')->limit(100)->get()
                ->map(fn ($x) => MarketplacePresenter::order($x));
        }

        return response()->json(['data' => $rows->values()]);
    }

    public function reviewShop(Request $request, string $shop)
    {
        $data = $request->validate(['decision' => 'required|in:approved,rejected', 'reason' => 'nullable|string|max:4000']);
        if ($data['decision'] === 'rejected' && trim((string) ($data['reason'] ?? '')) === '') abort(422, 'A rejection reason is required.');

        $model = Shop::where('public_id', $shop)->firstOrFail();
        abort_unless($model->status === 'pending_review', 409, 'Shop is not awaiting review.');

        $model->update([
            'status' => $data['decision'],
            'rejection_reason' => $data['decision'] === 'rejected' ? $data['reason'] : null,
            'reviewed_by' => $request->user()->id,
            'verified_at' => $data['decision'] === 'approved' ? now() : null,
        ]);

        Audit::log($request->user()->id, 'shop.reviewed', 'shop', $model->public_id, ['decision' => $data['decision']]);
        return response()->json(['data' => MarketplacePresenter::shop($model)]);
    }

    public function reviewListing(Request $request, string $listing)
    {
        $data = $request->validate(['decision' => 'required|in:approved,rejected', 'reason' => 'nullable|string|max:4000']);
        if ($data['decision'] === 'rejected' && trim((string) ($data['reason'] ?? '')) === '') abort(422, 'A rejection reason is required.');

        $model = Listing::with('shop')->where('public_id', $listing)->firstOrFail();
        abort_unless($model->status === 'pending_review', 409, 'Offer is not awaiting review.');
        abort_unless($model->shop?->status === 'approved', 409, 'Shop is not approved.');

        $model->update([
            'status' => $data['decision'],
            'rejection_reason' => $data['decision'] === 'rejected' ? $data['reason'] : null,
            'reviewed_by' => $request->user()->id,
            'verified_at' => $data['decision'] === 'approved' ? now() : null,
        ]);

        Audit::log($request->user()->id, 'listing.reviewed', 'listing', $model->public_id, ['decision' => $data['decision']]);
        return response()->json(['data' => MarketplacePresenter::listing($model)]);
    }

    public function verifyDelivery(Request $request, string $order)
    {
        $model = Order::where('public_id', $order)->firstOrFail();
        abort_unless(in_array($model->status, ['seller_completed','buyer_received'], true), 409, 'Order is not ready for final verification.');

        return DB::transaction(function () use ($request, $model) {
            $locked = Order::whereKey($model->id)->lockForUpdate()->firstOrFail();

            $openDispute = DB::table('marketplace_disputes')->where('order_id', $locked->id)->where('status', 'open')->exists();
            abort_if($openDispute, 409, 'Resolve the open dispute before completing this transaction.');

            $locked->update([
                'status' => 'completed',
                'admin_verified_at' => now(),
                'completed_at' => now(),
                'verified_by' => $request->user()->id,
            ]);

            $exists = DB::table('marketplace_ledger_entries')
                ->where('order_id', $locked->id)->where('type', 'seller_credit')->exists();

            if (!$exists) {
                DB::table('marketplace_ledger_entries')->insert([
                    'public_id' => (string) Str::uuid(),
                    'shop_id' => $locked->shop_id,
                    'order_id' => $locked->id,
                    'type' => 'seller_credit',
                    'amount' => $locked->seller_net,
                    'currency' => $locked->currency,
                    'status' => 'eligible',
                    'description' => 'Seller revenue for completed order ' . $locked->public_id,
                    'eligible_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            Audit::log($request->user()->id, 'order.admin_verified', 'order', $locked->public_id);
            return response()->json(['data' => MarketplacePresenter::order($locked)]);
        }, 3);
    }

    public function resolveDispute(Request $request, string $dispute)
    {
        $data = $request->validate([
            'decision' => 'required|in:seller,buyer,cancelled',
            'note' => 'required|string|min:5|max:5000',
        ]);

        return DB::transaction(function () use ($request, $dispute, $data) {
            $row = DB::table('marketplace_disputes')->where('public_id', $dispute)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless($row->status === 'open', 409, 'Dispute is already resolved.');

            $order = Order::whereKey($row->order_id)->lockForUpdate()->firstOrFail();

            $resolution = match ($data['decision']) {
                'buyer' => 'resolved_in_favor_buyer',
                'seller' => 'resolved_in_favor_seller',
                default => 'cancelled',
            };

            DB::table('marketplace_disputes')->where('id', $row->id)->update([
                'status' => $resolution,
                'resolution' => $data['note'],
                'resolved_by' => $request->user()->id,
                'resolved_at' => now(),
                'updated_at' => now(),
            ]);

            if ($data['decision'] === 'buyer') {
                // Refund execution remains an explicit payment-provider action.
                // Do not falsely mark money refunded before FedaPay confirms it.
                $order->update([
                    'status' => 'disputed',
                    'payment_status' => 'refund_required',
                ]);
            } else {
                $order->update([
                    'status' => $order->buyer_received_at ? 'buyer_received' : 'seller_completed',
                ]);
            }

            Audit::log($request->user()->id, 'dispute.resolved', 'dispute', $dispute, [
                'decision' => $data['decision'],
                'order' => $order->public_id,
            ]);

            return response()->json(['data' => [
                'id' => $dispute,
                'status' => $resolution,
                'decision' => $data['decision'],
                'order' => MarketplacePresenter::order($order),
            ]]);
        }, 3);
    }

    public function reviewPayoutRequest(Request $request, string $payout)
    {
        $data = $request->validate([
            'decision' => 'required|in:approved,rejected',
            'note' => 'nullable|string|max:4000',
        ]);

        return DB::transaction(function () use ($request, $payout, $data) {
            $row = DB::table('marketplace_payout_requests')->where('public_id', $payout)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless($row->status === 'pending', 409, 'Payout request is not awaiting review.');

            $ledgerIds = json_decode((string) $row->ledger_entry_ids, true) ?: [];

            if ($data['decision'] === 'rejected') {
                DB::table('marketplace_ledger_entries')
                    ->whereIn('id', $ledgerIds)
                    ->where('status', 'reserved')
                    ->update(['status' => 'eligible', 'updated_at' => now()]);
            }

            DB::table('marketplace_payout_requests')->where('id', $row->id)->update([
                'status' => $data['decision'],
                'admin_note' => $data['note'] ?? null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);

            Audit::log($request->user()->id, 'payout.reviewed', 'payout_request', $payout, [
                'decision' => $data['decision'],
            ]);

            return response()->json(['data' => [
                'id' => $payout,
                'status' => $data['decision'],
            ]]);
        }, 3);
    }

    public function createPayoutBatch(Request $request)
    {
        $currency = strtoupper($request->input('currency', config('marketplace.currency', 'XOF')));

        return DB::transaction(function () use ($request, $currency) {
            $approvedRequests = DB::table('marketplace_payout_requests')
                ->where('currency', $currency)
                ->where('status', 'approved')
                ->lockForUpdate()
                ->get();

            $requestedLedgerIds = $approvedRequests
                ->flatMap(fn ($row) => json_decode((string) $row->ledger_entry_ids, true) ?: [])
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            $requestedEntries = $requestedLedgerIds->isEmpty()
                ? collect()
                : DB::table('marketplace_ledger_entries')
                    ->whereIn('id', $requestedLedgerIds)
                    ->where('type', 'seller_credit')
                    ->where('status', 'reserved')
                    ->where('currency', $currency)
                    ->lockForUpdate()
                    ->get();

            // Weekly Cèlè One payout also includes eligible revenue that has not
            // been manually requested by a seller.
            $eligibleEntries = DB::table('marketplace_ledger_entries')
                ->where('type', 'seller_credit')
                ->where('status', 'eligible')
                ->where('currency', $currency)
                ->lockForUpdate()
                ->get();

            $entries = $requestedEntries->concat($eligibleEntries)->unique('id')->values();
            abort_if($entries->isEmpty(), 409, 'No eligible seller revenue is available.');

            $groups = $entries->groupBy('shop_id');
            $batchPublicId = (string) Str::uuid();
            $batchId = DB::table('marketplace_payout_batches')->insertGetId([
                'public_id' => $batchPublicId,
                'status' => 'draft',
                'currency' => $currency,
                'total_amount' => (int) $entries->sum('amount'),
                'seller_count' => $groups->count(),
                'created_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($groups as $shopId => $group) {
                DB::table('marketplace_payout_items')->insert([
                    'batch_id' => $batchId,
                    'shop_id' => $shopId,
                    'amount' => (int) $group->sum('amount'),
                    'currency' => $currency,
                    'status' => 'pending',
                    'ledger_entry_ids' => json_encode($group->pluck('id')->values()->all()),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('marketplace_ledger_entries')
                    ->whereIn('id', $group->pluck('id'))
                    ->update(['status' => 'batched', 'updated_at' => now()]);
            }

            if ($approvedRequests->isNotEmpty()) {
                DB::table('marketplace_payout_requests')
                    ->whereIn('id', $approvedRequests->pluck('id'))
                    ->update([
                        'status' => 'batched',
                        'batch_id' => $batchId,
                        'updated_at' => now(),
                    ]);
            }

            Audit::log($request->user()->id, 'payout.batch_created', 'payout_batch', $batchPublicId, [
                'total_amount' => (int) $entries->sum('amount'),
                'seller_count' => $groups->count(),
            ]);

            return response()->json(['data' => [
                'id' => $batchPublicId,
                'currency' => $currency,
                'totalAmount' => (int) $entries->sum('amount'),
                'sellerCount' => $groups->count(),
                'status' => 'draft',
            ]], 201);
        }, 3);
    }

    public function markPayoutPaid(Request $request, string $batch)
    {
        $data = $request->validate(['paymentReference' => 'required|string|max:190']);

        return DB::transaction(function () use ($request, $batch, $data) {
            $row = DB::table('marketplace_payout_batches')->where('public_id', $batch)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless(in_array($row->status, ['draft','processing'], true), 409, 'Batch is already closed.');

            $items = DB::table('marketplace_payout_items')->where('batch_id', $row->id)->get();
            foreach ($items as $item) {
                $ids = json_decode((string) $item->ledger_entry_ids, true) ?: [];
                DB::table('marketplace_ledger_entries')->whereIn('id', $ids)->update([
                    'status' => 'settled',
                    'settled_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('marketplace_payout_items')
                ->where('batch_id', $row->id)
                ->update(['status' => 'paid', 'updated_at' => now()]);

            DB::table('marketplace_payout_requests')
                ->where('batch_id', $row->id)
                ->where('status', 'batched')
                ->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::table('marketplace_payout_batches')->where('id', $row->id)->update([
                'status' => 'paid',
                'paid_by' => $request->user()->id,
                'payment_reference' => $data['paymentReference'],
                'paid_at' => now(),
                'updated_at' => now(),
            ]);

            Audit::log($request->user()->id, 'payout.paid', 'payout_batch', $batch, ['reference' => $data['paymentReference']]);
            return response()->json(['data' => ['id' => $batch, 'status' => 'paid']]);
        }, 3);
    }
}
