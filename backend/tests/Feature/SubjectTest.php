<?php

namespace Tests\Feature;

use App\Models\Mapel;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubjectTest extends TestCase
{
    use RefreshDatabase;

    private function createRole(string $code): Role
    {
        $names = [
            'TU' => 'Tata Usaha',
            'KEPALA_SEKOLAH' => 'Kepala Sekolah',
            'GURU' => 'Guru',
            'SISWA' => 'Siswa',
        ];

        return Role::create([
            'code' => $code,
            'name' => $names[$code] ?? $code,
        ]);
    }

    private function createUser(string $roleCode): User
    {
        $role = Role::where('code', $roleCode)->first();

        if ($role === null) {
            $role = $this->createRole($roleCode);
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

    private function createSubject(array $overrides = []): Mapel
    {
        return Mapel::create(array_merge([
            'kode' => 'MAT-' . uniqid(),
            'nama' => 'Matematika',
            'kkm' => 75,
        ], $overrides));
    }

    public function test_guest_cannot_access_subject_list(): void
    {
        $response = $this->getJson('/api/subjects');

        $response
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_all_authenticated_roles_can_view_subject_list(): void
    {
        foreach (['TU', 'KEPALA_SEKOLAH', 'GURU', 'SISWA'] as $role) {
            $user = $this->createUser($role);

            Sanctum::actingAs($user);

            $this->getJson('/api/subjects')
                ->assertOk()
                ->assertJsonStructure([
                    'data',
                    'meta',
                ]);
        }
    }

    public function test_tu_can_create_subject(): void
    {
        $tu = $this->createUser('TU');

        Sanctum::actingAs($tu);

        $response = $this->postJson('/api/subjects', [
            'kode' => 'BIO-01',
            'nama' => 'Biologi',
            'kkm' => 78,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.kode', 'BIO-01')
            ->assertJsonPath('data.nama', 'Biologi')
            ->assertJsonPath('data.kkm', '78.00');

        $this->assertDatabaseHas('mapel', [
            'kode' => 'BIO-01',
            'nama' => 'Biologi',
        ]);
    }

    public function test_non_tu_cannot_create_subject(): void
    {
        foreach (['KEPALA_SEKOLAH', 'GURU', 'SISWA'] as $role) {
            $user = $this->createUser($role);

            Sanctum::actingAs($user);

            $this->postJson('/api/subjects', [
                'kode' => 'BIO-' . uniqid(),
                'nama' => 'Biologi',
                'kkm' => 78,
            ])
                ->assertStatus(403)
                ->assertJsonPath(
                    'message',
                    'Anda tidak memiliki akses ke resource ini.'
                );
        }
    }

    public function test_tu_can_view_subject_detail(): void
    {
        $tu = $this->createUser('TU');
        $subject = $this->createSubject();

        Sanctum::actingAs($tu);

        $this->getJson("/api/subjects/{$subject->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $subject->id)
            ->assertJsonPath('data.kode', $subject->kode)
            ->assertJsonPath('data.nama', $subject->nama);
    }

    public function test_non_tu_roles_can_view_subject_detail(): void
    {
        $subject = $this->createSubject();

        foreach (['KEPALA_SEKOLAH', 'GURU', 'SISWA'] as $role) {
            $user = $this->createUser($role);

            Sanctum::actingAs($user);

            $this->getJson("/api/subjects/{$subject->id}")
                ->assertOk()
                ->assertJsonPath('data.id', $subject->id);
        }
    }

    public function test_tu_can_update_subject(): void
    {
        $tu = $this->createUser('TU');
        $subject = $this->createSubject();

        Sanctum::actingAs($tu);

        $response = $this->putJson("/api/subjects/{$subject->id}", [
            'kode' => 'BIO-02',
            'nama' => 'Biologi Lanjutan',
            'kkm' => 80,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.kode', 'BIO-02')
            ->assertJsonPath('data.nama', 'Biologi Lanjutan')
            ->assertJsonPath('data.kkm', '80.00');

        $this->assertDatabaseHas('mapel', [
            'id' => $subject->id,
            'kode' => 'BIO-02',
            'nama' => 'Biologi Lanjutan',
        ]);
    }

    public function test_non_tu_cannot_update_subject(): void
    {
        $subject = $this->createSubject();

        foreach (['KEPALA_SEKOLAH', 'GURU', 'SISWA'] as $role) {
            $user = $this->createUser($role);

            Sanctum::actingAs($user);

            $this->putJson("/api/subjects/{$subject->id}", [
                'kode' => 'BIO-99',
                'nama' => 'Tidak Boleh',
                'kkm' => 90,
            ])
                ->assertStatus(403)
                ->assertJsonPath(
                    'message',
                    'Anda tidak memiliki akses ke resource ini.'
                );
        }
    }

    public function test_tu_can_delete_subject(): void
    {
        $tu = $this->createUser('TU');
        $subject = $this->createSubject();

        Sanctum::actingAs($tu);

        $this->deleteJson("/api/subjects/{$subject->id}")
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Data mata pelajaran berhasil dihapus.'
            );

        $this->assertDatabaseMissing('mapel', [
            'id' => $subject->id,
        ]);
    }

    public function test_non_tu_cannot_delete_subject(): void
    {
        foreach (['KEPALA_SEKOLAH', 'GURU', 'SISWA'] as $role) {
            $user = $this->createUser($role);
            $subject = $this->createSubject();

            Sanctum::actingAs($user);

            $this->deleteJson("/api/subjects/{$subject->id}")
                ->assertStatus(403)
                ->assertJsonPath(
                    'message',
                    'Anda tidak memiliki akses ke resource ini.'
                );

            $this->assertDatabaseHas('mapel', [
                'id' => $subject->id,
            ]);
        }
    }

    public function test_create_subject_requires_valid_fields(): void
    {
        $tu = $this->createUser('TU');

        Sanctum::actingAs($tu);

        $this->postJson('/api/subjects', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'kode',
                'nama',
            ]);
    }

    public function test_subject_code_must_be_unique(): void
    {
        $tu = $this->createUser('TU');

        $this->createSubject([
            'kode' => 'MAT-UNIQUE',
        ]);

        Sanctum::actingAs($tu);

        $this->postJson('/api/subjects', [
            'kode' => 'MAT-UNIQUE',
            'nama' => 'Matematika Baru',
            'kkm' => 75,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['kode']);
    }

    public function test_subject_update_allows_existing_code(): void
    {
        $tu = $this->createUser('TU');

        $subject = $this->createSubject([
            'kode' => 'MAT-SELF',
        ]);

        Sanctum::actingAs($tu);

        $this->putJson("/api/subjects/{$subject->id}", [
            'kode' => 'MAT-SELF',
            'nama' => 'Matematika Updated',
            'kkm' => 76,
        ])
            ->assertOk();
    }

    public function test_subject_update_rejects_code_used_by_another_subject(): void
    {
        $tu = $this->createUser('TU');

        $first = $this->createSubject([
            'kode' => 'MAT-FIRST',
        ]);

        $second = $this->createSubject([
            'kode' => 'MAT-SECOND',
        ]);

        Sanctum::actingAs($tu);

        $this->putJson("/api/subjects/{$second->id}", [
            'kode' => $first->kode,
            'nama' => 'Tidak Valid',
            'kkm' => 80,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['kode']);
    }

    public function test_subject_show_returns_404_when_not_found(): void
    {
        $tu = $this->createUser('TU');

        Sanctum::actingAs($tu);

        $this->getJson('/api/subjects/999999')
            ->assertNotFound();
    }

    public function test_subject_update_returns_404_when_not_found(): void
    {
        $tu = $this->createUser('TU');

        Sanctum::actingAs($tu);

        $this->putJson('/api/subjects/999999', [
            'kode' => 'MAT-404',
            'nama' => 'Tidak Ada',
            'kkm' => 75,
        ])
            ->assertNotFound();
    }

    public function test_subject_delete_returns_404_when_not_found(): void
    {
        $tu = $this->createUser('TU');

        Sanctum::actingAs($tu);

        $this->deleteJson('/api/subjects/999999')
            ->assertNotFound();
    }

    public function test_subject_list_supports_pagination(): void
    {
        $tu = $this->createUser('TU');

        $this->createSubject([
            'kode' => 'SUB-01',
        ]);

        $this->createSubject([
            'kode' => 'SUB-02',
        ]);

        $this->createSubject([
            'kode' => 'SUB-03',
        ]);

        Sanctum::actingAs($tu);

        $this->getJson('/api/subjects?per_page=2&page=1')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.total', 3);
    }
}