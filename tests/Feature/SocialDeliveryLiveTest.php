<?php
namespace Tests\Feature;
use App\Models\User;
use App\Jobs\{NotifyCommentActivity,NotifyPageFollowers};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema,Cache,Http};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
class SocialDeliveryLiveTest extends TestCase {
 private User $owner;private User $actor;private User $reader;private string $keyPath;
 protected function setUp(): void {
  parent::setUp();config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','cache.default'=>'array']);DB::purge('sqlite');Cache::flush();
  Schema::create('users',function(Blueprint $t){$t->id();$t->string('name');$t->string('email');$t->string('password');$t->string('firebase_uid')->nullable();$t->string('legacy_firebase_uid')->nullable();$t->string('role')->default('user');$t->boolean('firebase_disabled')->default(false);$t->timestamp('firebase_created_at')->nullable();$t->timestamps();});
  Schema::create('firebase_documents',function(Blueprint $t){$t->id();$t->string('collection_path');$t->string('document_id');$t->string('parent_document_path')->nullable();$t->text('payload');$t->timestamp('firebase_updated_at')->nullable();$t->string('feed_status')->default('published');$t->string('feed_audience')->default('public');$t->string('feed_author_id')->nullable();$t->string('feed_owner_id')->nullable();$t->string('feed_page_id')->nullable();$t->timestamps();});
  Schema::create('following',function(Blueprint $t){$t->id();$t->integer('user_id');$t->string('parent_id');$t->string('status');});
  Schema::create('notification_inbox',function(Blueprint $t){$t->id();$t->string('public_id')->unique();$t->integer('user_id');$t->string('parent_type');$t->string('parent_id');$t->string('status');$t->string('title');$t->text('body');$t->text('data');$t->timestamp('deleted_at')->nullable();$t->timestamps();});
  Schema::create('social_live_sessions',function(Blueprint $t){$t->id();$t->string('public_id');$t->integer('user_id');$t->string('status');$t->timestamp('deleted_at')->nullable();$t->timestamps();});
  (require database_path('migrations/2026_09_19_000600_create_social_live_credentials.php'))->up();
  foreach(['owner','actor','reader'] as $who) $this->$who=User::create(['name'=>$who,'email'=>$who.'@example.test','password'=>'Pass123','firebase_uid'=>$who,'firebase_created_at'=>now()->subDays(60)]);
  $this->keyPath=tempnam(sys_get_temp_dir(),'celeone-test-');file_put_contents($this->keyPath,'isolated-test-key');config(['services.notifications.internal_key_path'=>$this->keyPath,'services.social_live.internal_key_path'=>$this->keyPath]);

 }
 protected function tearDown(): void {if(isset($this->keyPath))unlink($this->keyPath);parent::tearDown();}
 private function doc(string $collection,string $id,array $data,array $extra=[]): void {DB::table('firebase_documents')->insert(array_merge(['collection_path'=>$collection,'document_id'=>$id,'payload'=>json_encode($data),'created_at'=>now(),'updated_at'=>now()],$extra));}
 private function seedPost(bool $page=false): void { $this->doc('posts','post',['authorId'=>$page?'page':'owner','authorOwnerId'=>'owner','status'=>'published'],['feed_owner_id'=>'owner','feed_author_id'=>$page?'page':'owner','feed_page_id'=>$page?'page':null]);if($page)$this->doc('pages','page',['ownerId'=>'owner','name'=>'Page']); }
 public function test_comment_and_reply_reach_correct_owners_without_self_or_duplicates(): void {
  $this->seedPost();$this->doc('posts/post/comments','parent',['userId'=>'reader','text'=>'Parent']);$this->doc('posts/post/comments','reply',['userId'=>'actor','parentId'=>'parent','text'=>'Reply']);
  $job=new NotifyCommentActivity('post','reply',$this->actor->id);$job->handle();$job->handle();
  $this->assertSame(2,DB::table('notification_inbox')->count());
  $this->assertSame('reply',json_decode(DB::table('notification_inbox')->where('user_id',$this->reader->id)->value('data'),true)['type']);
  $this->assertSame(0,DB::table('notification_inbox')->where('user_id',$this->actor->id)->count());
 }
 public function test_page_comment_notifies_the_page_owner(): void {
  $this->seedPost(true);$this->doc('posts/post/comments','comment',['userId'=>'actor','text'=>'Hello']);(new NotifyCommentActivity('post','comment',$this->actor->id))->handle();
  $this->assertSame($this->owner->id,(int)DB::table('notification_inbox')->value('user_id'));
 }
 public function test_followed_user_and_page_alerts_respect_bell_unfollow_and_idempotency(): void {
  foreach([false,true] as $isPage){
   DB::table('firebase_documents')->delete();DB::table('notification_inbox')->delete();DB::table('following')->delete();$this->seedPost($isPage);$target=$isPage?'page':'owner';
   DB::table('following')->insert(['user_id'=>$this->reader->id,'parent_id'=>$target,'status'=>'active']);
   $job=new NotifyPageFollowers('post',$this->owner->id);$job->handle();$this->assertSame(0,DB::table('notification_inbox')->count());
   $this->doc('users/reader/post_alerts',$target,['enabled'=>true]);$job->handle();$job->handle();$this->assertSame(1,DB::table('notification_inbox')->count());
   DB::table('notification_inbox')->delete();DB::table('following')->update(['status'=>'inactive']);$job->handle();$this->assertSame(0,DB::table('notification_inbox')->count());
  }
 }
 public function test_push_targets_only_enabled_devices_and_retries_without_duplicate_inbox(): void {
  $this->seedPost();$this->doc('posts/post/comments','comment',['userId'=>'actor','text'=>'Hello']);
  $this->doc('users/owner/push_devices','ok',['fcmToken'=>'test-enabled','enabled'=>true]);$this->doc('users/owner/push_devices','off',['fcmToken'=>'test-disabled','enabled'=>false]);
  Http::fake(['http://127.0.0.1:4000/*'=>Http::sequence()->push([],503)->push(['delivered'=>1],200)]);
  $job=new NotifyCommentActivity('post','comment',$this->actor->id);
  try{$job->handle();$this->fail('Expected retryable push error');}catch(\Illuminate\Http\Client\RequestException $e){}
  $this->assertSame('pending',DB::table('notification_inbox')->value('status'));$job->handle();
  $this->assertSame(1,DB::table('notification_inbox')->count());$this->assertSame('sent',DB::table('notification_inbox')->value('status'));
  Http::assertSent(fn($r)=>$r['tokens']===['test-enabled'] && $r['data']['type']==='comment');
 }
 public function test_live_requires_auth_host_ownership_and_account_age(): void {
  $payload=['hostType'=>'user','hostId'=>'owner','title'=>'Test'];
  $this->postJson('/api/v1/social/live/provision',$payload)->assertUnauthorized();Sanctum::actingAs($this->actor);
  $this->postJson('/api/v1/social/live/provision',$payload)->assertForbidden();
  $this->actor->forceFill(['firebase_created_at'=>now()])->save();Sanctum::actingAs($this->actor->fresh());$payload['hostId']='actor';$this->postJson('/api/v1/social/live/provision',$payload)->assertForbidden();
 }
 public function test_live_callback_requires_private_secret_and_stop_is_owner_only(): void {
  Sanctum::actingAs($this->owner);$session=$this->postJson('/api/v1/social/live/provision',['hostType'=>'user','hostId'=>'owner','title'=>'Test'])->assertOk()->json('data');
  [$name,$query]=explode('?',$session['streamKey']);parse_str($query,$args);
  $this->assertStringNotContainsString($args['token'],$session['playbackUrl']);
  $this->postJson('/api/v1/internal/social-live/publish',['app'=>'live','name'=>$name,'token'=>$args['token']])->assertForbidden();
  $headers=['X-Celeone-Internal'=>'isolated-test-key'];
  $this->postJson('/api/v1/internal/social-live/publish',['app'=>'live','name'=>$name,'token'=>'wrong'],$headers)->assertForbidden();
  $this->postJson('/api/v1/internal/social-live/publish',['app'=>'live','name'=>$name,'token'=>$args['token']],$headers)->assertOk();
  $this->assertSame('live',json_decode(DB::table('firebase_documents')->where('document_id',$session['sessionId'])->value('payload'),true)['status']);
  Sanctum::actingAs($this->actor);$this->postJson('/api/v1/social/live/stop',['sessionId'=>$session['sessionId']])->assertForbidden();
  Sanctum::actingAs($this->owner);$this->postJson('/api/v1/social/live/stop',['sessionId'=>$session['sessionId']])->assertOk();
  $this->assertSame(0,DB::table('social_live_credentials')->count());
  $this->postJson('/api/v1/internal/social-live/publish',['app'=>'live','name'=>$name,'token'=>$args['token']],$headers)->assertForbidden();
 }
 public function test_notifications_are_scoped_to_the_recipient(): void {
  $this->seedPost();$this->doc('posts/post/comments','comment',['userId'=>'actor','text'=>'Hello']);(new NotifyCommentActivity('post','comment',$this->actor->id))->handle();
  $id=DB::table('notification_inbox')->value('public_id');Sanctum::actingAs($this->actor);
  $this->getJson('/api/v1/notifications?user_id='.$this->owner->id)->assertOk()->assertJsonCount(0,'data');
  $this->getJson('/api/v1/notifications/'.$id)->assertNotFound();
 }
 public function test_quiz_uses_authenticated_user_without_registration_and_scores_on_server(): void {
  $this->doc('jeunesse_quizzes','quiz1',['questions'=>[['id'=>'q1','answerIndex'=>1]],'durationSec'=>60]);
  $this->doc('jeunesse_quizzes','quiz2',['questions'=>[['id'=>'q1','answerIndex'=>0]],'durationSec'=>60]);
  Sanctum::actingAs($this->actor);
  $this->postJson('/api/v1/youth/quizzes/quiz1/attempt',['userId'=>'owner','identifier'=>'fake','score'=>999,'answers'=>['q1'=>1]])->assertCreated()->assertJsonPath('data.userId','actor')->assertJsonPath('data.score',1);
  $this->postJson('/api/v1/youth/quizzes/quiz1/attempt',['answers'=>['q1'=>0]])->assertOk()->assertJsonPath('data.score',1);
  $this->postJson('/api/v1/youth/quizzes/quiz2/attempt',['answers'=>[],'autoSubmitted'=>true])->assertCreated()->assertJsonPath('data.score',0);
  $this->getJson('/api/v1/youth/quiz-attempts')->assertOk()->assertJsonCount(2,'data');
  $this->getJson('/api/v1/resources/jeunesse_quiz_attempts?__kind=collection')->assertForbidden();
  Sanctum::actingAs($this->owner);$this->getJson('/api/v1/youth/quiz-attempts?userId=actor')->assertOk()->assertJsonCount(0,'data');
 }
 public function test_device_and_alert_preferences_cannot_be_changed_for_another_user(): void {
  Sanctum::actingAs($this->actor);
  $this->putJson('/api/v1/resources/users/owner/push_devices/device',['data'=>['fcmToken'=>'attacker']])->assertForbidden();
  $this->putJson('/api/v1/resources/users/owner/post_alerts/page',['data'=>['enabled'=>true]])->assertForbidden();
 }

}
