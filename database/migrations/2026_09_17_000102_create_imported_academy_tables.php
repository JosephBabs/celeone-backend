<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['courses', 'lessons', 'videos', 'exams'] as $type) {
            Schema::create('academy_'.$type, function (Blueprint $table): void {
                $table->id();
                $table->string('firebase_id', 150)->unique();
                $table->string('title')->nullable();
                $table->string('subtitle')->nullable();
                $table->text('description')->nullable();
                $table->string('image_url')->nullable();
                $table->string('video_url')->nullable();
                $table->integer('duration')->nullable();
                $table->string('status')->nullable()->index();
                $table->string('seeded_by')->nullable();
                $table->timestampTz('archived_at')->nullable();
                $table->jsonb('legacy_data')->nullable();
                $table->timestampsTz();
            });
        }

        Schema::create('academy_certificates', function (Blueprint $table): void {
            $table->id();
            $table->string('firebase_id', 150)->unique();
            $table->string('user_id')->nullable()->index();
            $table->string('user_email')->nullable();
            $table->string('title')->nullable();
            $table->string('subtitle')->nullable();
            $table->text('description')->nullable();
            $table->string('status')->nullable()->index();
            $table->string('seeded_by')->nullable();
            $table->string('issued_at_text')->nullable();
            $table->jsonb('legacy_data')->nullable();
            $table->timestampsTz();
        });

        Schema::create('academy_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->string('firebase_id', 150)->unique();
            $table->string('user_id')->index();
            $table->string('course_id')->nullable()->index();
            $table->string('course_title')->nullable();
            $table->integer('progress')->nullable();
            $table->jsonb('completed_lesson_ids')->nullable();
            $table->string('review_status')->nullable()->index();
            $table->string('reviewed_by')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->string('status')->nullable()->index();
            $table->jsonb('legacy_data')->nullable();
            $table->timestampsTz();
        });

        Schema::create('admin_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('firebase_id', 150)->unique();
            $table->string('action')->nullable()->index();
            $table->string('admin_uid')->nullable()->index();
            $table->string('admin_email')->nullable();
            $table->string('target_id')->nullable()->index();
            $table->string('target_type')->nullable();
            $table->string('collection')->nullable();
            $table->string('document_id')->nullable();
            $table->string('environment')->nullable();
            $table->string('as_role')->nullable();
            $table->bigInteger('created_at_ms')->nullable();
            $table->jsonb('fields')->nullable();
            $table->jsonb('legacy_data')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');
        Schema::dropIfExists('academy_enrollments');
        Schema::dropIfExists('academy_certificates');
        foreach (['courses', 'lessons', 'videos', 'exams'] as $type) Schema::dropIfExists('academy_'.$type);
    }
};
