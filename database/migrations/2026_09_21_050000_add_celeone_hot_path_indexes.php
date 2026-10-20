<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PostgreSQL hot-path indexes for Cèlè One.
     *
     * This migration is intentionally additive: it does not rename columns,
     * modify constraints, change endpoint behavior, or rewrite existing data.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $indexes = [
            ['posts', ['status', 'audience', 'id'], 'idx_celeone_posts_status_audience_id', 'CREATE INDEX IF NOT EXISTS idx_celeone_posts_status_audience_id ON posts (status, audience, id DESC)'],
            ['posts', ['user_id', 'id'], 'idx_celeone_posts_user_id', 'CREATE INDEX IF NOT EXISTS idx_celeone_posts_user_id ON posts (user_id, id DESC)'],
            ['posts', ['page_id', 'id'], 'idx_celeone_posts_page_id', 'CREATE INDEX IF NOT EXISTS idx_celeone_posts_page_id ON posts (page_id, id DESC)'],
            ['comments', ['post_id', 'id'], 'idx_celeone_comments_post_id', 'CREATE INDEX IF NOT EXISTS idx_celeone_comments_post_id ON comments (post_id, id)'],
            ['comments', ['post_id', 'parent_id', 'id'], 'idx_celeone_comments_thread', 'CREATE INDEX IF NOT EXISTS idx_celeone_comments_thread ON comments (post_id, parent_id, id)'],
            ['reactions', ['post_id', 'reaction_type'], 'idx_celeone_reactions_post_type', 'CREATE INDEX IF NOT EXISTS idx_celeone_reactions_post_type ON reactions (post_id, reaction_type)'],
            ['friendships', ['user_id', 'status', 'friend_id'], 'idx_celeone_friendships_user_status', 'CREATE INDEX IF NOT EXISTS idx_celeone_friendships_user_status ON friendships (user_id, status, friend_id)'],
            ['friendships', ['friend_id', 'status', 'user_id'], 'idx_celeone_friendships_friend_status', 'CREATE INDEX IF NOT EXISTS idx_celeone_friendships_friend_status ON friendships (friend_id, status, user_id)'],
            ['chat_room_members', ['user_id', 'chat_room_id'], 'idx_celeone_room_members_user_room', 'CREATE INDEX IF NOT EXISTS idx_celeone_room_members_user_room ON chat_room_members (user_id, chat_room_id)'],
            ['chat_messages', ['chat_room_id', 'id'], 'idx_celeone_chat_messages_room_id', 'CREATE INDEX IF NOT EXISTS idx_celeone_chat_messages_room_id ON chat_messages (chat_room_id, id DESC)'],
            ['chat_rooms', ['updated_at', 'id'], 'idx_celeone_chat_rooms_updated', 'CREATE INDEX IF NOT EXISTS idx_celeone_chat_rooms_updated ON chat_rooms (updated_at DESC, id DESC)'],
            ['notifications', ['user_id', 'read_at', 'created_at'], 'idx_celeone_notifications_user_read_created', 'CREATE INDEX IF NOT EXISTS idx_celeone_notifications_user_read_created ON notifications (user_id, read_at, created_at DESC)'],
            ['firebase_documents', ['collection_path', 'updated_at'], 'idx_celeone_firebase_collection_updated', 'CREATE INDEX IF NOT EXISTS idx_celeone_firebase_collection_updated ON firebase_documents (collection_path, updated_at DESC)'],
            ['devices', ['user_id', 'last_seen_at'], 'idx_celeone_devices_user_seen', 'CREATE INDEX IF NOT EXISTS idx_celeone_devices_user_seen ON devices (user_id, last_seen_at DESC)'],
            ['push_tokens', ['user_id', 'provider', 'last_used_at'], 'idx_celeone_push_tokens_user_provider', 'CREATE INDEX IF NOT EXISTS idx_celeone_push_tokens_user_provider ON push_tokens (user_id, provider, last_used_at DESC)'],
            ['feed_items', ['user_id', 'rank', 'id'], 'idx_celeone_feed_items_user_rank', 'CREATE INDEX IF NOT EXISTS idx_celeone_feed_items_user_rank ON feed_items (user_id, rank DESC, id DESC)'],
            ['messages', ['conversation_id', 'id'], 'idx_celeone_messages_conversation_id', 'CREATE INDEX IF NOT EXISTS idx_celeone_messages_conversation_id ON messages (conversation_id, id DESC)'],
            ['conversation_members', ['user_id', 'conversation_id'], 'idx_celeone_conversation_members_user', 'CREATE INDEX IF NOT EXISTS idx_celeone_conversation_members_user ON conversation_members (user_id, conversation_id)'],
            ['statuses', ['user_id', 'expires_at'], 'idx_celeone_statuses_user_expiry', 'CREATE INDEX IF NOT EXISTS idx_celeone_statuses_user_expiry ON statuses (user_id, expires_at DESC)'],
            ['friend_requests', ['parent_id', 'status', 'id'], 'idx_celeone_friend_requests_receiver', 'CREATE INDEX IF NOT EXISTS idx_celeone_friend_requests_receiver ON friend_requests (parent_id, status, id DESC)'],
            ['friend_requests', ['user_id', 'status', 'id'], 'idx_celeone_friend_requests_sender', 'CREATE INDEX IF NOT EXISTS idx_celeone_friend_requests_sender ON friend_requests (user_id, status, id DESC)'],
            ['notification_inbox', ['user_id', 'status', 'id'], 'idx_celeone_notification_inbox_user', 'CREATE INDEX IF NOT EXISTS idx_celeone_notification_inbox_user ON notification_inbox (user_id, status, id DESC)'],
            ['notification_inbox', ['uid', 'read', 'created_at_ms'], 'idx_celeone_notification_inbox_uid', 'CREATE INDEX IF NOT EXISTS idx_celeone_notification_inbox_uid ON notification_inbox (uid, read, created_at_ms DESC)'],
            ['push_devices', ['uid', 'push_enabled', 'invalid_token'], 'idx_celeone_push_devices_uid_enabled', 'CREATE INDEX IF NOT EXISTS idx_celeone_push_devices_uid_enabled ON push_devices (uid, push_enabled, invalid_token)'],
            ['user_data', ['firebase_uid', 'user_id'], 'idx_celeone_user_data_firebase_user', 'CREATE INDEX IF NOT EXISTS idx_celeone_user_data_firebase_user ON user_data (firebase_uid, user_id)'],
        ];

        foreach ($indexes as [$table, $columns, $name, $sql]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $missing = false;
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $missing = true;
                    break;
                }
            }
            if (! $missing) {
                DB::statement($sql);
            }
        }

        // The Firestore compatibility layer uses jsonb containment for some
        // array filters. GIN is additive and helps those reads without changing
        // the compatibility API or payload format.
        if (Schema::hasTable('firebase_documents') && Schema::hasColumn('firebase_documents', 'payload')) {
            DB::statement('CREATE INDEX IF NOT EXISTS idx_celeone_firebase_payload_gin ON firebase_documents USING GIN (payload jsonb_path_ops)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'idx_celeone_posts_status_audience_id',
            'idx_celeone_posts_user_id',
            'idx_celeone_posts_page_id',
            'idx_celeone_comments_post_id',
            'idx_celeone_comments_thread',
            'idx_celeone_reactions_post_type',
            'idx_celeone_friendships_user_status',
            'idx_celeone_friendships_friend_status',
            'idx_celeone_room_members_user_room',
            'idx_celeone_chat_messages_room_id',
            'idx_celeone_chat_rooms_updated',
            'idx_celeone_notifications_user_read_created',
            'idx_celeone_firebase_collection_updated',
            'idx_celeone_devices_user_seen',
            'idx_celeone_push_tokens_user_provider',
            'idx_celeone_feed_items_user_rank',
            'idx_celeone_messages_conversation_id',
            'idx_celeone_conversation_members_user',
            'idx_celeone_statuses_user_expiry',
            'idx_celeone_friend_requests_receiver',
            'idx_celeone_friend_requests_sender',
            'idx_celeone_notification_inbox_user',
            'idx_celeone_notification_inbox_uid',
            'idx_celeone_push_devices_uid_enabled',
            'idx_celeone_user_data_firebase_user',
            'idx_celeone_firebase_payload_gin',
        ] as $index) {
            DB::statement('DROP INDEX IF EXISTS ' . $index);
        }
    }
};
