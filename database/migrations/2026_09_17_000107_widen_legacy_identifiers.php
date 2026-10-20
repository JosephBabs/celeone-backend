<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firebase_documents', function (Blueprint $table): void {
            $table->string('document_id', 700)->change();
        });
        foreach (['auth_identities', 'agenda_items', 'app_configs', 'parishes', 'parish_visit_plans', 'parish_tracking_sessions', 'member_cards', 'member_card_requests', 'reports', 'reels', 'conversations', 'feed_items'] as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'legacy_id')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->string('legacy_id', 700)->change();
                });
            }
        }
    }

    public function down(): void
    {
        // Legacy identifiers are intentionally not narrowed during rollback.
    }
};
