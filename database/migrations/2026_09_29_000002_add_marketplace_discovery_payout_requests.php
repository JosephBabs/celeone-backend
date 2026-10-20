<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_discovery_events', function (Blueprint $table) {
            $table->id();
            $table->string('dedupe_key', 64)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('listing_id')->nullable()->constrained('marketplace_listings')->nullOnDelete();
            $table->foreignId('shop_id')->nullable()->constrained('marketplace_shops')->nullOnDelete();
            $table->string('category', 120)->nullable()->index();
            $table->string('event', 30)->index();
            $table->string('surface', 60)->default('marketplace')->index();
            $table->string('context_id', 120)->nullable()->index();
            $table->decimal('weight', 8, 3)->default(0);
            $table->timestampTz('occurred_at')->index();
            $table->timestampsTz();

            $table->index(['user_id', 'occurred_at']);
            $table->index(['user_id', 'category', 'occurred_at']);
            $table->index(['user_id', 'shop_id', 'occurred_at']);
            $table->index(['shop_id', 'event', 'occurred_at']);
            $table->index(['listing_id', 'event', 'occurred_at']);
        });

        Schema::create('marketplace_user_interest_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('category', 120);
            $table->decimal('score', 10, 3)->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('opens')->default(0);
            $table->unsignedInteger('favorites')->default(0);
            $table->unsignedInteger('carts')->default(0);
            $table->unsignedInteger('quotes')->default(0);
            $table->unsignedInteger('purchases')->default(0);
            $table->timestampTz('last_event_at')->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'category']);
            $table->index(['user_id', 'score']);
        });

        Schema::create('marketplace_user_seller_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('shop_id')->constrained('marketplace_shops')->cascadeOnDelete();
            $table->decimal('score', 10, 3)->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('opens')->default(0);
            $table->unsignedInteger('favorites')->default(0);
            $table->unsignedInteger('carts')->default(0);
            $table->unsignedInteger('quotes')->default(0);
            $table->unsignedInteger('purchases')->default(0);
            $table->timestampTz('last_event_at')->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'shop_id']);
            $table->index(['user_id', 'score']);
        });

        Schema::create('marketplace_payout_requests', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->foreignId('shop_id')->constrained('marketplace_shops')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('currency', 8)->default('XOF');
            $table->jsonb('ledger_entry_ids')->default('[]');
            $table->string('status', 30)->default('pending')->index();
            $table->text('seller_note')->nullable();
            $table->text('admin_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->foreignId('batch_id')->nullable()->constrained('marketplace_payout_batches')->nullOnDelete();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();

            $table->index(['shop_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        Schema::table('marketplace_order_items', function (Blueprint $table) {
            $table->string('category', 120)->nullable()->after('kind');
        });

        Schema::table('marketplace_orders', function (Blueprint $table) {
            $table->string('business_type', 80)->nullable()->after('shop_id');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_orders', function (Blueprint $table) {
            $table->dropColumn('business_type');
        });

        Schema::table('marketplace_order_items', function (Blueprint $table) {
            $table->dropColumn('category');
        });

        Schema::dropIfExists('marketplace_payout_requests');
        Schema::dropIfExists('marketplace_user_seller_signals');
        Schema::dropIfExists('marketplace_user_interest_profiles');
        Schema::dropIfExists('marketplace_discovery_events');
    }
};
