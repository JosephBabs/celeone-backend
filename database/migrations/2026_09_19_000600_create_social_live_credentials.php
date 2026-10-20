<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { if(Schema::hasTable('social_live_credentials')) return; Schema::create('social_live_credentials',function(Blueprint $t){ $t->string('session_id')->primary();$t->unsignedBigInteger('user_id');$t->string('stream_name')->unique();$t->string('secret_hash',64);$t->timestamp('expires_at');$t->timestamps(); }); }
 public function down(): void { Schema::dropIfExists('social_live_credentials'); }
};
