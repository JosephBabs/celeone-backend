<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema,Cache};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
class FriendContactTest extends TestCase {
 private array $people=[];
 protected function setUp(): void {
  parent::setUp();\Illuminate\Support\Facades\Queue::fake();config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','cache.default'=>'array']);DB::purge('sqlite');Cache::flush();
  Schema::create('users',function(Blueprint $t){$t->id();$t->string('name');$t->string('email');$t->string('password');$t->string('firebase_uid')->nullable();$t->string('legacy_firebase_uid')->nullable();$t->string('avatar_url')->nullable();$t->string('phone_number')->nullable();$t->boolean('firebase_disabled')->default(false);$t->timestamps();});
  Schema::create('user_data',function(Blueprint $t){$t->id();$t->integer('user_id');$t->text('profile_data');});
  Schema::create('friend_requests',function(Blueprint $t){$t->id();$t->string('public_id')->unique();$t->integer('user_id');$t->string('parent_id');$t->string('parent_type');$t->string('status');$t->text('data');$t->timestamp('deleted_at')->nullable();$t->timestamps();});
  Schema::create('friendships',function(Blueprint $t){$t->id();$t->integer('user_id');$t->integer('friend_id');$t->string('status');$t->unique(['user_id','friend_id']);$t->timestamps();});
  Schema::create('firebase_documents',function(Blueprint $t){$t->id();$t->string('collection_path');$t->string('document_id');$t->text('payload');$t->timestamp('firebase_updated_at')->nullable();$t->timestamps();});
  foreach(['a','b','c'] as $uid){$this->people[$uid]=User::create(['name'=>$uid,'email'=>$uid.'@example.test','password'=>'pass','firebase_uid'=>$uid]);DB::table('user_data')->insert(['user_id'=>$this->people[$uid]->id,'profile_data'=>'{}']);}
 }
 private function asUser(string $uid): void {Sanctum::actingAs($this->people[$uid]);}
 public function test_requests_are_idempotent_and_only_recipient_can_accept_or_reject(): void {
  $this->asUser('a');$id=$this->postJson('/api/v1/friend-requests',['receiverId'=>'b'])->assertCreated()->json('data.id');
  $this->postJson('/api/v1/friend-requests',['receiverId'=>'b'])->assertOk()->assertJsonPath('data.id',$id);
  $this->postJson('/api/v1/friend-requests',['receiverId'=>'a'])->assertUnprocessable();
  $this->asUser('c');$this->postJson('/api/v1/friend-requests/'.$id.'/accept')->assertForbidden();
  $this->asUser('b');$this->postJson('/api/v1/friend-requests',['receiverId'=>'a'])->assertOk()->assertJsonPath('data.id',$id);
  $this->postJson('/api/v1/friend-requests/'.$id.'/accept')->assertOk();$this->postJson('/api/v1/friend-requests/'.$id.'/accept')->assertOk();
  $this->assertSame(1,DB::table('friend_requests')->count());$this->assertSame(1,DB::table('friendships')->count());
  $this->postJson('/api/v1/friend-requests/'.$id.'/reject')->assertConflict();
  $this->getJson('/api/v1/friends')->assertOk()->assertJsonCount(1,'data');
  $this->deleteJson('/api/v1/friends/a')->assertOk();$this->getJson('/api/v1/friends')->assertJsonCount(0,'data');
 }
 public function test_sender_cancels_and_recipient_rejects_with_authoritative_status(): void {
  $this->asUser('a');$id=$this->postJson('/api/v1/friend-requests',['receiverId'=>'b'])->json('data.id');
  $this->asUser('b');$this->postJson('/api/v1/friend-requests/'.$id.'/cancel')->assertForbidden();$this->postJson('/api/v1/friend-requests/'.$id.'/reject')->assertOk();
  $this->asUser('a');$this->getJson('/api/v1/friend-requests')->assertJsonPath('data.outgoing.0.status','rejected');
  $new=$this->postJson('/api/v1/friend-requests',['receiverId'=>'b'])->assertCreated()->json('data.id');$this->postJson('/api/v1/friend-requests/'.$new.'/cancel')->assertOk();
  $this->asUser('b');$this->postJson('/api/v1/friend-requests/'.$new.'/accept')->assertConflict();
 }
 public function test_contacts_match_exact_numbers_without_suffix_collisions_or_private_profiles(): void {
  $this->people['b']->forceFill(['phone_number'=>'+33123456789'])->save();$this->people['c']->forceFill(['phone_number'=>'+44123456789'])->save();
  $this->asUser('a');$this->postJson('/api/v1/contacts/match',['phoneNumbers'=>['+33123456789']])->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.uid','b');
  DB::table('user_data')->where('user_id',$this->people['b']->id)->update(['profile_data'=>'{"phoneDiscoveryEnabled":false}']);
  $this->postJson('/api/v1/contacts/match',['phoneNumbers'=>['+33123456789']])->assertJsonCount(0,'data');
  $this->postJson('/api/v1/contacts/match',['phoneNumbers'=>['123456789']])->assertUnprocessable();
 }
}
