<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_data', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('firebase_uid', 150)->index();
            $table->string('display_name')->nullable();
            $table->string('username', 150)->nullable();
            $table->text('bio')->nullable();
            $table->string('phone_number', 40)->nullable();
            $table->string('avatar_url')->nullable();
            $table->string('role', 40)->default('user')->index();
            $table->jsonb('profile_data')->nullable();
            $table->string('legacy_collection', 150)->default('user_data');
            $table->string('legacy_path', 700)->nullable()->index();
            $table->jsonb('legacy_payload')->nullable();
            $table->timestampTz('legacy_created_at')->nullable();
            $table->timestampTz('legacy_updated_at')->nullable();
            $table->timestampTz('migrated_at')->nullable();
            $table->char('migration_checksum', 64)->nullable()->index();
            $table->timestampsTz();
            $table->unique(['firebase_uid', 'legacy_collection']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_data');
    }
};
