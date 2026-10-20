<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CeleOneMigrationStatus extends Command
{
    protected $signature = 'celeone:migration-status {--json : Print machine-readable JSON}';

    protected $description = 'Show staged Firebase records, migration ledger state, conflicts and reconciliation readiness.';

    public function handle(): int
    {
        $tables = [
            'firebase_documents' => 'staged_documents',
            'legacy_snapshots' => 'legacy_snapshots',
            'migration_ledger' => 'ledger_entries',
            'migration_conflicts' => 'open_conflicts',
        ];
        $status = [];
        foreach ($tables as $table => $key) {
            $query = DB::table($table);
            if ($table === 'migration_conflicts') {
                $query->where('status', 'open');
            }
            $status[$key] = $query->count();
        }
        $status['processed_ledger_entries'] = DB::table('migration_ledger')->where('status', 'processed')->count();
        $status['failed_ledger_entries'] = DB::table('migration_ledger')->where('status', 'failed')->count();
        $status['checksum_mismatches'] = DB::table('migration_ledger')->where('status', 'checksum_mismatch')->count();

        if ($this->option('json')) {
            $this->line(json_encode($status, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            foreach ($status as $name => $value) {
                $this->line(str_pad($name, 28).' '.$value);
            }
        }

        return self::SUCCESS;
    }
}
