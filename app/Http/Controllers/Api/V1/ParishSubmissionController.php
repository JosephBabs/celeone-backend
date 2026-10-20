<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ParishSubmissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $keys = $this->ownerKeys($request->user());
        $rows = DB::table('firebase_documents')
            ->where('collection_path', 'parishes')
            ->where(function ($query) use ($keys): void {
                $query->whereIn('payload->submittedBy', $keys)
                    ->orWhereIn('payload->createdBy', $keys);
            })
            ->orderByDesc('id')
            ->limit(100)
            ->get(['document_id', 'payload', 'firebase_updated_at']);

        return response()->json([
            'items' => $rows->map(fn ($row) => [
                'id' => (string) $row->document_id,
                'data' => json_decode($row->payload, true) ?: [],
                'updatedAt' => $row->firebase_updated_at,
            ])->values(),
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1200'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:120'],
            'contactName' => ['nullable', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $result = DB::transaction(function () use ($request, $id, $data): array {
            $row = DB::table('firebase_documents')
                ->where('collection_path', 'parishes')
                ->where('document_id', $id)
                ->lockForUpdate()
                ->first(['id', 'payload']);

            abort_unless($row, 404, 'Parish submission not found.');
            $payload = json_decode($row->payload, true) ?: [];
            $keys = $this->ownerKeys($request->user());
            $owners = array_filter([
                (string) ($payload['submittedBy'] ?? ''),
                (string) ($payload['createdBy'] ?? ''),
            ]);
            abort_unless(array_intersect($keys, $owners), 404, 'Parish submission not found.');

            $status = strtolower((string) ($payload['status'] ?? 'pending'));
            abort_if(in_array($status, ['published', 'active', 'approved', 'validated'], true), 403, 'Reviewed parish submissions can no longer be edited.');

            $payload = array_merge($payload, $data, [
                'title' => $data['name'],
                'locationText' => $data['address'] ?? '',
                'location' => [
                    'latitude' => (float) $data['latitude'],
                    'longitude' => (float) $data['longitude'],
                ],
                'updatedBy' => (string) ($request->user()->firebase_uid ?: $request->user()->legacy_firebase_uid ?: $request->user()->id),
                'updatedAt' => now()->toISOString(),
            ]);

            $now = now()->toISOString();
            DB::table('firebase_documents')->where('id', $row->id)->update([
                'payload' => json_encode($payload),
                'firebase_updated_at' => $now,
                'updated_at' => $now,
            ]);

            return ['id' => $id, 'data' => $payload, 'updatedAt' => $now];
        }, 3);

        return response()->json($result);
    }

    private function ownerKeys(User $user): array
    {
        return array_values(array_unique(array_filter([
            (string) $user->id,
            (string) ($user->firebase_uid ?? ''),
            (string) ($user->legacy_firebase_uid ?? ''),
        ])));
    }
}
