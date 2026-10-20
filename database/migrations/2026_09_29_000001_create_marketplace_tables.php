<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_shops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('public_id', 36)->unique();
            $table->string('title', 160);
            $table->string('slug', 190)->unique();
            $table->text('description');
            $table->string('business_type', 80);
            $table->string('custom_business_type', 160)->nullable();
            $table->string('mode', 20)->default('products');
            $table->string('phone', 40)->nullable();
            $table->string('whatsapp', 40)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('country', 120)->nullable();
            $table->text('address')->nullable();
            $table->text('logo_url')->nullable();
            $table->text('cover_url')->nullable();
            $table->unsignedBigInteger('delivery_fee')->default(0);
            $table->string('status', 30)->default('draft')->index();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique('owner_id');
            $table->index(['status', 'created_at']);
        });

        Schema::create('marketplace_listings', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->foreignId('shop_id')->constrained('marketplace_shops')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('title', 180);
            $table->text('description');
            $table->string('category', 120);
            $table->string('custom_category', 180)->nullable();
            $table->jsonb('images')->default('[]');
            $table->string('pricing_mode', 20)->default('fixed');
            $table->unsignedBigInteger('price')->nullable();
            $table->unsignedBigInteger('compare_at_price')->nullable();
            $table->string('currency', 8)->default('XOF');
            $table->string('unit_label', 80)->nullable();
            $table->string('stock_mode', 20)->default('tracked');
            $table->unsignedInteger('stock_quantity')->nullable();
            $table->unsignedInteger('reserved_quantity')->default(0);
            $table->boolean('available')->default(true)->index();
            $table->string('status', 30)->default('draft')->index();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['shop_id', 'status', 'available']);
            $table->index(['kind', 'category', 'status']);
        });

        Schema::create('marketplace_orders', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('shop_id')->constrained('marketplace_shops')->restrictOnDelete();
            $table->string('status', 40)->default('pending_payment')->index();
            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('delivery_fee')->default(0);
            $table->unsignedBigInteger('platform_fee')->default(0);
            $table->unsignedBigInteger('seller_net')->default(0);
            $table->unsignedBigInteger('total');
            $table->string('currency', 8)->default('XOF');
            $table->string('delivery_method', 20)->default('pickup');
            $table->text('delivery_address')->nullable();
            $table->string('buyer_phone', 40)->nullable();
            $table->string('payment_status', 30)->default('pending')->index();
            $table->string('provider', 30)->nullable();
            $table->string('provider_transaction_id', 120)->nullable()->index();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('seller_completed_at')->nullable();
            $table->timestampTz('buyer_received_at')->nullable();
            $table->timestampTz('admin_verified_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['buyer_id', 'created_at']);
            $table->index(['shop_id', 'created_at']);
        });

        Schema::create('marketplace_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('marketplace_orders')->cascadeOnDelete();
            $table->foreignId('listing_id')->nullable()->constrained('marketplace_listings')->nullOnDelete();
            $table->string('listing_public_id', 36);
            $table->string('kind', 20);
            $table->string('title', 180);
            $table->jsonb('image_urls')->default('[]');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('line_total');
            $table->string('currency', 8)->default('XOF');
            $table->timestampsTz();

            $table->index(['order_id', 'listing_id']);
        });

        Schema::create('marketplace_stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('marketplace_orders')->cascadeOnDelete();
            $table->foreignId('listing_id')->constrained('marketplace_listings')->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('status', 20)->default('active')->index();
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('released_at')->nullable();
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['order_id', 'listing_id']);
        });

        Schema::create('marketplace_payments', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->foreignId('order_id')->constrained('marketplace_orders')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('provider', 30)->default('fedapay');
            $table->string('provider_transaction_id', 120)->nullable()->unique();
            $table->text('checkout_url')->nullable();
            $table->unsignedBigInteger('amount');
            $table->string('currency', 8)->default('XOF');
            $table->string('status', 30)->default('pending')->index();
            $table->jsonb('provider_payload')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampsTz();

            $table->index(['order_id', 'status']);
        });

        Schema::create('marketplace_quote_requests', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->foreignId('listing_id')->constrained('marketplace_listings')->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
            $table->text('description');
            $table->unsignedBigInteger('budget')->nullable();
            $table->date('preferred_date')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('status', 30)->default('pending')->index();
            $table->unsignedBigInteger('seller_quote_amount')->nullable();
            $table->text('seller_message')->nullable();
            $table->timestampTz('responded_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampsTz();

            $table->index(['listing_id', 'status']);
            $table->index(['buyer_id', 'created_at']);
        });

        Schema::create('marketplace_disputes', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->foreignId('order_id')->constrained('marketplace_orders')->cascadeOnDelete();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->string('status', 30)->default('open')->index();
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('marketplace_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->foreignId('shop_id')->constrained('marketplace_shops')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('marketplace_orders')->nullOnDelete();
            $table->string('type', 40);
            $table->bigInteger('amount');
            $table->string('currency', 8)->default('XOF');
            $table->string('status', 30)->default('eligible')->index();
            $table->text('description')->nullable();
            $table->timestampTz('eligible_at')->nullable();
            $table->timestampTz('settled_at')->nullable();
            $table->timestampsTz();

            $table->index(['shop_id', 'status']);
        });

        Schema::create('marketplace_payout_batches', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->string('status', 30)->default('draft')->index();
            $table->string('currency', 8)->default('XOF');
            $table->unsignedBigInteger('total_amount')->default(0);
            $table->unsignedInteger('seller_count')->default(0);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('payment_reference', 190)->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('marketplace_payout_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('marketplace_payout_batches')->cascadeOnDelete();
            $table->foreignId('shop_id')->constrained('marketplace_shops')->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('currency', 8)->default('XOF');
            $table->string('status', 30)->default('pending');
            $table->jsonb('ledger_entry_ids')->default('[]');
            $table->timestampsTz();

            $table->unique(['batch_id', 'shop_id']);
        });

        Schema::create('marketplace_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 100)->index();
            $table->string('subject_type', 100);
            $table->string('subject_id', 64);
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_audit_events');
        Schema::dropIfExists('marketplace_payout_items');
        Schema::dropIfExists('marketplace_payout_batches');
        Schema::dropIfExists('marketplace_ledger_entries');
        Schema::dropIfExists('marketplace_disputes');
        Schema::dropIfExists('marketplace_quote_requests');
        Schema::dropIfExists('marketplace_payments');
        Schema::dropIfExists('marketplace_stock_reservations');
        Schema::dropIfExists('marketplace_order_items');
        Schema::dropIfExists('marketplace_orders');
        Schema::dropIfExists('marketplace_listings');
        Schema::dropIfExists('marketplace_shops');
    }
};
