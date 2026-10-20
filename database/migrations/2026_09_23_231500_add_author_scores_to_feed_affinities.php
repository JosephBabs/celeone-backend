<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('feed_affinities', 'author_scores')) {
            Schema::table('feed_affinities', function (Blueprint $table): void {
                $table->jsonb('author_scores')->nullable();
            });

            if (DB::getDriverName() === 'pgsql') {
                DB::statement(
                    "UPDATE feed_affinities
                     SET author_scores = '{}'::jsonb
                     WHERE author_scores IS NULL"
                );
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('feed_affinities', 'author_scores')) {
            Schema::table('feed_affinities', function (Blueprint $table): void {
                $table->dropColumn('author_scores');
            });
        }
    }
};
