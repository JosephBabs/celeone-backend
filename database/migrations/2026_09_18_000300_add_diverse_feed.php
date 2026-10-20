<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('firebase_documents', function (Blueprint $table): void {
            $table->string('feed_author_id', 191)->nullable();
            $table->string('feed_owner_id', 191)->nullable();
            $table->string('feed_page_id', 191)->nullable();
            $table->bigInteger('feed_created_at_ms')->nullable();
            $table->string('feed_status', 30)->nullable();
            $table->string('feed_audience', 30)->nullable();
            $table->jsonb('feed_tags')->nullable();
            $table->string('feed_revision', 32)->nullable();
            $table->double('feed_popularity')->default(0);
            $table->index(['collection_path', 'feed_status', 'feed_created_at_ms', 'document_id'], 'feed_chronological_idx');
            $table->index(['feed_author_id', 'feed_created_at_ms', 'document_id'], 'feed_author_chronological_idx');
        });
        Schema::create('feed_cursors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->jsonb('state');
            $table->jsonb('result')->nullable();
            $table->timestampTz('expires_at')->index();
        });
        Schema::create('feed_affinities', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->jsonb('tag_scores');
            $table->timestampTz('updated_at');
        });
        Schema::create('feed_interactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event_id', 80);
            $table->string('post_id', 191);
            $table->string('type', 24);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->date('event_day');
            $table->timestampTz('created_at')->index();
            $table->unique(['user_id', 'event_id']);
            $table->unique(['user_id', 'post_id', 'type', 'event_day'], 'feed_interaction_daily_unique');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(__DIR__.'/../sql/feed_metadata.sql'));
            DB::statement("UPDATE firebase_documents SET payload = payload WHERE collection_path = 'posts'");
            DB::statement('CREATE INDEX feed_tags_gin ON firebase_documents USING GIN (feed_tags) WHERE collection_path = \'posts\'');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS celeone_feed_metadata ON firebase_documents; DROP FUNCTION IF EXISTS celeone_feed_metadata(); DROP INDEX IF EXISTS feed_tags_gin;');
        }
        Schema::dropIfExists('feed_interactions');
        Schema::dropIfExists('feed_affinities');
        Schema::dropIfExists('feed_cursors');
        Schema::table('firebase_documents', function (Blueprint $table): void {
            $table->dropIndex('feed_chronological_idx');
            $table->dropIndex('feed_author_chronological_idx');
            $table->dropColumn(['feed_author_id', 'feed_owner_id', 'feed_page_id', 'feed_created_at_ms', 'feed_status', 'feed_audience', 'feed_tags', 'feed_revision','feed_popularity']);
        });
    }
};
