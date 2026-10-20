<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void {
        $sql=file_get_contents(__DIR__.'/../sql/feed_metadata.sql');
        $current=DB::selectOne("SELECT pg_get_functiondef('celeone_feed_metadata()'::regprocedure) AS definition");
        // Production may have a DBA-owned trigger; allow its pre-deployed update.
        if (!str_contains($current->definition,"'reels'")) DB::unprepared(substr($sql,0,strpos($sql,'CREATE TRIGGER')));
        DB::statement("UPDATE firebase_documents SET payload=payload WHERE collection_path='reels'");
    }
    public function down(): void {}
};
