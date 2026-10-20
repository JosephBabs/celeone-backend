<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_shops', function (Blueprint $table) {
            if (!Schema::hasColumn('marketplace_shops', 'profile_type')) {
                $table->string('profile_type', 32)->default('shop')->index();
            }
            if (!Schema::hasColumn('marketplace_shops', 'website_url')) {
                $table->string('website_url', 1000)->nullable();
            }
            if (!Schema::hasColumn('marketplace_shops', 'registration_number')) {
                $table->string('registration_number', 191)->nullable();
            }
            if (!Schema::hasColumn('marketplace_shops', 'hiring_enabled')) {
                $table->boolean('hiring_enabled')->default(false)->index();
            }
            if (!Schema::hasColumn('marketplace_shops', 'service_fixer_enabled')) {
                $table->boolean('service_fixer_enabled')->default(false);
            }
        });

        Schema::create('marketplace_documents', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 40)->default('cv')->index();
            $table->string('disk', 64)->default('local');
            $table->string('path', 1000);
            $table->string('original_name', 255);
            $table->string('mime', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64)->nullable()->index();
            $table->timestamps();
            $table->index(['owner_id', 'kind']);
        });

        Schema::create('marketplace_professional_profiles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->unique();
            $table->string('headline', 255);
            $table->text('summary');
            $table->string('city', 128)->nullable();
            $table->string('country', 128)->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('whatsapp', 64)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('portfolio_url', 1000)->nullable();
            $table->uuid('cv_document_public_id')->nullable()->index();
            $table->jsonb('skills')->default('[]');
            $table->jsonb('education')->default('[]');
            $table->jsonb('training')->default('[]');
            $table->jsonb('certifications')->default('[]');
            $table->jsonb('experience')->default('[]');
            $table->string('availability', 40)->default('open');
            $table->string('visibility', 40)->default('companies');
            $table->string('contact_visibility', 40)->default('after_interest');
            $table->string('status', 40)->default('draft')->index();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'visibility']);
        });

        Schema::create('marketplace_job_offers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('shop_id')->constrained('marketplace_shops')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('offer_type', 40)->default('job')->index();
            $table->string('title', 255);
            $table->text('description');
            $table->string('category', 128)->nullable()->index();
            $table->string('location', 255)->nullable();
            $table->string('work_mode', 40)->default('onsite');
            $table->string('employment_type', 40)->nullable();
            $table->unsignedBigInteger('salary_min')->nullable();
            $table->unsignedBigInteger('salary_max')->nullable();
            $table->string('currency', 8)->default('XOF');
            $table->string('salary_period', 40)->nullable();
            $table->jsonb('skills')->default('[]');
            $table->jsonb('requirements')->default('[]');
            $table->string('contact_mode', 40)->default('interest');
            $table->string('contact_email', 255)->nullable();
            $table->string('contact_phone', 64)->nullable();
            $table->string('contact_whatsapp', 64)->nullable();
            $table->boolean('managed_by_celeone')->default(false)->index();
            $table->boolean('available')->default(true)->index();
            $table->timestampTz('deadline_at')->nullable()->index();
            $table->string('status', 40)->default('draft')->index();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'available', 'deadline_at']);
            $table->index(['shop_id', 'status']);
        });

        Schema::create('marketplace_job_applications', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('job_offer_id')->constrained('marketplace_job_offers')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('professional_profile_id')->nullable()->constrained('marketplace_professional_profiles')->nullOnDelete();
            $table->uuid('cv_document_public_id')->nullable();
            $table->text('message')->nullable();
            $table->jsonb('extra_details')->default('{}');
            $table->string('status', 40)->default('interested')->index();
            $table->boolean('contact_unlocked')->default(false);
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('admin_note')->nullable();
            $table->timestamps();
            $table->unique(['job_offer_id', 'user_id']);
            $table->index(['job_offer_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('marketplace_candidate_views', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('shop_id')->constrained('marketplace_shops')->cascadeOnDelete();
            $table->foreignId('professional_profile_id')->constrained('marketplace_professional_profiles')->cascadeOnDelete();
            $table->foreignId('viewer_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('viewed_at');
            $table->timestamps();
            $table->index(['shop_id', 'viewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_candidate_views');
        Schema::dropIfExists('marketplace_job_applications');
        Schema::dropIfExists('marketplace_job_offers');
        Schema::dropIfExists('marketplace_professional_profiles');
        Schema::dropIfExists('marketplace_documents');

        Schema::table('marketplace_shops', function (Blueprint $table) {
            foreach ([
                'profile_type',
                'website_url',
                'registration_number',
                'hiring_enabled',
                'service_fixer_enabled',
            ] as $column) {
                if (Schema::hasColumn('marketplace_shops', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
