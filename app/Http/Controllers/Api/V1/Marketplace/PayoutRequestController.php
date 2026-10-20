<?php

namespace App\Http\Controllers\Api\V1\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Marketplace\Shop;
use App\Services\Marketplace\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PayoutRequestController extends Controller
{
    public function index(Request $request)
    {
        $shop = Shop::where('owner_id', $request->user()->id)->firstOrFail();

        $rows = DB::table('marketplace_payout_requests')
            ->where('shop_id', $shop->id)
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn ($row) => $this->present($row));

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'note' => 'nullable|string|max:2000',
        ]);

        $shop = Shop::where('owner_id', $request->user()->id)->firstOrFail();
        abort_unless($shop->status === 'approved', 409, 'Shop must be approved.');

        return DB::transaction(function () use ($request, $data, $shop) {
            $active = DB::table('marketplace_payout_requests')
                ->where('shop_id', $shop->id)
                ->whereIn('status', ['pending','approved','batched'])
                ->lockForUpdate()
                ->exists();

            abort_if($active, 409, 'A payout request is already being processed.');

            $entries = DB::table('marketplace_ledger_entries')
                ->where('shop_id', $shop->id)
                ->where('type', 'seller_credit')
                ->where('status', 'eligible')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            abort_if($entries->isEmpty(), 409, 'No completed revenue is currently eligible for payout.');

            $amount = (int) $entries->sum('amount');
            abort_if($amount <= 0, 409, 'No positive balance is eligible for payout.');

            $publicId = (string) Str::uuid();
            $id = DB::table('marketplace_payout_requests')->insertGetId([
                'public_id' => $publicId,
                'shop_id' => $shop->id,
                'user_id' => $request->user()->id,
                'amount' => $amount,
                'currency' => config('marketplace.currency', 'XOF'),
                'ledger_entry_ids' => json_encode($entries->pluck('id')->values()->all()),
                'status' => 'pending',
                'seller_note' => $data['note'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('marketplace_ledger_entries')
                ->whereIn('id', $entries->pluck('id'))
                ->update(['status' => 'reserved', 'updated_at' => now()]);

            Audit::log($request->user()->id, 'payout.requested', 'payout_request', $publicId, [
                'amount' => $amount,
                'shop_id' => $shop->public_id,
            ]);

            $row = DB::table('marketplace_payout_requests')->where('id', $id)->first();
            return response()->json(['data' => $this->present($row)], 201);
        }, 3);
    }

    private function present(object $row): array
    {
        return [
            'id' => $row->public_id,
            'amount' => (int) $row->amount,
            'currency' => $row->currency,
            'status' => $row->status,
            'sellerNote' => $row->seller_note,
            'adminNote' => $row->admin_note,
            'createdAt' => optional($row->created_at ? now()->parse($row->created_at) : null)?->toIso8601String(),
            'reviewedAt' => optional($row->reviewed_at ? now()->parse($row->reviewed_at) : null)?->toIso8601String(),
            'paidAt' => optional($row->paid_at ? now()->parse($row->paid_at) : null)?->toIso8601String(),
        ];
    }
}
