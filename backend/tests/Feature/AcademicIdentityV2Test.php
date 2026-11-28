<?php

namespace Tests\Feature;

use App\Models\Guru;
use App\Models\Role;
use App\Models\Siswa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AcademicIdentityV2Test extends TestCase
{
    use RefreshDatabase;

    private function account(string $code, string $username): User
    {
        $role = Role::firstOrCreate(['code' => $code], ['name' => $code]);
        return User::create(['role_id' => $role->id, 'username' => $username, 'name' => $username, 'password' => 'ExamplePassword123!', 'account_status' => 'active']);
    }

    private function admin(): void
    {
        Sanctum::actingAs($this->account('TU', 'tu-phase2'));
        Role::firstOrCreate(['code' => 'GURU'], ['name' => 'Guru']);
        Role::firstOrCreate(['code' => 'SISWA'], ['name' => 'Siswa']);
    }

    public function test_manual_teacher_registration_creates_pending_account(): void
    {
        $this->admin();
        $response = $this->postJson('/api/teachers', ['registration_mode' => 'new_account', 'nip' => '000000000000000001', 'nama_lengkap' => 'Guru Baru']);
        $response->assertCreated()->assertJsonPath('data.user.username', '000000000000000001')->assertJsonPath('data.user.requires_activation', true);
        $this->assertDatabaseHas('users', ['id' => $response->json('data.user_id'), 'name' => 'Guru Baru', 'requires_activation' => true]);
    }

    public function test_manual_student_registration_and_nipd_search_preserve_zeroes(): void
    {
        $this->admin();
        $this->postJson('/api/students', ['registration_mode' => 'new_account', 'nisn' => '0000000001', 'nipd' => '00012', 'nama_lengkap' => 'Siswa Baru'])
            ->assertCreated()->assertJsonPath('data.nipd', '00012')->assertJsonPath('data.user.requires_activation', true);
        $this->getJson('/api/students?search=00012')->assertOk()->assertJsonPath('data.0.nisn', '0000000001');
    }

    public function test_username_conflict_does_not_create_profile_or_account(): void
    {
        $this->admin();
        $this->account('GURU', 'occupied');
        $count = User::count();
        $this->postJson('/api/teachers', ['registration_mode' => 'new_account', 'nip' => 'NIP-NEW', 'username' => 'occupied', 'nama_lengkap' => 'Guru Baru'])->assertUnprocessable();
        $this->assertDatabaseMissing('guru', ['nip' => 'NIP-NEW']);
        $this->assertSame($count, User::count());
    }

    public function test_rebinding_is_rejected_and_name_updates_keep_username(): void
    {
        $this->admin();
        $old = $this->account('GURU', 'old');
        $other = $this->account('GURU', 'other');
        $teacher = Guru::create(['user_id' => $old->id, 'nip' => 'NIP-ONE', 'nama_lengkap' => 'Before']);
        $data = ['nip' => 'NIP-ONE', 'nama_lengkap' => 'After'];
        $this->putJson("/api/teachers/{$teacher->id}", [...$data, 'user_id' => $other->id])->assertUnprocessable();
        $this->putJson("/api/teachers/{$teacher->id}", [...$data, 'user_id' => $old->id])->assertOk();
        $this->assertSame('After', $old->fresh()->name);
        $this->assertSame('old', $old->fresh()->username);
    }

    public function test_stale_master_edit_is_rejected(): void
    {
        $this->admin();
        $user = $this->account('SISWA', 'student-one');
        $student = Siswa::create(['user_id' => $user->id, 'nisn' => '0000000002', 'nama_lengkap' => 'Student']);
        $version = $this->getJson("/api/students/{$student->id}")->assertOk()->json('data.profile_version');
        $student->update(['alamat' => 'Alamat baru']);
        $this->putJson("/api/students/{$student->id}", ['user_id' => $user->id, 'nisn' => $student->nisn, 'nama_lengkap' => 'Student', 'alamat' => 'Alamat lama', 'profile_version' => $version])->assertConflict();
        $this->assertSame('Alamat baru', $student->fresh()->alamat);
    }

    public function test_sorting_applies_before_pagination(): void
    {
        $this->admin();
        foreach (['Zeta', 'Alpha', 'Beta'] as $name) {
            $user = $this->account('GURU', 'sort-'.$name);
            Guru::create(['user_id' => $user->id, 'nip' => 'NIP-'.$name, 'nama_lengkap' => $name]);
        }
        $this->getJson('/api/teachers?page_size=1&sort_order=asc')->assertOk()->assertJsonPath('data.0.nama_lengkap', 'Alpha');
        $this->getJson('/api/teachers?page_size=1&sort_order=desc&page=2')->assertOk()->assertJsonPath('data.0.nama_lengkap', 'Beta');
    }

    public function test_removing_empty_profile_retains_and_disables_account(): void
    {
        $this->admin();
        $user = $this->account('GURU', 'delete-me');
        $guru = Guru::create(['user_id' => $user->id, 'nip' => 'NIP-DELETE', 'nama_lengkap' => 'Guru']);
        $user->createToken('old-session');
        $this->deleteJson("/api/teachers/{$guru->id}")->assertOk();
        $this->assertDatabaseMissing('guru', ['id' => $guru->id]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'account_status' => 'inactive']);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_nonactivation_cancels_unused_activation_code(): void
    {
        $this->admin();
        $user = $this->account('SISWA', 'pending');
        $user->forceFill(['requires_activation' => true])->save();
        Siswa::create(['user_id' => $user->id, 'nisn' => '0000000009', 'nama_lengkap' => 'Pending']);
        $preview=$this->postJson('/api/users/bulk/preview',['action'=>'issue','user_ids'=>[$user->id]])->assertOk()->json('data');
        $this->postJson("/api/users/{$user->id}/activation-code",['expires'=>$preview['expires'],'preview_token'=>$preview['preview_token'],'preview_nonce'=>$preview['preview_nonce'],'confirm'=>true])->assertOk();
        $this->assertDatabaseHas('user_activation_tokens', ['user_id' => $user->id]);
        $this->postJson("/api/users/{$user->id}/deactivate")->assertOk();
        $this->assertDatabaseMissing('user_activation_tokens', ['user_id' => $user->id]);
        $this->assertTrue($user->fresh()->requires_activation);
    }
}
