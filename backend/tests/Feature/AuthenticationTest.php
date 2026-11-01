<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;
use App\Models\Guru;
use App\Models\Kelas;
use App\Models\TahunAkademik;
use App\Models\WaliKelas;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_valid_credentials(): void
    {
        $role = Role::create([
            'code' => 'TU',
            'name' => 'Tata Usaha',
        ]);

        User::create([
            'role_id' => $role->id,
            'username' => 'tu.test',
            'name' => 'TU Test',
            'email' => 'tu.test@siakad.test',
            'password' => 'password-test',
            'account_status' => 'active',
        ]);

        $response = $this->postJson('/api/login', [
            'username' => 'tu.test',
            'password' => 'password-test',
        ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.username', 'tu.test')
            ->assertJsonPath('data.user.name', 'TU Test')
            ->assertJsonPath('data.user.account_status', 'active')
            ->assertJsonPath('data.user.role.code', 'TU')
            ->assertJsonPath('data.user.role.name', 'Tata Usaha');

        $this->assertDatabaseHas('users', [
            'username' => 'tu.test',
            'account_status' => 'active',
        ]);

        $this->assertDatabaseMissing('users', [
            'username' => 'tu.test',
            'last_login_at' => null,
        ]);
    }

    public function test_login_fails_with_invalid_password(): void
    {
        $role = Role::create([
            'code' => 'TU',
            'name' => 'Tata Usaha',
        ]);

        User::create([
            'role_id' => $role->id,
            'username' => 'tu.test',
            'name' => 'TU Test',
            'email' => 'tu.test@siakad.test',
            'password' => 'password-test',
            'account_status' => 'active',
        ]);

        $response = $this->postJson('/api/login', [
            'username' => 'tu.test',
            'password' => 'wrong-password',
        ]);

        $response
            ->assertStatus(401)
            ->assertJson([
                'data' => null,
                'message' => 'Username atau password tidak valid.',
            ]);

        $this->assertDatabaseHas('users', [
            'username' => 'tu.test',
            'last_login_at' => null,
        ]);
    }

    public function test_login_requires_username_and_password(): void
    {
        $response = $this->postJson('/api/login', []);

        $response
            ->assertStatus(422)
            ->assertJsonStructure([
                'message',
                'errors' => [
                    'username',
                    'password',
                ],
            ]);
    }

    public function test_inactive_account_cannot_login(): void
    {
        $role = Role::create([
            'code' => 'TU',
            'name' => 'Tata Usaha',
        ]);

        User::create([
            'role_id' => $role->id,
            'username' => 'inactive.test',
            'name' => 'Inactive Test',
            'email' => 'inactive.test@siakad.test',
            'password' => 'password-test',
            'account_status' => 'inactive',
        ]);

        $response = $this->postJson('/api/login', [
            'username' => 'inactive.test',
            'password' => 'password-test',
        ]);

        $response
            ->assertStatus(403)
            ->assertJson([
                'data' => null,
                'message' => 'Akun tidak aktif.',
            ]);

        $this->assertDatabaseHas('users', [
            'username' => 'inactive.test',
            'account_status' => 'inactive',
            'last_login_at' => null,
        ]);
    }

    public function test_authenticated_user_can_access_me_endpoint(): void
    {
        $role = Role::create([
            'code' => 'TU',
            'name' => 'Tata Usaha',
        ]);

        $user = User::create([
            'role_id' => $role->id,
            'username' => 'tu.me.test',
            'name' => 'TU Me Test',
            'email' => 'tu.me.test@siakad.test',
            'password' => 'password-test',
            'account_status' => 'active',
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/me');

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.username', 'tu.me.test')
            ->assertJsonPath('data.user.name', 'TU Me Test')
            ->assertJsonPath('data.user.account_status', 'active')
            ->assertJsonPath('data.user.role.code', 'TU')
            ->assertJsonPath('data.user.role.name', 'Tata Usaha');
    }

    public function test_unauthenticated_user_cannot_access_me_endpoint(): void
    {
        $response = $this->getJson('/api/me');

        $response
            ->assertStatus(401)
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_user_can_logout_and_token_is_revoked(): void
    {
        $role = Role::create([
            'code' => 'TU',
            'name' => 'Tata Usaha',
        ]);

        $user = User::create([
            'role_id' => $role->id,
            'username' => 'tu.logout.test',
            'name' => 'TU Logout Test',
            'email' => 'tu.logout.test@siakad.test',
            'password' => 'password-test',
            'account_status' => 'active',
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $meResponse = $this->withToken($token)
            ->getJson('/api/me');

        $meResponse
            ->assertStatus(200)
            ->assertJsonPath('data.user.username', 'tu.logout.test');

        $logoutResponse = $this->withToken($token)
            ->postJson('/api/logout');

        $logoutResponse
            ->assertStatus(200)
            ->assertJson([
                'data' => null,
                'message' => 'Logout berhasil.',
            ]);

        $this->assertDatabaseCount('personal_access_tokens', 0);

        Auth::forgetGuards();

        $revokedTokenResponse = $this->withToken($token)
            ->getJson('/api/me');

        $revokedTokenResponse
            ->assertStatus(401)
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);
    }


    private function createGuruForAssignmentTest(): array
    {
        $role = Role::create([
            'code' => 'GURU',
            'name' => 'Guru',
        ]);

        $user = User::create([
            'role_id' => $role->id,
            'username' => 'guru.assignment.test',
            'name' => 'Guru Assignment Test',
            'email' => 'guru.assignment.test@siakad.test',
            'password' => 'password-test',
            'account_status' => 'active',
        ]);

        $guru = Guru::create([
            'user_id' => $user->id,
            'nip' => 'NIP-ASSIGNMENT-TEST',
            'nama_lengkap' => 'Guru Assignment Test',
        ]);

        return [$user, $guru];
    }

    private function createActiveAcademicYearAndClass(): array
    {
        $academicYear = TahunAkademik::create([
            'tahun_mulai' => 2026,
            'tahun_selesai' => 2027,
            'semester' => 'GANJIL',
            'is_active' => true,
        ]);

        $class = Kelas::create([
            'tahun_akademik_id' => $academicYear->id,
            'nama' => 'X IPA 1',
            'tingkat' => 10,
        ]);

        return [$academicYear, $class];
    }

    public function test_login_and_me_return_wali_kelas_assignment_consistently(): void
    {
        [$user, $guru] = $this->createGuruForAssignmentTest();
        [$academicYear, $class] = $this->createActiveAcademicYearAndClass();

        WaliKelas::create([
            'guru_id' => $guru->id,
            'kelas_id' => $class->id,
            'tahun_akademik_id' => $academicYear->id,
            'tanggal_mulai' => now()->subDays(30)->toDateString(),
            'tanggal_selesai' => null,
        ]);

        $loginResponse = $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'password-test',
        ]);

        $loginResponse
            ->assertOk()
            ->assertJsonPath('data.assignments.0.type', 'WALI_KELAS');

        $token = $loginResponse->json('data.token');

        $meResponse = $this->withToken($token)->getJson('/api/me');

        $meResponse
            ->assertOk()
            ->assertJsonPath('data.assignments.0.type', 'WALI_KELAS')
            ->assertJsonPath(
                'data.assignments',
                $loginResponse->json('data.assignments')
            );
    }

    public function test_guru_without_wali_kelas_assignment_has_empty_assignments(): void
    {
        [$user] = $this->createGuruForAssignmentTest();

        $loginResponse = $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'password-test',
        ]);

        $loginResponse
            ->assertOk()
            ->assertJsonPath('data.assignments', []);

        $token = $loginResponse->json('data.token');

        $this->withToken($token)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.assignments', []);
    }

    public function test_ended_wali_kelas_assignment_is_not_returned(): void
    {
        [$user, $guru] = $this->createGuruForAssignmentTest();
        [$academicYear, $class] = $this->createActiveAcademicYearAndClass();

        WaliKelas::create([
            'guru_id' => $guru->id,
            'kelas_id' => $class->id,
            'tahun_akademik_id' => $academicYear->id,
            'tanggal_mulai' => now()->subDays(30)->toDateString(),
            'tanggal_selesai' => now()->subDay()->toDateString(),
        ]);

        $loginResponse = $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'password-test',
        ]);

        $loginResponse
            ->assertOk()
            ->assertJsonPath('data.assignments', []);

        $token = $loginResponse->json('data.token');

        $this->withToken($token)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.assignments', []);
    }

}