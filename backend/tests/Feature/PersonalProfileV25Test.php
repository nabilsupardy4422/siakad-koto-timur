<?php

namespace Tests\Feature;

use App\Models\Guru;
use App\Models\Siswa;
use App\Models\User;
use App\Models\Role;
use App\Models\TahunAkademik;
use App\Models\Kelas;
use App\Models\AnggotaKelas;
use App\Services\GooglePasswordRecoveryGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PersonalProfileV25Test extends TestCase
{
    use RefreshDatabase;
    private const OLD = 'ExistingPassword#2026';
    private const NEW = 'ChangedPassword#2026';

    private function account(string $role, string $name, bool $linked = true): User
    {
        $r = Role::firstOrCreate(['code' => $role], ['name' => $role]);
        $u = User::create(['role_id' => $r->id, 'username' => $name, 'name' => $name, 'password' => self::OLD, 'account_status' => 'active']);
        $u->forceFill(['requires_activation' => false])->save();
        if ($linked && $role === 'GURU') Guru::create(['user_id' => $u->id, 'nip' => '00'.$name, 'nama_lengkap' => 'Resmi '.$name]);
        if ($linked && $role === 'SISWA') Siswa::create(['user_id' => $u->id, 'nisn' => '00'.$name, 'nipd' => '001'.$name, 'nama_lengkap' => 'Resmi '.$name]);
        return $u;
    }
    private function authenticate(User $u): void
    {
        Sanctum::actingAs($u);
        $u->withAccessToken($u->createToken('profile-test')->accessToken);
    }
    private function passwordBody(string $new = self::NEW, string $old = self::OLD): array
    {
        return ['current_password' => $old, 'password' => $new, 'password_confirmation' => $new];
    }
    public function test_guest_cannot_read_or_write_profile(): void
    {
        $this->getJson('/api/profile')->assertUnauthorized();
        $this->putJson('/api/profile', [])->assertUnauthorized();
        $this->putJson('/api/profile/password', [])->assertUnauthorized();
    }
    public function test_teacher_reads_only_official_own_identity_and_no_secret(): void
    {
        $u = $this->account('GURU', 'guru'); $other = $this->account('GURU', 'lain');
        $u->forceFill(['google_id' => 'private-google-subject'])->save(); $this->authenticate($u);
        $r = $this->getJson('/api/profile')->assertOk()->assertJsonPath('data.user.name', 'Resmi guru')
            ->assertJsonPath('data.biodata.nip', '00guru')->assertJsonPath('data.completeness.filled', 2)
            ->assertJsonPath('data.completeness.total', 5)->assertJsonPath('data.can_edit_contact', true);
        $r->assertDontSee('private-google-subject')->assertDontSee($u->password)->assertDontSee('00lain');
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $this->getJson('/api/profile?user_id='.$other->id)->assertUnprocessable();
    }
    public function test_student_context_uses_effective_membership_only(): void
    {
        $u = $this->account('SISWA', 'student'); $this->authenticate($u);
        $y = TahunAkademik::create(['tahun_mulai' => 2026, 'tahun_selesai' => 2027, 'semester' => 'GANJIL', 'is_active' => true]);
        $c = Kelas::create(['tahun_akademik_id' => $y->id, 'nama' => 'X A', 'tingkat' => 10]);
        $row = AnggotaKelas::create(['kelas_id' => $c->id, 'siswa_id' => $u->siswa->id, 'tanggal_mulai' => now('Asia/Jakarta')->addDay()->toDateString()]);
        $this->getJson('/api/profile')->assertOk()->assertJsonPath('data.biodata.nipd', '001student')
            ->assertJsonPath('data.completeness.total', 8)->assertJsonPath('data.academic.placement.status', 'future')->assertJsonPath('data.academic.placement.class_name', null);
        $row->update(['tanggal_mulai' => now('Asia/Jakarta')->subDay()->toDateString()]);
        $this->getJson('/api/profile')->assertOk()->assertJsonPath('data.academic.placement.class_name', 'X A');
        $y->update(['is_active' => false]);
        $this->getJson('/api/profile')->assertOk()->assertJsonPath('data.academic.period_status', 'unavailable')->assertJsonPath('data.academic.placement', null);
    }
    public function test_contact_changes_are_partial_versioned_and_shared_with_master(): void
    {
        $u = $this->account('GURU', 'guru'); $this->authenticate($u);
        $u->guru->update(['alamat' => 'Alamat lama']);
        $version = $this->getJson('/api/profile')->assertOk()->json('data.profile_version');
        $body = ['profile_version' => $version, 'no_telepon' => '001234'];
        $this->putJson('/api/profile', [...$body, 'nip' => 'forbidden'])->assertUnprocessable();
        $this->putJson('/api/profile', [...$body, 'user_id' => 999])->assertUnprocessable();
        $r = $this->putJson('/api/profile', $body)->assertOk()->assertJsonPath('data.biodata.no_telepon', '001234')->assertJsonPath('data.biodata.alamat', 'Alamat lama');
        $this->assertDatabaseHas('guru', ['user_id' => $u->id, 'no_telepon' => '001234', 'alamat' => 'Alamat lama']);
        $this->putJson('/api/profile', ['profile_version' => $version, 'alamat' => 'stale'])->assertConflict();
        $this->putJson('/api/profile', ['profile_version' => $r->json('data.profile_version'), 'alamat' => null])->assertOk()->assertJsonPath('data.biodata.alamat', null);
    }
    public function test_administrative_roles_have_no_invented_biodata_and_no_contact_write(): void
    {
        foreach (['TU', 'KEPALA_SEKOLAH'] as $role) {
            $u = $this->account($role, $role); $this->authenticate($u);
            $this->getJson('/api/profile')->assertOk()->assertJsonPath('data.profile_status', 'not_applicable')->assertJsonPath('data.biodata', null)->assertJsonPath('data.completeness', null);
            $this->putJson('/api/profile', ['profile_version' => str_repeat('a',64), 'alamat' => 'x'])->assertForbidden();
        }
    }
    public function test_unlinked_and_conflicting_identity_cannot_edit_contact(): void
    {
        $u = $this->account('SISWA', 'orphan', false); $this->authenticate($u);
        $this->getJson('/api/profile')->assertOk()->assertJsonPath('data.profile_status', 'unlinked')->assertJsonPath('data.biodata', null);
        $this->putJson('/api/profile', ['profile_version' => str_repeat('a',64), 'alamat' => 'x'])->assertConflict();
        Guru::create(['user_id' => $u->id, 'nip' => 'wrong-role', 'nama_lengkap' => 'Private']);
        $this->getJson('/api/profile')->assertOk()->assertJsonPath('data.profile_status', 'conflict')->assertJsonPath('data.biodata', null)->assertDontSee('wrong-role');
    }
    public function test_pending_or_inactive_account_cannot_use_profile(): void
    {
        $u = $this->account('SISWA', 'pending'); $u->forceFill(['requires_activation' => true])->save(); $this->authenticate($u);
        $this->getJson('/api/profile')->assertForbidden();
        $this->putJson('/api/profile/password', $this->passwordBody())->assertForbidden();
        $u->forceFill(['requires_activation' => false, 'account_status' => 'inactive'])->save();
        $this->getJson('/api/profile')->assertForbidden();
    }
    public function test_password_requires_current_and_strong_distinct_password_then_revokes_tokens(): void
    {
        $u = $this->account('TU', 'tu'); $this->authenticate($u);
        $u->createToken('phone');
        $this->putJson('/api/profile/password', $this->passwordBody(self::NEW, 'wrong'))->assertUnprocessable();
        $this->putJson('/api/profile/password', $this->passwordBody('weak'))->assertUnprocessable();
        $this->putJson('/api/profile/password', $this->passwordBody(self::OLD))->assertUnprocessable();
        $this->assertSame(2,$u->tokens()->count());
        $this->putJson('/api/profile/password', $this->passwordBody())->assertOk();
        $this->assertTrue(Hash::check(self::NEW, $u->fresh()->password));
        $this->assertFalse(Hash::check(self::OLD, $u->fresh()->password));
        $this->assertSame(0,$u->tokens()->count());
    }
    public function test_old_google_recovery_grant_cannot_overwrite_changed_password(): void
    {
        $u = $this->account('GURU', 'guru'); $u->forceFill(['google_id' => 'verified-subject'])->save();
        $token = app(GooglePasswordRecoveryGrant::class)->issue($u->id, $u->google_id, $u->password)['reset_token'];
        $this->authenticate($u);
        $this->putJson('/api/profile/password', $this->passwordBody())->assertOk();
        $this->postJson('/api/recovery/google/password', ['reset_token' => $token, 'password' => 'AnotherPassword#2026', 'password_confirmation' => 'AnotherPassword#2026'])->assertForbidden();
        $this->assertTrue(Hash::check(self::NEW, $u->fresh()->password));
    }
}
