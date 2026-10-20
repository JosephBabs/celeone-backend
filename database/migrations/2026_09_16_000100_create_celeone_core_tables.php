<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('firebase_uid', 128)->nullable()->unique()->after('id');
            $table->string('google_id')->nullable()->unique()->after('email');
            $table->text('avatar_url')->nullable()->after('google_id');
            $table->string('role')->default('user')->index()->after('avatar_url');
            $table->jsonb('legacy_data')->nullable()->after('role');
        });

        Schema::create('pages', function (Blueprint $table): void {
            $table->id();
            $table->string('firebase_id', 150)->nullable()->unique();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('avatar_url')->nullable();
            $table->string('status')->default('active')->index();
            $table->jsonb('legacy_data')->nullable();
            $table->timestampsTz();
        });

        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->string('firebase_id', 150)->nullable()->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('page_id')->nullable()->constrained('pages')->nullOnDelete();
            $table->text('text_content')->nullable();
            $table->string('post_type', 50)->default('status')->index();
            $table->string('audience', 50)->default('public')->index();
            $table->string('status')->default('published')->index();
            $table->jsonb('legacy_data')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'created_at']);
            $table->index(['page_id', 'created_at']);
        });

        Schema::create('media_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('post_id')->nullable()->constrained('posts')->cascadeOnDelete();
            $table->string('type', 30)->index();
            $table->text('url');
            $table->text('firebase_storage_path')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
        });

        Schema::create('comments', function (Blueprint $table): void {
            $table->id();
            $table->string('firebase_id', 150)->nullable()->unique();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('comments')->cascadeOnDelete();
            $table->text('body');
            $table->jsonb('legacy_data')->nullable();
            $table->timestampsTz();
            $table->index(['post_id', 'created_at']);
        });

        Schema::create('reactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->string('reaction_type', 30)->default('like');
            $table->timestampsTz();
            $table->unique(['user_id', 'post_id']);
        });

        Schema::create('page_follows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('page_id')->constrained('pages')->cascadeOnDelete();
            $table->string('status')->default('active')->index();
            $table->timestampsTz();
            $table->unique(['user_id', 'page_id']);
        });

        Schema::create('friendships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('friend_id')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('accepted')->index();
            $table->timestampsTz();
            $table->unique(['user_id', 'friend_id']);
        });

        Schema::create('chat_rooms', function (Blueprint $table): void {
            $table->id();
            $table->string('firebase_id', 150)->nullable()->unique();
            $table->string('type', 30)->default('direct');
            $table->string('name')->nullable();
            $table->string('pair_key')->nullable()->index();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
        });

        Schema::create('chat_room_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('chat_room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role')->default('member');
            $table->timestampsTz();
            $table->unique(['chat_room_id', 'user_id']);
        });

        Schema::create('chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->string('firebase_id', 150)->nullable()->unique();
            $table->foreignId('chat_room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body')->nullable();
            $table->string('message_type', 30)->default('text');
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->index(['chat_room_id', 'created_at']);
        });

        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type');
            $table->nullableMorphs('notifiable');
            $table->text('body')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->jsonb('data')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'read_at']);
        });

        Schema::create('firebase_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('collection_path', 500);
            $table->string('document_id', 150);
            $table->string('parent_document_path', 500)->nullable();
            $table->jsonb('payload');
            $table->timestampTz('firebase_created_at')->nullable();
            $table->timestampTz('firebase_updated_at')->nullable();
            $table->timestampsTz();
            $table->unique(['collection_path', 'document_id']);
            $table->index('parent_document_path');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firebase_documents');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_room_members');
        Schema::dropIfExists('chat_rooms');
        Schema::dropIfExists('friendships');
        Schema::dropIfExists('page_follows');
        Schema::dropIfExists('reactions');
        Schema::dropIfExists('comments');
        Schema::dropIfExists('media_assets');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('pages');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['firebase_uid', 'google_id', 'avatar_url', 'role', 'legacy_data']);
        });
    }
};
