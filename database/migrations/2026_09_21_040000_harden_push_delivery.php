<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('devices')) {
            Schema::create('devices', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('device_id', 191);
                $table->string('platform', 30)->nullable();
                $table->string('app_version', 50)->nullable();
                $table->timestampTz('last_seen_at')->nullable();
                $table->timestampsTz();
                $table->unique(['user_id', 'device_id']);
            });
        }

        if (! Schema::hasTable('push_tokens')) {
            Schema::create('push_tokens', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
                $table->string('provider', 30)->default('fcm');
                $table->text('token')->unique();
                $table->timestampTz('last_used_at')->nullable();
                $table->timestampsTz();
            });
        }
    }

    public function down(): void
    {
        // These tables are shared authentication/device infrastructure. Never
        // drop them automatically from a feature rollback.
    }
};
