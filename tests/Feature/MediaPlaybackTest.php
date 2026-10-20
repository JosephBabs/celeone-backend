<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
class MediaPlaybackTest extends TestCase {
 protected function setUp(): void {parent::setUp();$this->travelTo(now()->startOfSecond());config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:']);DB::purge('sqlite');
 Schema::create('users',function(Blueprint $t){$t->id();$t->string('name');$t->string('email');$t->string('password');$t->timestamps();});
 foreach(['songs','videos','media_consumption_events','creator_metrics'] as $table)Schema::create($table,function(Blueprint $t){$t->id();$t->string('public_id')->unique();$t->integer('user_id')->nullable();$t->string('parent_type')->nullable();$t->string('parent_id')->nullable();$t->string('status')->nullable();$t->text('data');$t->timestamp('deleted_at')->nullable();$t->timestamps();});
 Schema::create('app_configs',function(Blueprint $t){$t->id();$t->string('key');$t->text('value');});
 Schema::create('firebase_documents',function(Blueprint $t){$t->id();$t->string('collection_path');$t->string('document_id');$t->text('payload');$t->timestamp('firebase_updated_at')->nullable();$t->timestamps();});
 (require database_path('migrations/2026_09_19_000900_create_media_playback_sessions.php'))->up();
 $user=User::create(['name'=>'listener','email'=>'listener@example.test','password'=>'pass']);Sanctum::actingAs($user);
 DB::table('songs')->insert(['public_id'=>'song','data'=>'{"creatorId":"owner"}']);
 }
 private function event(string $type,string $id,array $extra=[]): array {return array_replace(['eventType'=>$type,'mediaType'=>'audio','contentId'=>'song','sessionId'=>'session','clientEventId'=>$id,'startedAtMs'=>now()->getTimestampMs()-20000,'endedAtMs'=>now()->getTimestampMs(),'durationSec'=>180,'positionSec'=>15],$extra);}
 public function test_actual_time_is_idempotent_bounded_and_averaged_per_session(): void {
  $start=$this->event('start','start',['endedAtMs'=>now()->getTimestampMs()-15000]);$this->postJson('/api/v1/media/consumption',$start)->assertOk();
  $watch=$this->event('watch','watch',['watchedSeconds'=>900]);$res=$this->postJson('/api/v1/media/consumption',$watch)->assertOk();$seconds=$res->json('data.watched_seconds');$this->assertGreaterThan(14,$seconds);$this->assertLessThan(16,$seconds);
  $this->postJson('/api/v1/media/consumption',$watch)->assertOk()->assertJsonPath('data.duplicate',true);
  $this->assertSame(2,DB::table('media_consumption_events')->count());
  $metrics=json_decode(DB::table('songs')->value('data'),true);$this->assertSame(1,$metrics['plays']);$this->assertSame(1,$metrics['uniqueAccountStreams']);$this->assertEquals($seconds,$metrics['avgListenTimeSec']);
  $this->postJson('/api/v1/media/consumption',$this->event('watch','overlap',['watchedSeconds'=>15,'endedAtMs'=>$watch['endedAtMs']]))->assertOk()->assertJsonPath('data.watched_seconds',0);
 }
 public function test_unknown_content_and_watch_without_session_are_rejected(): void {
  $this->postJson('/api/v1/media/consumption',$this->event('start','bad',['contentId'=>'absent']))->assertNotFound();
  $this->postJson('/api/v1/media/consumption',$this->event('watch','watch',['watchedSeconds'=>15]))->assertUnprocessable();
  $this->assertSame(0,DB::table('media_consumption_events')->count());
 }
 public function test_admin_metrics_use_actual_watch_sessions_and_restrict_access(): void {
  $this->getJson('/api/v1/admin/media-metrics')->assertForbidden();
  $user=User::first();$user->setAttribute('role','admin');Sanctum::actingAs($user);
  $this->postJson('/api/v1/media/consumption',$this->event('start','start'))->assertOk();
  $this->getJson('/api/v1/admin/media-metrics')->assertOk()->assertJsonPath('data.items',[]);
  DB::table('media_playback_sessions')->update(['watched_seconds'=>25]);
  $result=$this->getJson('/api/v1/admin/media-metrics')->assertOk()->json('data.items');
  $this->assertSame(1,$result['audio:song']['plays']);$this->assertEquals(25,$result['audio:song']['avgListenTimeSec']);
 }
}
