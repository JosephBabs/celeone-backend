<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legacy_snapshots', function (Blueprint $table): void {
            $table->string('source_document_id', 700)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Do not narrow preserved legacy identifiers during rollback.
    }
};
