<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('managed_documents')) {
            Schema::create('managed_documents', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('collection_path', 512)->index();
                $table->string('document_id', 191);
                $table->jsonb('payload')->default('{}');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['collection_path', 'document_id'], 'managed_documents_path_document_unique');
            });
        }

        if (! Schema::hasTable('management_audit_logs')) {
            Schema::create('management_audit_logs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('action', 100);
                $table->string('collection_path', 512)->nullable();
                $table->string('document_id', 191)->nullable();
                $table->jsonb('changes')->default('{}');
                $table->string('ip_address', 64)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamps();
                $table->index(['collection_path', 'document_id']);
            });
        }

        if (! Schema::hasTable('media_assets')) {
            Schema::create('media_assets', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name');
                $table->string('mime')->nullable();
                $table->string('kind', 50)->nullable();
                $table->unsignedBigInteger('size')->default(0);
                $table->text('url')->nullable();
                $table->text('cdn_url')->nullable();
                $table->text('thumbnail_url')->nullable();
                $table->text('storage_path');
                $table->string('visibility', 20)->default('public');
                $table->string('collection_name', 191)->nullable();
                $table->string('entity_id', 191)->nullable();
                $table->string('field_name', 191)->nullable();
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('status', 30)->default('active');
                $table->timestamps();
                $table->index(['collection_name', 'entity_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('management_audit_logs');
        Schema::dropIfExists('managed_documents');
    }
};
