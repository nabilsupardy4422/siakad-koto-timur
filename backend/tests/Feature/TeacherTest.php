<?php

namespace Tests\Feature;

use App\Models\Guru;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherTest extends TestCase
{
    use RefreshDatabase;

    private function createRole(string $code, string $name): Role
    {
        return Role::create([
            'code' => $code,
            'name' => $name,
        ]);
    }

    private function createUser(string $roleCode): User
    {
        $role = Role::where('code', $roleCode)->first();

        if ($role === null) {
            $role = $this->createRole(
                $roleCode,
                match ($roleCode) {
                    'TU' => 'Tata Usaha',
                    'KEPALA_SEKOLAH' => 'Kepala Sekolah',
                    'GURU' => 'Guru',
                    'SISWA' => 'Siswa',
                    default => $roleCode,
                }
            );
        }

        return User::create([
            'role_id' => $role->id,
            'username' => strtolower($roleCode) . '_' . uniqid(),
            'name' => 'User ' . $roleCode,
            'email' => strtolower($roleCode) . '_' . uniqid() . '@example.test',
            'password' => Hash::make('password'),
            'account_status' => 'active',
        ]);
    }

    private function createTeacherUser(): User
    {
        return $this->createUser('GURU');
    }

    private function createTeacher(array $overrides = []): Guru
    {
        $user = $this->createTeacherUser();

        return Guru::create(array_merge([
            'user_id' => $user->id,
            'nip' => 'NIP-' . uniqid(),
            'nama_lengkap' => 'Guru Test',
            'jenis_kelamin' => 'L',
            'no_telepon' => '08123456789',
            'alamat' => 'Alamat Test',
        ], $overrides));
    }

    public function test_guest_cannot_access_teacher_list(): void
    {
        $response = $this->getJson('/api/teachers');

        $response
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_all_authenticated_roles_can_view_teacher_list(): void
    {
        foreach (['TU', 'KEPALA_SEKOLAH', 'GURU', 'SISWA'] as $role) {
            $user = $this->createUser($role);

            Sanctum::actingAs($user);

            $this->getJson('/api/teachers')
                ->assertOk()
                ->assertJsonStructure([
                    'data',
                    'meta',
                ]);
        }
    }

    public function test_tu_can_create_teacher(): void
    {
        $tu = $this->createUser('TU');
        $teacherUser = $this->createTeacherUser();

        Sanctum::actingAs($tu);

        $response = $this->postJson('/api/teachers', [
            'user_id' => $teacherUser->id,
            'nip' => '198501012026010001',
            'nama_lengkap' => 'Guru Baru',
            'jenis_kelamin' => 'P',
            'no_telepon' => '081234567890',
            'alamat' => 'Padang',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.user_id', $teacherUser->id)
            ->assertJsonPath('data.nip', '198501012026010001')
            ->assertJsonPath('data.nama_lengkap', 'Guru Baru');

        $this->assertDatabaseHas('guru', [
            'user_id' => $teacherUser->id,
            'nip' => '198501012026010001',
        ]);
    }

    public function test_non_tu_cannot_create_teacher(): void
    {
        $guru = $this->createUser('GURU');
        $teacherUser = $this->createTeacherUser();

        Sanctum::actingAs($guru);

        $this->postJson('/api/teachers', [
            'user_id' => $teacherUser->id,
            'nip' => '198501012026010001',
            'nama_lengkap' => 'Guru Baru',
        ])
            ->assertStatus(403)
            ->assertJsonPath(
                'message',
                'Anda tidak memiliki akses ke resource ini.'
            );
    }

    public function test_tu_can_view_teacher_detail(): void
    {
        $tu = $this->createUser('TU');
        $teacher = $this->createTeacher();

        Sanctum::actingAs($tu);

        $this->getJson("/api/teachers/{$teacher->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $teacher->id)
            ->assertJsonPath('data.user_id', $teacher->user_id)
            ->assertJsonPath('data.nip', $teacher->nip);
    }

    public function test_tu_can_update_teacher(): void
    {
        $tu = $this->createUser('TU');
        $teacher = $this->createTeacher();

        Sanctum::actingAs($tu);

        $response = $this->putJson("/api/teachers/{$teacher->id}", [
            'user_id' => $teacher->user_id,
            'nip' => 'NIP-UPDATED',
            'nama_lengkap' => 'Guru Updated',
            'jenis_kelamin' => 'P',
            'no_telepon' => '08999999999',
            'alamat' => 'Alamat Updated',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.nip', 'NIP-UPDATED')
            ->assertJsonPath('data.nama_lengkap', 'Guru Updated');

        $this->assertDatabaseHas('guru', [
            'id' => $teacher->id,
            'nip' => 'NIP-UPDATED',
            'nama_lengkap' => 'Guru Updated',
        ]);
    }

    public function test_non_tu_cannot_update_teacher(): void
    {
        $guru = $this->createUser('GURU');
        $teacher = $this->createTeacher();

        Sanctum::actingAs($guru);

        $this->putJson("/api/teachers/{$teacher->id}", [
            'user_id' => $teacher->user_id,
            'nip' => 'NIP-UPDATED',
            'nama_lengkap' => 'Guru Updated',
        ])
            ->assertStatus(403)
            ->assertJsonPath(
                'message',
                'Anda tidak memiliki akses ke resource ini.'
            );
    }

    public function test_tu_can_delete_teacher(): void
    {
        $tu = $this->createUser('TU');
        $teacher = $this->createTeacher();

        Sanctum::actingAs($tu);

        $this->deleteJson("/api/teachers/{$teacher->id}")
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Data guru berhasil dihapus.'
            );

        $this->assertDatabaseMissing('guru', [
            'id' => $teacher->id,
        ]);
    }

    public function test_non_tu_cannot_delete_teacher(): void
    {
        $guru = $this->createUser('GURU');
        $teacher = $this->createTeacher();

        Sanctum::actingAs($guru);

        $this->deleteJson("/api/teachers/{$teacher->id}")
            ->assertStatus(403)
            ->assertJsonPath(
                'message',
                'Anda tidak memiliki akses ke resource ini.'
            );
    }

    public function test_create_teacher_requires_valid_fields(): void
    {
        $tu = $this->createUser('TU');

        Sanctum::actingAs($tu);

        $this->postJson('/api/teachers', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'user_id',
                'nip',
                'nama_lengkap',
            ]);
    }

    public function test_create_teacher_rejects_non_guru_user(): void
    {
        $tu = $this->createUser('TU');
        $siswa = $this->createUser('SISWA');

        Sanctum::actingAs($tu);

        $this->postJson('/api/teachers', [
            'user_id' => $siswa->id,
            'nip' => 'NIP-INVALID',
            'nama_lengkap' => 'Invalid Guru',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'user_id',
            ]);
    }

    public function test_create_teacher_rejects_duplicate_user(): void
    {
        $tu = $this->createUser('TU');
        $teacher = $this->createTeacher();

        Sanctum::actingAs($tu);

        $this->postJson('/api/teachers', [
            'user_id' => $teacher->user_id,
            'nip' => 'NIP-SECOND',
            'nama_lengkap' => 'Guru Kedua',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'user_id',
            ]);
    }

    public function test_create_teacher_rejects_duplicate_nip(): void
    {
        $tu = $this->createUser('TU');
        $teacher = $this->createTeacher();

        Sanctum::actingAs($tu);

        $this->postJson('/api/teachers', [
            'user_id' => $this->createTeacherUser()->id,
            'nip' => $teacher->nip,
            'nama_lengkap' => 'Guru Duplicate NIP',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'nip',
            ]);
    }

    public function test_teacher_list_supports_search(): void
    {
        $tu = $this->createUser('TU');

        $teacher = $this->createTeacher([
            'nama_lengkap' => 'Ahmad Fauzan',
            'nip' => 'NIP-AHMAD',
        ]);

        $this->createTeacher([
            'nama_lengkap' => 'Budi Santoso',
            'nip' => 'NIP-BUDI',
        ]);

        Sanctum::actingAs($tu);

        $response = $this->getJson('/api/teachers?search=Ahmad');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $teacher->id);
    }

    public function test_teacher_list_supports_account_status_filter(): void
    {
        $tu = $this->createUser('TU');

        $activeTeacher = $this->createTeacher();

        $inactiveUser = $this->createTeacherUser();
        $inactiveUser->update([
            'account_status' => 'inactive',
        ]);

        $inactiveTeacher = Guru::create([
            'user_id' => $inactiveUser->id,
            'nip' => 'NIP-INACTIVE',
            'nama_lengkap' => 'Guru Inactive',
        ]);

        Sanctum::actingAs($tu);

        $response = $this->getJson('/api/teachers?status=inactive');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inactiveTeacher->id);

        $this->assertNotEquals(
            $activeTeacher->id,
            $response->json('data.0.id')
        );
    }

    public function test_teacher_show_returns_404_when_not_found(): void
    {
        $tu = $this->createUser('TU');

        Sanctum::actingAs($tu);

        $this->getJson('/api/teachers/999999')
            ->assertNotFound();
    }

    public function test_teacher_update_returns_404_when_not_found(): void
    {
        $tu = $this->createUser('TU');

        Sanctum::actingAs($tu);

        $this->putJson('/api/teachers/999999', [
            'user_id' => $this->createTeacherUser()->id,
            'nip' => 'NIP-NOTFOUND',
            'nama_lengkap' => 'Guru Not Found',
        ])
            ->assertNotFound();
    }
}