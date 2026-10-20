<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
class RevenuePolicyTest extends TestCase {
 protected function setUp(): void {
  parent::setUp(); config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:']); DB::purge('sqlite');
  Schema::create('users',function(Blueprint $t){$t->id();$t->string('name');$t->string('email');$t->string('password');$t->string('role')->default('user');$t->timestamps();});
  Schema::create('app_configs',function(Blueprint $t){$t->id();$t->string('key')->unique();$t->string('legacy_id');$t->text('value');$t->boolean('public');$t->timestamps();});
  Schema::create('documents',function(Blueprint $t){$t->id();$t->string('public_id');$t->text('data');$t->timestamp('deleted_at')->nullable();});
  Schema::create('firebase_documents',function(Blueprint $t){$t->id();$t->string('collection_path');$t->string('document_id');$t->text('payload');});
 }
 public function test_migration_preserves_policy_removes_library_entry_and_never_overwrites_admin_edits(): void {
  DB::table('firebase_documents')->insert(['collection_path'=>'documents','document_id'=>'revenue_policy','payload'=>json_encode(['songMinPlays'=>123,'playsWeight'=>7])]);
  DB::table('documents')->insert(['public_id'=>'revenue_policy','data'=>'{}']);
  $migration=require database_path('migrations/2026_09_19_000700_move_revenue_policy_to_settings.php');$migration->up();
  $this->assertSame(123,json_decode(DB::table('app_configs')->value('value'),true)['songMinPlays']);
  $this->assertSame(0,DB::table('firebase_documents')->count());$this->assertNotNull(DB::table('documents')->value('deleted_at'));
  DB::table('app_configs')->update(['value'=>'{"songMinPlays":456}']);$migration->up();
  $this->assertSame(456,json_decode(DB::table('app_configs')->value('value'),true)['songMinPlays']);
 }
 public function test_settings_are_readable_but_only_admin_can_save_valid_complete_policy(): void {
  $user=User::create(['name'=>'Test','email'=>'test@example.test','password'=>'pass','role'=>'user']);Sanctum::actingAs($user);
  $policy=config('revenue_policy');
  $this->getJson('/api/v1/app-config/revenue_policy')->assertOk()->assertJsonPath('data.playsWeight',1);
  $this->putJson('/api/v1/app-config/revenue_policy',['value'=>$policy])->assertForbidden();
  $user->forceFill(['role'=>'admin'])->save();Sanctum::actingAs($user);
  $this->putJson('/api/v1/app-config/revenue_policy',['value'=>array_replace($policy,['targetCreatorPoolPct'=>2])])->assertUnprocessable();
  $this->putJson('/api/v1/app-config/revenue_policy',['value'=>array_replace($policy,['playsWeight'=>3])])->assertOk();
  $this->getJson('/api/v1/app-config/revenue_policy')->assertOk()->assertJsonPath('data.playsWeight',3);
 }
}
