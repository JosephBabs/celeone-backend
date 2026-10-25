<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_public_job_applications', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->foreignId('job_offer_id')->constrained('marketplace_job_offers')->cascadeOnDelete();
            $table->string('applicant_name', 150);
            $table->string('applicant_email', 255);
            $table->string('applicant_phone', 64);
            $table->text('message');
            $table->string('status', 40)->default('interested')->index();
            $table->boolean('contact_unlocked')->default(false);
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('admin_note')->nullable();
            $table->timestamps();
            $table->unique(['job_offer_id', 'applicant_email']);
            $table->index(['job_offer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_public_job_applications');
    }
};
