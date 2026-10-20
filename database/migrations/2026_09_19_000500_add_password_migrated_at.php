<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { if (Schema::hasColumn('users', 'password_migrated_at')) return; Schema::table('users', fn (Blueprint $table) => $table->timestamp('password_migrated_at')->nullable()); }
    public function down(): void { Schema::table('users', fn (Blueprint $table) => $table->dropColumn('password_migrated_at')); }
};
