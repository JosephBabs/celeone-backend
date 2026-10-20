<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $legacy = DB::table('firebase_documents')->where('collection_path', 'documents')->where('document_id', 'revenue_policy')->first();
            $document = DB::table('documents')->where('public_id', 'revenue_policy')->first();
            $payload = json_decode((string) ($legacy->payload ?? $document->data ?? '{}'), true) ?: [];
            $defaults = config('revenue_policy');
            $policy = array_replace($defaults, array_intersect_key($payload, $defaults));
            DB::table('app_configs')->insertOrIgnore([
                'key' => 'revenue_policy', 'legacy_id' => 'revenue_policy', 'value' => json_encode($policy), 'public' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            // Keep the original imported snapshot for audit/rollback; remove only
            // the misplaced library entries, after storing the canonical policy.
            DB::table('documents')->where('public_id', 'revenue_policy')->update(['deleted_at' => now()]);
            DB::table('firebase_documents')->where('collection_path', 'documents')->where('document_id', 'revenue_policy')->delete();
        });
    }

    public function down(): void
    {
        // Retain administrator edits. Configuration must not become a public document again.
    }
};
