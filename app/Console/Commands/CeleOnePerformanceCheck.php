<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CeleOnePerformanceCheck extends Command
{
    protected $signature = 'celeone:performance:check';
    protected $description = 'Check Cèlè One production performance dependencies without changing data.';

    public function handle(): int
    {
        $ok = true;

        $this->newLine();
        $this->info('Cèlè One backend performance check');
        $this->line('APP_ENV: ' . app()->environment());
        $this->line('APP_DEBUG: ' . (config('app.debug') ? 'true' : 'false'));
        $this->line('DB: ' . DB::getDriverName());
        $this->line('CACHE_STORE: ' . config('cache.default'));
        $this->line('QUEUE_CONNECTION: ' . config('queue.default'));

        try {
            DB::select('select 1');
            $this->info('[OK] PostgreSQL connection');
        } catch (Throwable $e) {
            $ok = false;
            $this->error('[FAIL] PostgreSQL: ' . $e->getMessage());
        }

        try {
            $pong = Redis::connection()->ping();
            $this->info('[OK] Redis: ' . (is_string($pong) ? $pong : 'PONG'));
        } catch (Throwable $e) {
            $ok = false;
            $this->error('[FAIL] Redis: ' . $e->getMessage());
        }

        try {
            $key = 'celeone:performance:probe:' . getmypid();
            Cache::put($key, 'ok', 20);
            $value = Cache::get($key);
            Cache::forget($key);
            if ($value !== 'ok') {
                throw new \RuntimeException('cache round-trip returned an unexpected value');
            }
            $this->info('[OK] Cache round-trip');
        } catch (Throwable $e) {
            $ok = false;
            $this->error('[FAIL] Cache: ' . $e->getMessage());
        }

        if (DB::getDriverName() === 'pgsql') {
            try {
                $count = (int) (DB::selectOne("select count(*)::int as c from pg_indexes where indexname like 'idx_celeone_%'")->c ?? 0);
                $this->line('Cèlè One hot-path indexes: ' . $count);
                if ($count < 8) {
                    $this->warn('Hot-path index migration may not have been applied yet.');
                }
            } catch (Throwable $e) {
                $this->warn('Could not inspect PostgreSQL indexes: ' . $e->getMessage());
            }
        }

        foreach ([
            'outbox_events' => ['published_at' => null],
            'jobs' => null,
            'failed_jobs' => null,
        ] as $table => $where) {
            if (! Schema::hasTable($table)) continue;
            try {
                $query = DB::table($table);
                if ($where) {
                    foreach ($where as $column => $value) {
                        $value === null ? $query->whereNull($column) : $query->where($column, $value);
                    }
                }
                $this->line($table . ': ' . $query->count());
            } catch (Throwable $e) {
                $this->warn($table . ': unavailable (' . $e->getMessage() . ')');
            }
        }

        if (Schema::hasTable('push_tokens')) {
            $this->line('push_tokens: ' . DB::table('push_tokens')->count());
        }
        if (Schema::hasTable('push_devices') && Schema::hasColumn('push_devices', 'push_enabled')) {
            $this->line('enabled push_devices: ' . DB::table('push_devices')->where('push_enabled', true)->count());
        }

        $this->newLine();
        $ok ? $this->info('Performance foundation is healthy.') : $this->error('One or more performance dependencies need attention.');
        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
