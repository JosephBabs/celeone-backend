<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;

class ManagementSystemController extends Controller
{
    public function health()
    {
        $database = ['ok' => false, 'detail' => 'Unavailable'];
        $storage = ['ok' => false, 'detail' => 'Unavailable'];

        try {
            DB::select('select 1');
            $database = [
                'ok' => true,
                'detail' => DB::connection()->getDriverName() . ' connected',
            ];
        } catch (\Throwable $e) {
            $database['detail'] = $e->getMessage();
        }

        try {
            $disk = config('management.upload_disk', 'public');
            Storage::disk($disk)->exists('.');
            $storage = ['ok' => true, 'detail' => "Laravel storage disk {$disk} reachable"];
        } catch (\Throwable $e) {
            $storage['detail'] = $e->getMessage();
        }

        $services = [
            'cdn' => $this->httpService(
                config('management.health.cdn_url')
            ),

            'live' => $this->httpService(
                config('management.health.live_url')
            ),

            'notifications' => $this->httpService(
                config('management.health.notifications_url')
            ),

            'socket' => $this->tcpService(
                (string) config(
                    'management.health.socket_host',
                    'socket.celeonetv.com'
                ),
                (int) config(
                    'management.health.socket_port',
                    443
                )
            ),
        ];

        return response()->json(['data' => [
            'ok' => $database['ok'],
            'database' => $database,
            'storage' => $storage,
            'queue' => ['ok' => true, 'detail' => config('queue.default')],
            'cache' => ['ok' => app()->bound('cache'), 'detail' => config('cache.default')],
            'environment' => app()->environment(),
            'services' => $services,
            'time' => now()->toISOString(),
        ]]);
    }

    private function httpService(?string $url): array
    {
        if (! $url) return ['ok' => false, 'detail' => 'Not configured'];
        $started = microtime(true);
        try {
            $response = Http::timeout(5)->acceptJson()->get($url);
            return [
                'ok' => $response->successful(),
                'detail' => $url . ' · HTTP ' . $response->status(),
                'latency' => (int) round((microtime(true) - $started) * 1000),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'detail' => $e->getMessage(),
                'latency' => (int) round((microtime(true) - $started) * 1000),
            ];
        }
    }

    private function tcpService(string $host, int $port): array
    {
        $started = microtime(true);
        $errno = 0; $errstr = '';
        $socket = @fsockopen($host, $port, $errno, $errstr, 3);
        $ok = is_resource($socket);
        if ($ok) fclose($socket);
        return [
            'ok' => $ok,
            'detail' => $ok ? "{$host}:{$port}" : ($errstr ?: "TCP {$errno}"),
            'latency' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    public function grantRole(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['nullable','string','max:191'],
            'email' => ['nullable','email'],
            'full_name' => ['nullable','string','max:255'],
            'role' => ['required','in:admin,super_admin'],
        ]);

        $query = User::query();
        if (! empty($data['user_id']) && ctype_digit((string) $data['user_id'])) {
            $query->whereKey((int) $data['user_id']);
        } elseif (! empty($data['email'])) {
            $query->whereRaw('lower(email) = ?', [strtolower($data['email'])]);
        } else {
            abort(422, 'user_id or email is required.');
        }

        $user = $query->first();
        abort_unless($user, 404, 'User not found.');

        $user->role = $data['role'];
        if (! empty($data['full_name']) && Schema::hasColumn($user->getTable(), 'name')) {
            $user->name = $data['full_name'];
        }
        $user->save();

        if (Schema::hasTable('management_audit_logs')) {
            DB::table('management_audit_logs')->insert([
                'admin_user_id' => $request->user()->id,
                'action' => 'grant_role',
                'collection_path' => 'users',
                'document_id' => (string) $user->id,
                'changes' => json_encode(['role' => $data['role']]),
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 4000),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json(['data' => [
            'id' => $user->id,
            'uid' => (string) $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'role' => $user->role,
        ]]);
    }
}
