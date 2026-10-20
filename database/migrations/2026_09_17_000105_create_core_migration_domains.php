<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function legacy(Blueprint $table): void
    {
        $table->string('legacy_id', 150)->nullable()->index();
        $table->string('legacy_collection', 150)->nullable()->index();
        $table->string('legacy_path', 700)->nullable()->index();
        $table->jsonb('legacy_payload')->nullable();
        $table->timestampTz('legacy_created_at')->nullable();
        $table->timestampTz('legacy_updated_at')->nullable();
        $table->timestampTz('migrated_at')->nullable();
        $table->char('migration_checksum', 64)->nullable()->index();
        $table->timestampsTz();
    }

    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table): void {
            $table->id(); $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('username', 100)->nullable()->unique(); $table->text('bio')->nullable();
            $table->string('phone', 40)->nullable()->index(); $table->string('location')->nullable(); $this->legacy($table);
        });
        Schema::create('auth_identities', function (Blueprint $table): void {
            $table->id(); $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40); $table->string('provider_subject', 255); $table->jsonb('claims')->nullable();
            $table->boolean('disabled')->default(false); $table->timestampTz('last_sign_in_at')->nullable(); $this->legacy($table);
            $table->unique(['provider', 'provider_subject']);
        });
        Schema::create('devices', function (Blueprint $table): void {
            $table->id(); $table->foreignId('user_id')->constrained()->cascadeOnDelete(); $table->string('device_id', 150);
            $table->string('platform', 30)->nullable(); $table->string('app_version', 50)->nullable(); $table->timestampTz('last_seen_at')->nullable(); $this->legacy($table);
            $table->unique(['user_id', 'device_id']);
        });
        Schema::create('push_tokens', function (Blueprint $table): void {
            $table->id(); $table->foreignId('user_id')->constrained()->cascadeOnDelete(); $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->string('provider', 30)->default('fcm'); $table->text('token')->unique(); $table->timestampTz('last_used_at')->nullable(); $this->legacy($table);
        });
        Schema::create('follows', function (Blueprint $table): void {
            $table->id(); $table->foreignId('follower_id')->constrained('users')->cascadeOnDelete(); $table->foreignId('followed_id')->constrained('users')->cascadeOnDelete(); $table->string('status', 30)->default('active'); $this->legacy($table); $table->unique(['follower_id', 'followed_id']);
        });
        Schema::create('user_blocks', function (Blueprint $table): void {
            $table->id(); $table->foreignId('user_id')->constrained()->cascadeOnDelete(); $table->foreignId('blocked_user_id')->constrained('users')->cascadeOnDelete(); $this->legacy($table); $table->unique(['user_id', 'blocked_user_id']);
        });
        Schema::create('reels', function (Blueprint $table): void {
            $table->id(); $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); $table->text('caption')->nullable(); $table->text('media_url')->nullable(); $table->string('status', 30)->default('published')->index(); $this->legacy($table);
        });
        Schema::create('shares', function (Blueprint $table): void {
            $table->id(); $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); $table->string('shareable_type'); $table->string('shareable_id'); $table->text('destination')->nullable(); $this->legacy($table); $table->index(['shareable_type', 'shareable_id']);
        });
        Schema::create('saved_posts', function (Blueprint $table): void {
            $table->id(); $table->foreignId('user_id')->constrained()->cascadeOnDelete(); $table->foreignId('post_id')->constrained()->cascadeOnDelete(); $this->legacy($table); $table->unique(['user_id', 'post_id']);
        });
        Schema::create('content_views', function (Blueprint $table): void {
            $table->id(); $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); $table->string('viewable_type'); $table->string('viewable_id'); $table->unsignedInteger('duration_seconds')->default(0); $this->legacy($table); $table->index(['viewable_type', 'viewable_id']);
        });
        Schema::create('statuses', function (Blueprint $table): void {
            $table->id(); $table->foreignId('user_id')->constrained()->cascadeOnDelete(); $table->text('body')->nullable(); $table->text('media_url')->nullable(); $table->timestampTz('expires_at')->nullable()->index(); $this->legacy($table);
        });
        Schema::create('status_views', function (Blueprint $table): void {
            $table->id(); $table->foreignId('status_id')->constrained()->cascadeOnDelete(); $table->foreignId('user_id')->constrained()->cascadeOnDelete(); $this->legacy($table); $table->unique(['status_id', 'user_id']);
        });
        Schema::create('feed_items', function (Blueprint $table): void {
            $table->id(); $table->foreignId('user_id')->constrained()->cascadeOnDelete(); $table->string('item_type', 50); $table->string('item_id', 150); $table->decimal('rank', 14, 6)->default(0); $table->timestampTz('seen_at')->nullable(); $this->legacy($table); $table->index(['user_id', 'rank']);
        });
        Schema::create('conversations', function (Blueprint $table): void {
            $table->id(); $table->string('conversation_type', 30)->default('direct'); $table->string('public_id', 150)->nullable()->unique(); $table->jsonb('metadata')->nullable(); $this->legacy($table);
        });
        Schema::create('conversation_members', function (Blueprint $table): void {
            $table->id(); $table->foreignId('conversation_id')->constrained()->cascadeOnDelete(); $table->foreignId('user_id')->constrained()->cascadeOnDelete(); $table->string('role', 30)->default('member'); $table->timestampTz('last_read_at')->nullable(); $this->legacy($table); $table->unique(['conversation_id', 'user_id']);
        });
        Schema::create('messages', function (Blueprint $table): void {
            $table->id(); $table->foreignId('conversation_id')->constrained()->cascadeOnDelete(); $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); $table->uuid('client_id')->nullable()->unique(); $table->text('body')->nullable(); $table->string('message_type', 30)->default('text'); $table->jsonb('metadata')->nullable(); $this->legacy($table); $table->index(['conversation_id', 'created_at']);
        });
        Schema::create('message_receipts', function (Blueprint $table): void {
            $table->id(); $table->foreignId('message_id')->constrained()->cascadeOnDelete(); $table->foreignId('user_id')->constrained()->cascadeOnDelete(); $table->timestampTz('read_at')->nullable(); $table->timestampTz('delivered_at')->nullable(); $this->legacy($table); $table->unique(['message_id', 'user_id']);
        });
        Schema::create('agenda_items', function (Blueprint $table): void {
            $table->id(); $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); $table->string('title'); $table->text('description')->nullable(); $table->timestampTz('starts_at')->nullable()->index(); $table->timestampTz('ends_at')->nullable(); $table->string('location')->nullable(); $this->legacy($table);
        });
        Schema::create('documents', function (Blueprint $table): void {
            $table->id(); $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); $table->string('title'); $table->text('body')->nullable(); $table->text('url')->nullable(); $table->string('status', 30)->default('published'); $this->legacy($table);
        });
        Schema::create('parishes', function (Blueprint $table): void {
            $table->id(); $table->string('public_id', 150)->nullable()->unique(); $table->string('name'); $table->text('address')->nullable(); $table->decimal('latitude', 10, 7)->nullable(); $table->decimal('longitude', 10, 7)->nullable(); $table->jsonb('metadata')->nullable(); $this->legacy($table); $table->index(['latitude', 'longitude']);
        });
        Schema::create('parish_visit_plans', function (Blueprint $table): void {
            $table->id(); $table->foreignId('user_id')->constrained()->cascadeOnDelete(); $table->string('name'); $table->string('status', 30)->default('draft'); $table->jsonb('parish_ids')->nullable(); $table->timestampTz('starts_at')->nullable(); $this->legacy($table);
        });
        Schema::create('parish_tracking_sessions', function (Blueprint $table): void {
            $table->id(); $table->foreignId('user_id')->constrained()->cascadeOnDelete(); $table->foreignId('parish_id')->nullable()->constrained()->nullOnDelete(); $table->timestampTz('started_at')->nullable(); $table->timestampTz('ended_at')->nullable(); $table->jsonb('route')->nullable(); $this->legacy($table);
        });
        Schema::create('member_cards', function (Blueprint $table): void {
            $table->id(); $table->foreignId('user_id')->constrained()->cascadeOnDelete(); $table->foreignId('parish_id')->nullable()->constrained()->nullOnDelete(); $table->string('card_number', 100)->nullable()->unique(); $table->string('status', 30)->default('pending'); $this->legacy($table);
        });
        Schema::create('member_card_requests', function (Blueprint $table): void {
            $table->id(); $table->foreignId('user_id')->constrained()->cascadeOnDelete(); $table->foreignId('parish_id')->nullable()->constrained()->nullOnDelete(); $table->string('status', 30)->default('pending'); $table->jsonb('data')->nullable(); $this->legacy($table);
        });
        Schema::create('app_configs', function (Blueprint $table): void {
            $table->id(); $table->string('key', 150)->unique(); $table->jsonb('value'); $table->boolean('public')->default(false); $this->legacy($table);
        });
        Schema::create('packages', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->decimal('price', 12, 2)->default(0); $table->string('currency', 3)->default('EUR'); $table->string('status', 30)->default('active'); $table->jsonb('features')->nullable(); $this->legacy($table);
        });
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id(); $table->foreignId('user_id')->constrained()->cascadeOnDelete(); $table->foreignId('package_id')->nullable()->constrained('packages')->nullOnDelete(); $table->string('provider', 40)->nullable(); $table->string('provider_id', 150)->nullable()->index(); $table->string('status', 30)->index(); $table->timestampTz('starts_at')->nullable(); $table->timestampTz('ends_at')->nullable(); $this->legacy($table);
        });
        Schema::create('payments', function (Blueprint $table): void {
            $table->id(); $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete(); $table->decimal('amount', 12, 2); $table->string('currency', 3)->default('EUR'); $table->string('status', 30)->index(); $table->string('provider_reference', 150)->nullable()->unique(); $this->legacy($table);
        });
        Schema::create('reports', function (Blueprint $table): void {
            $table->id(); $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete(); $table->string('target_type'); $table->string('target_id'); $table->string('reason', 100); $table->string('status', 30)->default('open')->index(); $table->text('resolution')->nullable(); $this->legacy($table); $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        foreach (['reports', 'payments', 'subscriptions', 'packages', 'app_configs', 'member_card_requests', 'member_cards', 'parish_tracking_sessions', 'parish_visit_plans', 'parishes', 'documents', 'agenda_items', 'message_receipts', 'messages', 'conversation_members', 'conversations', 'feed_items', 'status_views', 'statuses', 'content_views', 'saved_posts', 'shares', 'reels', 'user_blocks', 'follows', 'push_tokens', 'devices', 'auth_identities', 'user_profiles'] as $table) Schema::dropIfExists($table);
    }
};
