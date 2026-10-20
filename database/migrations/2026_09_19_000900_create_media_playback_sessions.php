<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {Schema::create('media_playback_sessions',function(Blueprint $t){$t->id();$t->foreignId('user_id')->constrained()->cascadeOnDelete();$t->string('session_id',191);$t->string('media_type',10);$t->string('content_id',191);$t->double('watched_seconds')->default(0);$t->double('duration_seconds')->default(0);$t->double('position_seconds')->default(0);$t->unsignedBigInteger('last_end_ms')->default(0);$t->timestamps();$t->unique(['user_id','session_id','media_type','content_id'],'media_session_identity');$t->index(['media_type','content_id']);});}
 public function down(): void {Schema::dropIfExists('media_playback_sessions');}
};
