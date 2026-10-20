<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FirebaseIncrementalImportTest extends TestCase
{
    private string $export;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        foreach (['firebase_documents', 'legacy_snapshots', 'migration_ledger'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id();
                foreach (['collection_path', 'document_id', 'parent_document_path', 'payload', 'firebase_created_at', 'firebase_updated_at', 'source_path', 'source_checksum', 'import_status', 'source_collection', 'source_document_id', 'source_created_at', 'source_updated_at', 'status', 'processed_at'] as $column) {
                    $table->text($column)->nullable();
                }
                $table->timestamps();
            });
        }
        $this->export = tempnam(sys_get_temp_dir(), 'firebase-sync-test-');
    }

    protected function tearDown(): void
    {
        if (isset($this->export)) unlink($this->export);
        parent::tearDown();
    }

    private function writeExport(string $updated = '2026-09-18T10:00:00.123456Z', string $text = 'source'): void
    {
        file_put_contents($this->export, json_encode([
            'collectionPath' => 'sync_regression', 'documentId' => '0',
            'updateTime' => $updated, 'data' => ['text' => $text],
        ])."\n");
    }

    private function import(): int
    {
        return Artisan::call('firebase:import', ['file' => $this->export, '--changed-only' => true]);
    }

    public function test_absolute_path_and_zero_document_id_import_once_even_with_rounded_database_timestamp(): void
    {
        $this->writeExport();
        $this->assertSame(0, $this->import());
        $this->assertSame(1, DB::table('firebase_documents')->count());
        // PostgreSQL timestamp precision can round the source update time.
        DB::table('firebase_documents')->update(['firebase_updated_at' => '2026-09-18T10:00:00Z']);
        $this->assertSame(0, $this->import());
        $this->assertStringContainsString('Processed 0 Firebase documents; skipped 1', Artisan::output());
        $this->assertSame(1, DB::table('migration_ledger')->count());
    }

    public function test_source_changes_apply_but_newer_local_documents_are_preserved(): void
    {
        $this->writeExport();
        $this->import();
        $this->writeExport('2026-09-18T11:00:00Z', 'changed');
        $this->assertSame(0, $this->import());
        $this->assertSame('changed', json_decode(DB::table('firebase_documents')->value('payload'), true)['text']);
        DB::table('firebase_documents')->update(['firebase_updated_at' => '2026-09-18T13:00:00Z', 'payload' => '{"text":"local"}']);
        $this->writeExport('2026-09-18T12:00:00Z', 'older source');
        $this->assertSame(0, $this->import());
        $this->assertSame('local', json_decode(DB::table('firebase_documents')->value('payload'), true)['text']);
    }

    public function test_failed_import_does_not_leave_a_partially_updated_mirror(): void
    {
        DB::statement("CREATE TRIGGER fail_snapshot BEFORE INSERT ON legacy_snapshots BEGIN SELECT RAISE(ABORT, 'test failure'); END");
        $this->writeExport();
        try {
            $this->import();
            $this->fail('Import should fail when snapshot persistence fails.');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertStringContainsString('test failure', $error->getMessage());
        }
        $this->assertSame(0, DB::table('firebase_documents')->count());
        $this->assertSame(0, DB::table('migration_ledger')->count());
    }
}
