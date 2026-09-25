<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\TahunAkademik;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademicYearTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithRole(string $roleCode): User
    {
        $roleNames = [
            'TU' => 'Tata Usaha',
            'KEPALA_SEKOLAH' => 'Kepala Sekolah',
            'GURU' => 'Guru',
            'SISWA' => 'Siswa',
        ];

        $role = Role::create([
            'code' => $roleCode,
            'name' => $roleNames[$roleCode],
        ]);

        return User::create([
            'role_id' => $role->id,
            'username' => strtolower($roleCode) . '.test',
            'name' => $roleNames[$roleCode] . ' Test',
            'email' => strtolower($roleCode) . '.test@siakad.test',
            'password' => 'password-test',
            'account_status' => 'active',
        ]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test-token')->plainTextToken;
    }

    private function validPayload(): array
    {
        return [
            'tahun_mulai' => 2026,
            'tahun_selesai' => 2027,
            'semester' => 'GANJIL',
            'is_active' => true,
        ];
    }

    public function test_tu_can_list_academic_years(): void
    {
        $user = $this->createUserWithRole('TU');

        TahunAkademik::create($this->validPayload());

        $response = $this
            ->withToken($this->tokenFor($user))
            ->getJson('/api/academic-years');

        $response
            ->assertOk()
            ->assertJsonPath('data.0.tahun_mulai', 2026)
            ->assertJsonPath('data.0.tahun_selesai', 2027)
            ->assertJsonPath('data.0.semester', 'GANJIL')
            ->assertJsonPath('data.0.is_active', true);
    }

    public function test_kepala_sekolah_can_list_academic_years(): void
    {
        $user = $this->createUserWithRole('KEPALA_SEKOLAH');

        $response = $this
            ->withToken($this->tokenFor($user))
            ->getJson('/api/academic-years');

        $response->assertOk();
    }

    public function test_guru_can_list_academic_years(): void
    {
        $user = $this->createUserWithRole('GURU');

        $response = $this
            ->withToken($this->tokenFor($user))
            ->getJson('/api/academic-years');

        $response->assertOk();
    }

    public function test_siswa_can_list_academic_years(): void
    {
        $user = $this->createUserWithRole('SISWA');

        $response = $this
            ->withToken($this->tokenFor($user))
            ->getJson('/api/academic-years');

        $response->assertOk();
    }

    public function test_tu_can_show_academic_year(): void
    {
        $user = $this->createUserWithRole('TU');

        $academicYear = TahunAkademik::create($this->validPayload());

        $response = $this
            ->withToken($this->tokenFor($user))
            ->getJson("/api/academic-years/{$academicYear->id}");

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $academicYear->id)
            ->assertJsonPath('data.semester', 'GANJIL');
    }

    public function test_guru_can_show_academic_year(): void
    {
        $user = $this->createUserWithRole('GURU');

        $academicYear = TahunAkademik::create($this->validPayload());

        $response = $this
            ->withToken($this->tokenFor($user))
            ->getJson("/api/academic-years/{$academicYear->id}");

        $response->assertOk();
    }

    public function test_tu_can_create_academic_year(): void
    {
        $user = $this->createUserWithRole('TU');

        $response = $this
            ->withToken($this->tokenFor($user))
            ->postJson('/api/academic-years', $this->validPayload());

        $response
            ->assertCreated()
            ->assertJsonPath('data.tahun_mulai', 2026)
            ->assertJsonPath('data.tahun_selesai', 2027)
            ->assertJsonPath('data.semester', 'GANJIL')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('tahun_akademik', [
            'tahun_mulai' => 2026,
            'tahun_selesai' => 2027,
            'semester' => 'GANJIL',
            'is_active' => true,
        ]);
    }

    public function test_non_tu_cannot_create_academic_year(): void
    {
        $user = $this->createUserWithRole('GURU');

        $response = $this
            ->withToken($this->tokenFor($user))
            ->postJson('/api/academic-years', $this->validPayload());

        $response
            ->assertForbidden()
            ->assertJson([
                'message' => 'Anda tidak memiliki akses ke resource ini.',
            ]);
    }

    public function test_tu_can_update_academic_year(): void
    {
        $user = $this->createUserWithRole('TU');

        $academicYear = TahunAkademik::create($this->validPayload());

        $payload = [
            'tahun_mulai' => 2027,
            'tahun_selesai' => 2028,
            'semester' => 'GENAP',
            'is_active' => false,
        ];

        $response = $this
            ->withToken($this->tokenFor($user))
            ->putJson("/api/academic-years/{$academicYear->id}", $payload);

        $response
            ->assertOk()
            ->assertJsonPath('data.tahun_mulai', 2027)
            ->assertJsonPath('data.tahun_selesai', 2028)
            ->assertJsonPath('data.semester', 'GENAP')
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('tahun_akademik', [
            'id' => $academicYear->id,
            'tahun_mulai' => 2027,
            'tahun_selesai' => 2028,
            'semester' => 'GENAP',
            'is_active' => false,
        ]);
    }

    public function test_non_tu_cannot_update_academic_year(): void
    {
        $user = $this->createUserWithRole('GURU');

        $academicYear = TahunAkademik::create($this->validPayload());

        $response = $this
            ->withToken($this->tokenFor($user))
            ->putJson(
                "/api/academic-years/{$academicYear->id}",
                $this->validPayload()
            );

        $response
            ->assertForbidden()
            ->assertJson([
                'message' => 'Anda tidak memiliki akses ke resource ini.',
            ]);
    }

    public function test_tu_can_delete_academic_year(): void
    {
        $user = $this->createUserWithRole('TU');

        $academicYear = TahunAkademik::create($this->validPayload());

        $response = $this
            ->withToken($this->tokenFor($user))
            ->deleteJson("/api/academic-years/{$academicYear->id}");

        $response
            ->assertOk()
            ->assertJson([
                'message' => 'Tahun akademik berhasil dihapus.',
            ]);

        $this->assertDatabaseMissing('tahun_akademik', [
            'id' => $academicYear->id,
        ]);
    }

    public function test_non_tu_cannot_delete_academic_year(): void
    {
        $user = $this->createUserWithRole('GURU');

        $academicYear = TahunAkademik::create($this->validPayload());

        $response = $this
            ->withToken($this->tokenFor($user))
            ->deleteJson("/api/academic-years/{$academicYear->id}");

        $response
            ->assertForbidden()
            ->assertJson([
                'message' => 'Anda tidak memiliki akses ke resource ini.',
            ]);
    }

    public function test_unauthenticated_user_cannot_access_academic_years(): void
    {
        $response = $this->getJson('/api/academic-years');

        $response
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_academic_year_validation_failure_returns_422(): void
    {
        $user = $this->createUserWithRole('TU');

        $response = $this
            ->withToken($this->tokenFor($user))
            ->postJson('/api/academic-years', [
                'tahun_mulai' => 'abc',
                'tahun_selesai' => null,
                'semester' => 'SALAH',
                'is_active' => 'invalid',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonStructure([
                'message',
                'errors' => [
                    'tahun_mulai',
                    'tahun_selesai',
                    'semester',
                    'is_active',
                ],
            ]);
    }
}