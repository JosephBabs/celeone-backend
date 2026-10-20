<?php
namespace Tests\Feature;
use App\Models\User;
use App\Services\FirebaseTokenVerifier;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Cache, DB, Hash, Mail, Schema};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PasswordMigrationSecurityTest extends TestCase
{
    private User $owner;
    private User $other;
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','cache.default'=>'array']);
        DB::purge('sqlite');
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('email')->unique(); $t->string('password');
            $t->string('firebase_uid')->nullable(); $t->string('legacy_firebase_uid')->nullable();
            $t->string('role')->default('user'); $t->boolean('firebase_disabled')->default(false);
            $t->string('avatar_url')->nullable(); $t->timestamp('email_verified_at')->nullable();
            $t->timestamp('password_migrated_at')->nullable(); $t->timestamps();
        });
        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id(); $t->morphs('tokenable'); $t->string('name'); $t->string('token',64)->unique();
            $t->text('abilities')->nullable(); $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable(); $t->timestamps();
        });
        Schema::create('user_data', function (Blueprint $t) { $t->id(); $t->integer('user_id'); $t->string('firebase_uid')->nullable(); $t->string('role')->default('user'); $t->timestamps(); });
        $this->owner=User::create(['name'=>'Owner','email'=>'owner@example.test','password'=>'Oldpass1','firebase_uid'=>'owner-uid']);
        $this->other=User::create(['name'=>'Other','email'=>'other@example.test','password'=>'Otherpass1','firebase_uid'=>'other-uid']);
        Cache::flush(); Mail::fake();
    }
    private function key(string $kind, string $email): string { return 'celeone:password-migration:v2:'.$kind.':'.hash('sha256',$email); }
    private function payload(string $email, string $token): array { return ['email'=>$email,'verification_token'=>$token,'password'=>'Newpass2','password_confirmation'=>'Newpass2']; }
    public function test_migration_requires_authentication(): void
    {
        foreach (['request','verify','complete'] as $action) $this->postJson('/api/v1/auth/password-migration/'.$action,[])->assertUnauthorized();
    }
    public function test_signed_in_user_cannot_target_another_account_even_with_its_proof(): void
    {
        Sanctum::actingAs($this->owner);
        $proof=str_repeat('x',64);
        Cache::put($this->key('token',$this->other->email),Hash::make($proof),900);
        $this->postJson('/api/v1/auth/password-migration/request',['email'=>$this->other->email])->assertForbidden();
        $this->postJson('/api/v1/auth/password-migration/verify',['email'=>$this->other->email,'code'=>'123456'])->assertForbidden();
        $this->postJson('/api/v1/auth/password-migration/complete',$this->payload($this->other->email,$proof))->assertForbidden();
        $this->assertTrue(Hash::check('Otherpass1',$this->other->fresh()->password));
    }
    public function test_proof_is_bound_to_email_and_is_single_use(): void
    {
        Sanctum::actingAs($this->owner);
        $proof=str_repeat('x',64);
        Cache::put($this->key('token',$this->other->email),Hash::make($proof),900);
        $this->postJson('/api/v1/auth/password-migration/complete',$this->payload($this->owner->email,$proof))->assertUnprocessable();
        Cache::put($this->key('token',$this->owner->email),Hash::make($proof),900);
        $this->owner->createToken('old-device');
        $result=$this->postJson('/api/v1/auth/password-migration/complete',$this->payload($this->owner->email,$proof))->assertOk()->assertJsonPath('data.password_migrated',true);
        $this->assertNotEmpty($result->json('data.access_token'));
        $this->assertTrue(Hash::check('Newpass2',$this->owner->fresh()->password));
        $this->assertNotNull($this->owner->fresh()->password_migrated_at);
        $this->assertSame(1,$this->owner->tokens()->count());
        $this->postJson('/api/v1/auth/password-migration/complete',$this->payload($this->owner->email,$proof))->assertUnprocessable();
    }
    public function test_otp_is_one_use_and_limited_to_five_attempts(): void
    {
        Sanctum::actingAs($this->owner);
        $key=$this->key('otp',$this->owner->email);
        Cache::put($key,Hash::make('123456'),600);
        $this->postJson('/api/v1/auth/password-migration/verify',['email'=>$this->owner->email,'code'=>'123456'])->assertOk();
        $this->postJson('/api/v1/auth/password-migration/verify',['email'=>$this->owner->email,'code'=>'123456'])->assertUnprocessable();
        Cache::put($key,Hash::make('123456'),600); Cache::put($key.':attempts',5,600);
        $this->postJson('/api/v1/auth/password-migration/verify',['email'=>$this->owner->email,'code'=>'123456'])->assertUnprocessable();
        $this->assertNull(Cache::get($key));
    }
    public function test_existing_backend_password_login_records_completion_for_other_devices(): void
    {
        $this->postJson('/api/v1/auth/login',['email'=>$this->owner->email,'password'=>'Oldpass1'])->assertOk()->assertJsonPath('data.password_migrated',true);
        $this->assertNotNull($this->owner->fresh()->password_migrated_at);
    }
    public function test_migrated_account_cannot_exchange_an_old_firebase_password(): void
    {
        $this->owner->forceFill(['password_migrated_at'=>now()])->save();
        $this->mock(FirebaseTokenVerifier::class, function ($mock) { $mock->shouldReceive('verify')->andReturn(['localId'=>'owner-uid','email'=>'owner@example.test','verifiedSignInProvider'=>'password']); });
        $this->postJson('/api/v1/auth/firebase/exchange',['id_token'=>'test'])->assertForbidden();
    }
    public function test_google_identity_can_sign_in_to_its_linked_account(): void
    {
        $this->owner->forceFill(['password_migrated_at'=>now()])->save();
        $this->mock(FirebaseTokenVerifier::class, function ($mock) { $mock->shouldReceive('verify')->andReturn(['localId'=>'owner-uid','email'=>'owner@example.test','emailVerified'=>true,'verifiedSignInProvider'=>'google.com']); });
        $this->postJson('/api/v1/auth/firebase/exchange',['id_token'=>'test'])->assertOk()->assertJsonPath('data.user.id',$this->owner->id)->assertJsonPath('data.password_migrated',true);
    }
    public function test_email_match_cannot_rebind_another_firebase_uid(): void
    {
        $this->mock(FirebaseTokenVerifier::class, function ($mock) { $mock->shouldReceive('verify')->andReturn(['localId'=>'attacker-uid','email'=>'owner@example.test','emailVerified'=>true,'verifiedSignInProvider'=>'google.com']); });
        $this->postJson('/api/v1/auth/firebase/exchange',['id_token'=>'test'])->assertForbidden();
        $this->assertSame('owner-uid',$this->owner->fresh()->firebase_uid);
    }
    public function test_recovery_requires_its_own_email_proof_and_changes_only_that_account(): void
    {
        $proof=str_repeat('r',64);
        Cache::put($this->key('token',$this->owner->email),Hash::make($proof),900);
        $this->postJson('/api/v1/auth/password-reset/complete',$this->payload($this->owner->email,$proof))->assertUnprocessable();
        Cache::put('celeone:password-reset:v2:token:'.hash('sha256',$this->owner->email),Hash::make($proof),900);
        $this->postJson('/api/v1/auth/password-reset/complete',$this->payload($this->other->email,$proof))->assertUnprocessable();
        $this->postJson('/api/v1/auth/password-reset/complete',$this->payload($this->owner->email,$proof))->assertOk();
        $this->assertTrue(Hash::check('Newpass2',$this->owner->fresh()->password));
        $this->assertTrue(Hash::check('Otherpass1',$this->other->fresh()->password));
        $this->postJson('/api/v1/auth/password-reset/complete',$this->payload($this->owner->email,$proof))->assertUnprocessable();
    }
    public function test_requesting_recovery_for_unknown_email_does_not_reveal_existence(): void
    {
        $this->postJson('/api/v1/auth/password-reset/request',['email'=>'missing@example.test'])->assertOk()->assertJsonPath('data.sent',true);
    }

    public function test_security_email_renders_both_purposes_and_plain_text(): void
    {
        foreach (['migration','recovery'] as $purpose) {
            $data=['purpose'=>$purpose,'code'=>'481926','minutes'=>10];
            $html=view('emails.security-code',$data)->render();
            $plain=view('emails.security-code-text',$data)->render();
            $this->assertStringContainsString('481926',$html);
            $this->assertStringContainsString('10 minutes',$plain);
            $this->assertStringContainsString($purpose === 'recovery' ? 'PASSWORD RECOVERY' : 'ACCOUNT UPGRADE',$html);
            $this->assertStringNotContainsString('<script',$html);
        }
    }

}
