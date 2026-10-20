<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('firebase_disabled')->default(false)->index();
            $table->string('phone_number', 40)->nullable()->index();
            $table->jsonb('firebase_custom_claims')->nullable();
            $table->timestampTz('firebase_created_at')->nullable();
            $table->timestampTz('firebase_last_sign_in_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['firebase_disabled', 'phone_number', 'firebase_custom_claims', 'firebase_created_at', 'firebase_last_sign_in_at']);
        });
    }
};
