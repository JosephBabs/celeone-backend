<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $legacyDomainTables = [
        'academy_certificates','academy_enrollments','academy_exam_attempts','admin_alerts','agenda_items',
        'app_configs','blocked_users','cantiques','channels','chat_invites','church_history','content_reach',
        'creator_applications','creator_content_requests','creator_drafts','creator_rights_requests',
        'creator_scheduled_posts','creator_verification_requests','creator_metrics','documents','followers',
        'following','friend_requests','groups','interaction_events','youth_children','youth_quiz_attempts',
        'youth_quizzes','youth_results','youth_settings','join_requests','live_notification_subscriptions',
        'media_consumption_events','member_card_requests','member_cards','monetization_requests','music_albums',
        'notification_dispatch_queue','notification_inbox','page_profile_views','parish_history',
        'parish_tracking_sessions','parish_visit_plans','parishes','participation_rewards','payment_configs',
        'payment_transactions','platform_requests','post_boost_campaigns','profile_media','reels','reports',
        'restriction_appeals','shares','social_configs','social_delivery_events','social_fanout_jobs',
        'social_live_events','social_live_sessions','songs','statuses','subscription_packages','tv_devices',
        'tv_link_codes','user_badges','user_devices','user_feed_items','user_interests','user_presence',
        'user_profile_views','user_reactions','user_subscriptions','videos','watch_history','weekly_themes',
        'saved_posts','post_views','status_views','status_viewers','hidden_messages','hidden_conversations',
        'push_devices','notification_preferences','professional_profiles','professional_skills',
        'professional_services','professional_requests','professional_reviews','professional_tickets',
        'professional_ticket_messages','academy_courses','academy_lessons','academy_exams','quiz_questions',
        'quiz_answers','quiz_attempt_answers','cantique_translations','cantique_assets','cantique_favorites',
        'media_variants','albums','album_items','playback_progress','upload_sessions','transcode_jobs',
        'page_members','live_participants','live_comments','live_reactions','live_blocks','calls','call_participants',
        'auth_identities','refresh_tokens','user_settings','roles','role_assignments','restrictions',
        'mobile_releases','payment_events','reconciliation_results','audit_logs',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('pages', 'public_id')) {
            Schema::table('pages', function (Blueprint $table): void {
                $table->string('public_id', 191)->nullable()->unique()->after('id');
                $table->string('title')->nullable();
                $table->text('body')->nullable();
                $table->jsonb('data')->default(DB::raw("'{}'::jsonb"));
                $table->string('parent_type', 80)->nullable();
                $table->string('parent_id', 191)->nullable();
                $table->softDeletesTz();
            });
            DB::table('pages')->whereNull('public_id')->update(['public_id'=>DB::raw('COALESCE(firebase_id, id::text)')]);
        }
        foreach ($this->legacyDomainTables as $name) {
            if (Schema::hasTable($name)) {
                $this->ensureGenericColumns($name);
                continue;
            }
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->id();
                $table->string('public_id', 191)->unique();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('parent_type', 80)->nullable();
                $table->string('parent_id', 191)->nullable();
                $table->string('status', 50)->nullable()->index();
                $table->string('title')->nullable();
                $table->text('body')->nullable();
                $table->jsonb('data')->default(DB::raw("'{}'::jsonb"));
                $table->string('legacy_id', 191)->nullable()->index();
                $table->string('legacy_collection', 191)->nullable()->index();
                $table->string('legacy_path', 700)->nullable()->index();
                $table->jsonb('legacy_payload')->nullable();
                $table->timestampTz('legacy_created_at')->nullable();
                $table->timestampTz('legacy_updated_at')->nullable();
                $table->timestampTz('migrated_at')->nullable();
                $table->timestampsTz();
                $table->softDeletesTz();
                $table->index(['parent_type', 'parent_id']);
                $table->index(['status', 'created_at']);
                $table->unique(['legacy_collection', 'legacy_path'], $name.'_legacy_path_unique');
            });
        }

        if (! Schema::hasTable('conversation_members')) {
            Schema::create('conversation_members', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('chat_room_id')->constrained('chat_rooms')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('role', 30)->default('member');
                $table->unsignedInteger('unread_count')->default(0);
                $table->timestampTz('last_read_at')->nullable();
                $table->timestampTz('muted_until')->nullable();
                $table->timestampsTz();
                $table->unique(['chat_room_id', 'user_id']);
            });
        }

        if (! Schema::hasTable('message_receipts')) {
            Schema::create('message_receipts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('chat_message_id')->constrained('chat_messages')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->timestampTz('delivered_at')->nullable();
                $table->timestampTz('read_at')->nullable();
                $table->timestampsTz();
                $table->unique(['chat_message_id', 'user_id']);
            });
        }

        if (! Schema::hasColumn('chat_messages', 'client_id')) {
            Schema::table('chat_messages', function (Blueprint $table): void {
                $table->uuid('client_id')->nullable()->after('firebase_id');
                $table->foreignId('reply_to_id')->nullable()->constrained('chat_messages')->nullOnDelete();
                $table->timestampTz('edited_at')->nullable();
                $table->timestampTz('deleted_for_everyone_at')->nullable();
                $table->unique(['chat_room_id', 'client_id']);
            });
        }

        if (! Schema::hasTable('post_metrics')) {
            Schema::create('post_metrics', function (Blueprint $table): void {
                $table->foreignId('post_id')->primary()->constrained('posts')->cascadeOnDelete();
                $table->unsignedBigInteger('reactions_count')->default(0);
                $table->unsignedBigInteger('comments_count')->default(0);
                $table->unsignedBigInteger('shares_count')->default(0);
                $table->unsignedBigInteger('views_count')->default(0);
                $table->timestampsTz();
            });
        }
    }

    private function ensureGenericColumns(string $name): void
    {
        $definitions = [
            'public_id'=>fn(Blueprint $t)=>$t->string('public_id',191)->nullable()->unique(),
            'parent_type'=>fn(Blueprint $t)=>$t->string('parent_type',80)->nullable(),
            'parent_id'=>fn(Blueprint $t)=>$t->string('parent_id',191)->nullable(),
            'body'=>fn(Blueprint $t)=>$t->text('body')->nullable(),
            'data'=>fn(Blueprint $t)=>$t->jsonb('data')->default(DB::raw("'{}'::jsonb")),
            'legacy_id'=>fn(Blueprint $t)=>$t->string('legacy_id',191)->nullable()->index(),
            'legacy_collection'=>fn(Blueprint $t)=>$t->string('legacy_collection',191)->nullable()->index(),
            'legacy_path'=>fn(Blueprint $t)=>$t->string('legacy_path',700)->nullable()->index(),
            'legacy_payload'=>fn(Blueprint $t)=>$t->jsonb('legacy_payload')->nullable(),
            'legacy_created_at'=>fn(Blueprint $t)=>$t->timestampTz('legacy_created_at')->nullable(),
            'legacy_updated_at'=>fn(Blueprint $t)=>$t->timestampTz('legacy_updated_at')->nullable(),
            'migrated_at'=>fn(Blueprint $t)=>$t->timestampTz('migrated_at')->nullable(),
            'deleted_at'=>fn(Blueprint $t)=>$t->softDeletesTz(),
        ];
        foreach ($definitions as $column=>$definition) {
            if (!Schema::hasColumn($name,$column)) Schema::table($name,$definition);
        }
        $idSource=Schema::hasColumn($name,'firebase_id')?'firebase_id':'id::text';
        DB::statement('UPDATE "'.str_replace('"','""',$name).'" SET public_id = COALESCE(public_id, '.$idSource.') WHERE public_id IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('post_metrics');
        Schema::dropIfExists('message_receipts');
        Schema::dropIfExists('conversation_members');
        foreach (array_reverse($this->legacyDomainTables) as $name) {
            Schema::dropIfExists($name);
        }
    }
};
