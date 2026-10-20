<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_content_metrics', function (Blueprint $t) {
            $t->string('content_key', 220)->primary();
            $t->string('page_id', 191)->nullable()->index();
            $t->unsignedBigInteger('legacy_views')->default(0);
            $t->unsignedBigInteger('views_count')->default(0);
            $t->unsignedBigInteger('reach_count')->default(0);
            $t->unsignedBigInteger('impressions_count')->default(0);
            $t->unsignedBigInteger('watch_ms')->default(0);
            $t->timestamps();
        });
        Schema::create('social_content_viewers', function (Blueprint $t) {
            $t->string('content_key', 220);
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestamp('first_seen_at');
            $t->primary(['content_key', 'user_id']);
            $t->index(['user_id', 'content_key']);
        });
        Schema::create('social_metric_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('event_id', 191);
            $t->string('content_key', 220)->index();
            $t->string('event_type', 20);
            $t->unsignedInteger('duration_ms')->default(0);
            $t->timestamp('created_at');
            $t->unique(['user_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_metric_events');
        Schema::dropIfExists('social_content_viewers');
        Schema::dropIfExists('social_content_metrics');
    }
};
