<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class ManagementLiveController extends Controller
{
    public function sessions()
    {
        return response()->json(['data' => $this->rows('social_live_sessions')]);
    }

    public function events()
    {
        return response()->json(['data' => $this->rows('social_live_events')]);
    }

    public function stop(Request $request, string $session)
    {
        $payload = [
            'status' => 'ended',
            'endedAtMs' => now()->getTimestampMs(),
            'endedAt' => now()->toISOString(),
            'endedBy' => (string) $request->user()->id,
            'endReason' => (string) $request->input('reason', 'admin_force_stop'),
        ];

        $table = Schema::hasTable('social_live_sessions') ? 'social_live_sessions' : null;
        if ($table) {
            $columns = Schema::getColumnListing($table);
            $patch = [];
            foreach ($payload as $key => $value) {
                $snake = \Illuminate\Support\Str::snake($key);
                if (in_array($key, $columns, true)) $patch[$key] = $value;
                elseif (in_array($snake, $columns, true)) $patch[$snake] = $value;
            }
            if ($patch) {
                $q = DB::table($table);
                if (ctype_digit($session) && in_array('id', $columns, true)) $q->where('id', $session);
                elseif (in_array('public_id', $columns, true)) $q->where('public_id', $session);
                elseif (in_array('session_id', $columns, true)) $q->where('session_id', $session);
                $q->update($patch);
            }
        } else {
            $row = DB::table('managed_documents')->where([
                'collection_path' => 'social_live_sessions',
                'document_id' => $session,
            ])->first();
            if ($row) {
                $current = json_decode((string) $row->payload, true) ?: [];
                DB::table('managed_documents')->where('id', $row->id)->update([
                    'payload' => json_encode(array_replace($current, $payload)),
                    'updated_by' => $request->user()->id,
                    'updated_at' => now(),
                ]);
            }
        }

        $controlUrl = config('services.celeone_live.stop_url');
        if ($controlUrl) {
            Http::timeout(8)->withHeaders([
                'X-CeleOne-Internal' => (string) config('services.celeone_live.internal_key'),
            ])->post($controlUrl, [
                'sessionId' => $session,
                'force' => true,
                'reason' => $payload['endReason'],
            ])->throw();
        }

        return response()->json(['data' => ['stopped' => true, 'sessionId' => $session]]);
    }

    private function rows(string $collection): array
    {
        if (Schema::hasTable($collection)) {
            return DB::table($collection)->orderByDesc('id')->limit(500)->get()
                ->map(fn ($row) => (array) $row)->all();
        }

        return DB::table('managed_documents')->where('collection_path', $collection)
            ->orderByDesc('updated_at')->limit(500)->get()
            ->map(fn ($row) => ['id' => $row->document_id, ...(json_decode((string) $row->payload, true) ?: [])])
            ->all();
    }
}
