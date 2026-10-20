<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_professional_profiles', function (Blueprint $table) {
            $table->string('profile_photo_path', 1000)->nullable();
            $table->string('profile_photo_url', 2000)->nullable();
            $table->jsonb('projects')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_professional_profiles', function (Blueprint $table) {
            $table->dropColumn(['profile_photo_path', 'profile_photo_url', 'projects']);
        });
    }
};
