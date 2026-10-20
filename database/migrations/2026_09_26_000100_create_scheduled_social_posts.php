<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_social_posts', function (Blueprint $table): void {
            $table->string('id', 100)->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('page_id', 191)->nullable();
            $table->jsonb('payload');
            $table->timestampTz('scheduled_at');
            $table->string('timezone', 100);
            $table->string('status', 20)->default('scheduled');
            $table->unsignedInteger('revision')->default(1);
            $table->string('last_error')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'scheduled_at']);
            $table->index(['user_id', 'status']);
            $table->index(['page_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_social_posts');
    }
};
