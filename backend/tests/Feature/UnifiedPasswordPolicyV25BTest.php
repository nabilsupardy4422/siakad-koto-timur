<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\Guru;
use App\Services\UserActivationService;
use App\Services\GooglePasswordRecoveryGrant;
use App\Support\SchoolPasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UnifiedPasswordPolicyV25BTest extends TestCase
{
    use RefreshDatabase;
    private const OLD = 'legacyweak';
    private const GOOD = 'ExamplePassword#2026';

    private function account(string $role, string $username, bool $pending = false): User
    {
        $r = Role::firstOrCreate(['code' => $role], ['name' => $role]);
        $u = User::create(['role_id' => $r->id, 'username' => $username, 'name' => $username, 'password' => self::OLD, 'account_status' => 'active']);
        $u->forceFill(['requires_activation' => $pending])->save();
        return $u;
    }
    private function body(string $password): array
    {
        return ['password' => $password, 'password_confirmation' => $password];
    }
    private function oversized(): string
    {
        return 'Ab1!' . str_repeat('é', 35); // 74 bytes.
    }
    public function test_unicode_and_bcrypt_boundaries(): void
    {
        foreach (['Abcdefghij1!', 'Ab1!'.str_repeat('é',34), 'ÉcoleSécurité١!'] as $valid) {
            $this->assertSame([], SchoolPasswordPolicy::violations($valid));
        }
        foreach (['Ab1!short', 'abcdefghij1!', 'ABCDEFGHIJ1!', 'Abcdefghijk!', 'Abcdefghij1 ', $this->oversized(), "Abcdefghij1!\0", "Abcdefghij1!\n", ['x'], "\xff"] as $invalid) {
            $this->assertNotEmpty(SchoolPasswordPolicy::violations($invalid));
        }
    }
    public function test_legacy_password_can_still_login(): void
    {
        $u = $this->account('TU', 'legacy');
        $this->postJson('/api/login', ['username' => $u->username, 'password' => self::OLD])->assertOk()->assertJsonPath('data.user.username', 'legacy');
        $this->assertTrue(Hash::check(self::OLD, $u->fresh()->password));
    }
    public function test_admin_creation_enforces_policy_and_optional_legacy_confirmation(): void
    {
        Sanctum::actingAs($this->account('TU', 'actor'));
        $role = Role::firstOrCreate(['code'=>'KEPALA_SEKOLAH'], ['name'=>'Kepala Sekolah']);
        $base = ['username'=>'principal-new','name'=>'Principal','role_id'=>$role->id];
        foreach (['weak', $this->oversized(), 'Abcdefghij1 '] as $weak) {
            $this->postJson('/api/users', [...$base, ...$this->body($weak)])->assertUnprocessable()->assertJsonValidationErrors('password');
        }
        $this->postJson('/api/users', [...$base, ...$this->body(self::GOOD), 'password_confirmation'=>'different'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertDatabaseMissing('users',['username'=>'principal-new']);
        $this->postJson('/api/users', [...$base, 'password'=>self::GOOD])->assertCreated()->assertJsonMissingPath('data.password');
        $this->assertTrue(Hash::check(self::GOOD, User::where('username','principal-new')->firstOrFail()->password));
        // A human-chosen string that happens to look like a bcrypt hash is not a pre-hashed credential.
        $hashLike = '$2y$04$CiWx.4l8KmCR0cyC2WonL.WxM7WMQPkPiAiybaG/u4xB.Bje8SY2y';
        $this->postJson('/api/users', [...$base, 'username'=>'hashlike', ...$this->body($hashLike)])->assertCreated();
        $this->assertTrue(Hash::check($hashLike, User::where('username','hashlike')->firstOrFail()->password));
    }
    public function test_admin_reset_rejects_weak_and_revokes_sessions_and_old_grant(): void
    {
        $actor = $this->account('TU','actor'); $u = $this->account('GURU','target');
        $u->forceFill(['google_id'=>'subject','remember_token'=>'old-remember'])->save();
        $grant = app(GooglePasswordRecoveryGrant::class)->issue($u->id, $u->google_id, $u->password)['reset_token'];
        $u->createToken('device'); Sanctum::actingAs($actor);
        $url = '/api/users/'.$u->id.'/reset-password';
        $this->postJson($url,$this->body($this->oversized()))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson($url,$this->body('weak'))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertTrue(Hash::check(self::OLD,$u->fresh()->password)); $this->assertSame(1,$u->tokens()->count());
        $this->postJson($url,$this->body(self::GOOD))->assertOk();
        $this->assertTrue(Hash::check(self::GOOD,$u->fresh()->password)); $this->assertSame(0,$u->tokens()->count()); $this->assertNull($u->fresh()->remember_token);
        $this->postJson('/api/recovery/google/password',['reset_token'=>$grant,...$this->body('OtherPassword#2026')])->assertForbidden();
    }
    public function test_invalid_activation_password_does_not_consume_code(): void
    {
        $actor = $this->account('TU','actor'); $u=$this->account('GURU','pending',true);
        Guru::create(['user_id'=>$u->id,'nip'=>'000100','nama_lengkap'=>'Pending']);
        $code = DB::transaction(fn()=>app(UserActivationService::class)->issue($u,$actor->id)['activation_code']);
        $base=['username'=>$u->username,'activation_code'=>$code];
        foreach (['weak',$this->oversized()] as $weak) {
            $this->postJson('/api/account/activate',[...$base,...$this->body($weak)])->assertUnprocessable()->assertJsonValidationErrors('password');
        }
        $this->assertTrue($u->fresh()->requires_activation);
        $this->assertDatabaseHas('user_activation_tokens',['user_id'=>$u->id,'used_at'=>null,'failed_attempts'=>0]);
        $this->postJson('/api/account/activate',[...$base,...$this->body(self::GOOD)])->assertOk();
        $this->assertFalse($u->fresh()->requires_activation); $this->assertTrue(Hash::check(self::GOOD,$u->fresh()->password));
    }
    public function test_weak_google_password_keeps_grant_for_valid_retry(): void
    {
        $u=$this->account('GURU','recovery');$u->forceFill(['google_id'=>'subject'])->save();
        $grant=app(GooglePasswordRecoveryGrant::class)->issue($u->id,$u->google_id,$u->password)['reset_token'];
        $u->createToken('device');
        $this->postJson('/api/recovery/google/password',['reset_token'=>$grant,...$this->body($this->oversized())])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertTrue(Hash::check(self::OLD,$u->fresh()->password)); $this->assertSame(1,$u->tokens()->count());
        $this->postJson('/api/recovery/google/password',['reset_token'=>$grant,...$this->body(self::GOOD)])->assertOk();
        $this->assertTrue(Hash::check(self::GOOD,$u->fresh()->password));$this->assertSame(0,$u->tokens()->count());
    }
    public function test_self_change_accepts_legacy_current_but_rejects_oversized_new(): void
    {
        $u=$this->account('TU','self');Sanctum::actingAs($u);$u->withAccessToken($u->createToken('current')->accessToken);
        $url='/api/profile/password';
        $this->putJson($url,['current_password'=>self::OLD,...$this->body($this->oversized())])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertSame(1,$u->tokens()->count());
        $this->putJson($url,['current_password'=>self::OLD,...$this->body(self::GOOD)])->assertOk();
        $this->assertTrue(Hash::check(self::GOOD,$u->fresh()->password));$this->assertSame(0,$u->tokens()->count());
    }
}
