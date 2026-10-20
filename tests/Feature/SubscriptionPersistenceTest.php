<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
class SubscriptionPersistenceTest extends TestCase {
 private string $key; private User $user;
 protected function setUp(): void {parent::setUp();config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:']);DB::purge('sqlite');
 Schema::create('users',function(Blueprint $t){$t->id();$t->string('name');$t->string('email');$t->string('password');$t->string('firebase_uid')->nullable();$t->string('legacy_firebase_uid')->nullable();$t->timestamps();});
 Schema::create('user_subscriptions',function(Blueprint $t){$t->id();$t->string('public_id')->unique();$t->integer('user_id');$t->string('status');$t->text('data');$t->timestamp('deleted_at')->nullable();$t->timestamps();});
 Schema::create('firebase_documents',function(Blueprint $t){$t->id();$t->string('collection_path');$t->string('document_id');$t->text('payload');$t->timestamp('firebase_updated_at')->nullable();$t->timestamps();});
 $this->user=User::create(['name'=>'member','email'=>'member@example.test','password'=>'pass','firebase_uid'=>'member']);
 $this->key=tempnam(sys_get_temp_dir(),'sub-key');file_put_contents($this->key,'test-secret');config(['services.notifications.internal_key_path'=>$this->key]);
 }
 protected function tearDown(): void {unlink($this->key);parent::tearDown();}
 public function test_verified_internal_payment_is_idempotent_and_subscription_is_scoped_to_owner(): void {
 $data=['uid'=>'member','transactionId'=>'123','packageId'=>'monthly','packageName'=>'Monthly','amount'=>2000,'durationDays'=>30,'currency'=>'XOF','startAt'=>now()->getTimestampMs()];
 $this->postJson('/api/v1/internal/subscriptions/confirmed',$data)->assertForbidden();
 $this->withHeader('X-Celeone-Internal','test-secret')->postJson('/api/v1/internal/subscriptions/confirmed',$data)->assertOk();
 $this->withHeader('X-Celeone-Internal','test-secret')->postJson('/api/v1/internal/subscriptions/confirmed',array_replace($data,['startAt'=>now()->addDay()->getTimestampMs()]))->assertOk()->assertJsonPath('data.startAt',$data['startAt']);
 $this->assertSame(1,DB::table('user_subscriptions')->count());
 Sanctum::actingAs($this->user);$this->getJson('/api/v1/subscriptions')->assertOk()->assertJsonCount(1,'data');
 $this->postJson('/api/v1/subscriptions',['status'=>'active'])->assertForbidden();$this->patchJson('/api/v1/subscriptions/fedapay_123',['status'=>'active'])->assertForbidden();
 $other=User::create(['name'=>'other','email'=>'other@example.test','password'=>'pass']);Sanctum::actingAs($other);$this->getJson('/api/v1/subscriptions')->assertJsonCount(0,'data');$this->getJson('/api/v1/subscriptions/fedapay_123')->assertNotFound();
 }
}
