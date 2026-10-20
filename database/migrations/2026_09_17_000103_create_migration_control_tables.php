<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('legacy_firebase_uid', 128)->nullable()->unique()->after('firebase_uid');
            $table->jsonb('legacy_payload')->nullable()->after('legacy_data');
            $table->string('legacy_collection', 150)->nullable()->index();
            $table->string('legacy_path', 500)->nullable()->index();
            $table->timestampTz('legacy_created_at')->nullable();
            $table->timestampTz('legacy_updated_at')->nullable();
            $table->timestampTz('migrated_at')->nullable();
        });

        foreach (['pages', 'posts', 'comments', 'chat_rooms', 'chat_messages'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('legacy_id', 150)->nullable()->index();
                $table->string('legacy_collection', 150)->nullable()->index();
                $table->string('legacy_path', 500)->nullable()->index();
                $table->jsonb('legacy_payload')->nullable();
                $table->timestampTz('legacy_created_at')->nullable();
                $table->timestampTz('legacy_updated_at')->nullable();
                $table->timestampTz('migrated_at')->nullable();
            });
        }

        Schema::table('firebase_documents', function (Blueprint $table): void {
            $table->string('source_path', 700)->nullable()->index();
            $table->char('source_checksum', 64)->nullable()->index();
            $table->timestampTz('migrated_at')->nullable();
            $table->string('import_status', 30)->default('staged')->index();
        });

        Schema::create('migration_ledger', function (Blueprint $table): void {
            $table->id();
            $table->string('source_path', 700);
            $table->timestampTz('source_updated_at')->nullable();
            $table->char('source_checksum', 64);
            $table->string('target_type', 150)->nullable();
            $table->string('target_id', 150)->nullable();
            $table->string('status', 30)->default('staged')->index();
            $table->text('error_message')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['source_path', 'source_checksum']);
            $table->index(['source_path', 'source_updated_at']);
        });

        Schema::create('migration_conflicts', function (Blueprint $table): void {
            $table->id();
            $table->string('source_path', 700);
            $table->string('conflict_type', 80);
            $table->jsonb('source_values');
            $table->jsonb('proposed_resolution')->nullable();
            $table->string('status', 30)->default('open')->index();
            $table->text('resolution_notes')->nullable();
            $table->timestampsTz();
            $table->index(['source_path', 'status']);
        });

        Schema::create('legacy_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('source_collection', 150);
            $table->string('source_path', 700)->unique();
            $table->string('source_document_id', 150)->nullable();
            $table->timestampTz('source_created_at')->nullable();
            $table->timestampTz('source_updated_at')->nullable();
            $table->char('source_checksum', 64);
            $table->jsonb('payload');
            $table->string('status', 30)->default('staged')->index();
            $table->timestampsTz();
            $table->index(['source_collection', 'source_document_id']);
        });

        Schema::create('outbox_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_type', 150);
            $table->string('aggregate_type', 150)->nullable();
            $table->string('aggregate_id', 150)->nullable();
            $table->jsonb('payload');
            $table->timestampTz('published_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampsTz();
            $table->index(['published_at', 'created_at']);
            $table->index(['aggregate_type', 'aggregate_id']);
        });

        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('key', 150);
            $table->string('method', 10);
            $table->string('request_path', 500);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('legacy_snapshots');
        Schema::dropIfExists('migration_conflicts');
        Schema::dropIfExists('migration_ledger');
        Schema::table('firebase_documents', function (Blueprint $table): void {
            $table->dropColumn(['source_path', 'source_checksum', 'migrated_at', 'import_status']);
        });
        foreach (['pages', 'posts', 'comments', 'chat_rooms', 'chat_messages'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn(['legacy_id', 'legacy_collection', 'legacy_path', 'legacy_payload', 'legacy_created_at', 'legacy_updated_at', 'migrated_at']);
            });
        }
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['legacy_firebase_uid', 'legacy_payload', 'legacy_collection', 'legacy_path', 'legacy_created_at', 'legacy_updated_at', 'migrated_at']);
        });
    }
};
