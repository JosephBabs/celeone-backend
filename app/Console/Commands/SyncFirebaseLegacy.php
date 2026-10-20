<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class SyncFirebaseLegacy extends Command
{
    protected $signature = 'firebase:sync-legacy {--once} {--interval=60}';
    protected $description = 'Continuously import Firebase users, user data and posts while clients migrate to the API.';

    public function handle(): int
    {
        $script = (string) env('FIREBASE_SYNC_SCRIPT', base_path('tools/firebase-targeted-sync.mjs'));
        $workdir = (string) env('FIREBASE_SYNC_WORKDIR', base_path());
        $file = (string) env('FIREBASE_SYNC_FILE', storage_path('app/firebase-targeted.ndjson'));
        if (! is_file($script)) {
            $this->error("Firebase sync script not found: {$script}");
            return self::FAILURE;
        }

        do {
            $result = self::FAILURE;
            $authFile = (string) env('FIREBASE_AUTH_FILE', storage_path('app/firebase-auth.ndjson'));
            $process = new Process(['node', $script], $workdir, array_filter([
                'GOOGLE_APPLICATION_CREDENTIALS' => env('GOOGLE_APPLICATION_CREDENTIALS'),
                'FIREBASE_SYNC_FILE' => $file,
                'FIREBASE_AUTH_FILE' => $authFile,
            ]));
            $process->setTimeout((int) env('FIREBASE_SYNC_TIMEOUT', 300));
            $process->run(fn ($type, $buffer) => $this->output->write($buffer));
            if (! $process->isSuccessful()) {
                $this->error('Firebase export failed: '.$process->getExitCodeText());
            } elseif (is_file($file)) {
                $authResult = self::SUCCESS;
                if (is_file($authFile)) {
                    $authResult = $this->call('celeone:import-firebase-auth', ['file' => $authFile, '--resume' => true]);
                }
                $dataResult = $this->call('firebase:import', ['file' => $file]);
                $result = $authResult === self::SUCCESS && $dataResult === self::SUCCESS ? self::SUCCESS : self::FAILURE;
                if ($result === self::SUCCESS) $this->info('Firebase sync completed at '.now()->toIso8601String());
            } else {
                $this->error('Firebase export did not produce the configured import file.');
            }
            if ($this->option('once')) return $result;
            sleep(max(15, (int) $this->option('interval')));
        } while (true);

        return self::SUCCESS;
    }
}
