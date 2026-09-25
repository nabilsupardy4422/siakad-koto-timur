<?php

namespace Tests\Feature;

use App\Models\AnggotaKelas;
use App\Models\Guru;
use App\Models\JadwalPelajaran;
use App\Models\Kelas;
use App\Models\TahunAkademik;
use App\Models\User;
use App\Models\WaliKelas;
use App\Models\Siswa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClassTest extends TestCase
{
    use RefreshDatabase;

    private function createRole(string $code): int
    {
        return \App\Models\Role::firstOrCreate(
            ['code' => $code],
            ['name' => $code]
        )->id;
    }

    private function createUser(string $roleCode): User
    {
        $roleId = $this->createRole($roleCode);

        return User::create([
            'role_id' => $roleId,
            'username' => strtolower($roleCode) . '-' . uniqid(),
            'name' => 'User ' . $roleCode,
            'email' => strtolower($roleCode) . '-' . uniqid() . '@example.com',
            'password' => Hash::make('password'),
            'account_status' => 'active',
        ]);
    }

    private function createAcademicYear(
        bool $active = true
    ): TahunAkademik {
        return TahunAkademik::create([
            'tahun_mulai' => 2026,
            'tahun_selesai' => 2027,
            'semester' => 'GANJIL',
            'is_active' => $active,
        ]);
    }

    private function createClass(
        TahunAkademik $academicYear,
        string $name = 'X IPA 1',
        int $grade = 10
    ): Kelas {
        return Kelas::create([
            'tahun_akademik_id' => $academicYear->id,
            'nama' => $name,
            'tingkat' => $grade,
        ]);
    }

    private function createTeacher(): array
    {
        $user = $this->createUser('GURU');

        $guru = Guru::create([
            'user_id' => $user->id,
            'nip' => 'NIP-' . uniqid(),
            'nama_lengkap' => 'Guru Test',
        ]);

        return [$user, $guru];
    }

    private function createStudent(): array
    {
        $user = $this->createUser('SISWA');

        $student = Siswa::create([
            'user_id' => $user->id,
            'nisn' => 'NISN-' . uniqid(),
            'nis' => 'NIS-' . uniqid(),
            'nama_lengkap' => 'Siswa Test',
        ]);

        return [$user, $student];
    }

    public function test_guest_cannot_access_class_list(): void
    {
        $this->getJson('/api/classes')
            ->assertStatus(401);
    }

    public function test_all_authenticated_roles_can_view_class_list(): void
    {
        foreach (['TU', 'KEPALA_SEKOLAH', 'GURU', 'SISWA'] as $role) {
            $user = $this->createUser($role);

            Sanctum::actingAs($user);

            $this->getJson('/api/classes')
                ->assertOk()
                ->assertJsonStructure([
                    'data',
                    'meta',
                ]);
        }
    }

    public function test_tu_can_create_class(): void
    {
        $user = $this->createUser('TU');
        $academicYear = $this->createAcademicYear();

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/classes', [
            'tahun_akademik_id' => $academicYear->id,
            'nama' => 'X IPA 1',
            'tingkat' => 10,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.nama', 'X IPA 1')
            ->assertJsonPath('data.tingkat', 10);

        $this->assertDatabaseHas('kelas', [
            'tahun_akademik_id' => $academicYear->id,
            'nama' => 'X IPA 1',
            'tingkat' => 10,
        ]);
    }

    public function test_non_tu_cannot_create_class(): void
    {
        $academicYear = $this->createAcademicYear();

        foreach (['KEPALA_SEKOLAH', 'GURU', 'SISWA'] as $role) {
            $user = $this->createUser($role);

            Sanctum::actingAs($user);

            $this->postJson('/api/classes', [
                'tahun_akademik_id' => $academicYear->id,
                'nama' => 'X IPA 1',
                'tingkat' => 10,
            ])->assertForbidden();
        }
    }

    public function test_tu_can_view_class_detail(): void
    {
        $user = $this->createUser('TU');
        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        Sanctum::actingAs($user);

        $this->getJson("/api/classes/{$class->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $class->id);
    }

    public function test_kepala_sekolah_can_view_class(): void
    {
        $user = $this->createUser('KEPALA_SEKOLAH');
        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        Sanctum::actingAs($user);

        $this->getJson("/api/classes/{$class->id}")
            ->assertOk();
    }

    public function test_guru_can_view_class_in_teaching_scope(): void
    {
        [$user, $guru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        JadwalPelajaran::create([
            'tahun_akademik_id' => $academicYear->id,
            'kelas_id' => $class->id,
            'mapel_id' => $this->createSubject()->id,
            'guru_id' => $guru->id,
            'hari' => 'SENIN',
            'jam_mulai' => '07:00',
            'jam_selesai' => '08:00',
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/classes/{$class->id}")
            ->assertOk();
    }

    public function test_guru_cannot_view_class_outside_teaching_scope(): void
    {
        [$user, $guru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $otherClass = $this->createClass(
            $academicYear,
            'X IPA 2',
            10
        );

        JadwalPelajaran::create([
            'tahun_akademik_id' => $academicYear->id,
            'kelas_id' => $class->id,
            'mapel_id' => $this->createSubject()->id,
            'guru_id' => $guru->id,
            'hari' => 'SENIN',
            'jam_mulai' => '07:00',
            'jam_selesai' => '08:00',
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/classes/{$otherClass->id}")
            ->assertForbidden();
    }

    public function test_guru_can_view_class_from_wali_kelas_assignment(): void
    {
        [$user, $guru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        WaliKelas::create([
            'guru_id' => $guru->id,
            'kelas_id' => $class->id,
            'tahun_akademik_id' => $academicYear->id,
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/classes/{$class->id}")
            ->assertOk();
    }

    public function test_wali_kelas_assignment_does_not_grant_access_to_another_academic_year(): void
    {
        [$user, $guru] = $this->createTeacher();

        $academicYearOne = $this->createAcademicYear();

        $academicYearTwo = TahunAkademik::create([
            'tahun_mulai' => 2027,
            'tahun_selesai' => 2028,
            'semester' => 'GANJIL',
            'is_active' => false,
        ]);

        $assignedClass = $this->createClass(
            $academicYearOne,
            'X IPA 1',
            10
        );

        $otherClass = $this->createClass(
            $academicYearTwo,
            'X IPA 1',
            10
        );

        WaliKelas::create([
            'guru_id' => $guru->id,
            'kelas_id' => $assignedClass->id,
            'tahun_akademik_id' => $academicYearOne->id,
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/classes/{$assignedClass->id}")
            ->assertOk();

        $this->getJson("/api/classes/{$otherClass->id}")
            ->assertForbidden();
    }

    public function test_siswa_can_view_own_class(): void
    {
        [$user, $student] = $this->createStudent();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        AnggotaKelas::create([
            'kelas_id' => $class->id,
            'siswa_id' => $student->id,
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/classes/{$class->id}")
            ->assertOk();
    }

    public function test_siswa_cannot_view_another_class(): void
    {
        [$user, $student] = $this->createStudent();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $otherClass = $this->createClass(
            $academicYear,
            'X IPA 2',
            10
        );

        AnggotaKelas::create([
            'kelas_id' => $class->id,
            'siswa_id' => $student->id,
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/classes/{$otherClass->id}")
            ->assertForbidden();
    }

    public function test_create_class_requires_valid_fields(): void
    {
        $user = $this->createUser('TU');

        Sanctum::actingAs($user);

        $this->postJson('/api/classes', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'tahun_akademik_id',
                'nama',
                'tingkat',
            ]);
    }

    public function test_create_class_rejects_unknown_academic_year(): void
    {
        $user = $this->createUser('TU');

        Sanctum::actingAs($user);

        $this->postJson('/api/classes', [
            'tahun_akademik_id' => 999999,
            'nama' => 'X IPA 1',
            'tingkat' => 10,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'tahun_akademik_id',
            ]);
    }

    public function test_class_name_must_be_unique_within_same_academic_year(): void
    {
        $user = $this->createUser('TU');
        $academicYear = $this->createAcademicYear();

        $this->createClass($academicYear);

        Sanctum::actingAs($user);

        $this->postJson('/api/classes', [
            'tahun_akademik_id' => $academicYear->id,
            'nama' => 'X IPA 1',
            'tingkat' => 10,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'nama',
            ]);
    }

    public function test_same_class_name_is_allowed_in_different_academic_year(): void
    {
        $user = $this->createUser('TU');

        $academicYearOne = $this->createAcademicYear();

        $academicYearTwo = TahunAkademik::create([
            'tahun_mulai' => 2027,
            'tahun_selesai' => 2028,
            'semester' => 'GANJIL',
            'is_active' => false,
        ]);

        $this->createClass($academicYearOne);

        Sanctum::actingAs($user);

        $this->postJson('/api/classes', [
            'tahun_akademik_id' => $academicYearTwo->id,
            'nama' => 'X IPA 1',
            'tingkat' => 10,
        ])->assertCreated();
    }

    public function test_tu_can_update_class(): void
    {
        $user = $this->createUser('TU');
        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        Sanctum::actingAs($user);

        $this->putJson("/api/classes/{$class->id}", [
            'tahun_akademik_id' => $academicYear->id,
            'nama' => 'XI IPA 1',
            'tingkat' => 11,
        ])
            ->assertOk()
            ->assertJsonPath('data.nama', 'XI IPA 1')
            ->assertJsonPath('data.tingkat', 11);
    }

    public function test_non_tu_cannot_update_class(): void
    {
        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        foreach (['KEPALA_SEKOLAH', 'GURU', 'SISWA'] as $role) {
            $user = $this->createUser($role);

            Sanctum::actingAs($user);

            $this->putJson("/api/classes/{$class->id}", [
                'tahun_akademik_id' => $academicYear->id,
                'nama' => 'XI IPA 1',
                'tingkat' => 11,
            ])->assertForbidden();
        }
    }

    public function test_tu_can_delete_class_without_dependent_records(): void
    {
        $user = $this->createUser('TU');
        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        Sanctum::actingAs($user);

        $this->deleteJson("/api/classes/{$class->id}")
            ->assertOk();

        $this->assertDatabaseMissing('kelas', [
            'id' => $class->id,
        ]);
    }

    public function test_non_tu_cannot_delete_class(): void
    {
        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        foreach (['KEPALA_SEKOLAH', 'GURU', 'SISWA'] as $role) {
            $user = $this->createUser($role);

            Sanctum::actingAs($user);

            $this->deleteJson("/api/classes/{$class->id}")
                ->assertForbidden();
        }
    }

    public function test_class_list_supports_academic_year_filter(): void
    {
        $user = $this->createUser('TU');

        $academicYearOne = $this->createAcademicYear();

        $academicYearTwo = TahunAkademik::create([
            'tahun_mulai' => 2027,
            'tahun_selesai' => 2028,
            'semester' => 'GANJIL',
            'is_active' => false,
        ]);

        $classOne = $this->createClass(
            $academicYearOne,
            'X IPA 1'
        );

        $classTwo = $this->createClass(
            $academicYearTwo,
            'X IPA 1'
        );

        Sanctum::actingAs($user);

        $response = $this->getJson(
            "/api/classes?academic_year_id={$academicYearOne->id}"
        );

        $response
            ->assertOk()
            ->assertJsonFragment([
                'id' => $classOne->id,
            ])
            ->assertJsonMissing([
                'id' => $classTwo->id,
            ]);
    }

    public function test_class_list_supports_grade_level_filter(): void
    {
        $user = $this->createUser('TU');
        $academicYear = $this->createAcademicYear();

        $gradeTen = $this->createClass(
            $academicYear,
            'X IPA 1',
            10
        );

        $gradeEleven = $this->createClass(
            $academicYear,
            'XI IPA 1',
            11
        );

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/classes?grade_level=10');

        $response
            ->assertOk()
            ->assertJsonFragment([
                'id' => $gradeTen->id,
            ])
            ->assertJsonMissing([
                'id' => $gradeEleven->id,
            ]);
    }

    public function test_class_list_supports_search(): void
    {
        $user = $this->createUser('TU');
        $academicYear = $this->createAcademicYear();

        $target = $this->createClass(
            $academicYear,
            'XI IPA Unggulan'
        );

        $other = $this->createClass(
            $academicYear,
            'X IPS 1',
            10
        );

        Sanctum::actingAs($user);

        $response = $this->getJson(
            '/api/classes?search=Unggulan'
        );

        $response
            ->assertOk()
            ->assertJsonFragment([
                'id' => $target->id,
            ])
            ->assertJsonMissing([
                'id' => $other->id,
            ]);
    }

    public function test_class_list_supports_pagination(): void
    {
        $user = $this->createUser('TU');
        $academicYear = $this->createAcademicYear();

        for ($i = 1; $i <= 3; $i++) {
            $this->createClass(
                $academicYear,
                "X IPA {$i}",
                10
            );
        }

        Sanctum::actingAs($user);

        $this->getJson('/api/classes?per_page=2&page=1')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.total', 3);
    }

    public function test_class_show_returns_404_when_not_found(): void
    {
        $user = $this->createUser('TU');

        Sanctum::actingAs($user);

        $this->getJson('/api/classes/999999')
            ->assertNotFound();
    }

    public function test_class_update_returns_404_when_not_found(): void
    {
        $user = $this->createUser('TU');
        $academicYear = $this->createAcademicYear();

        Sanctum::actingAs($user);

        $this->putJson('/api/classes/999999', [
            'tahun_akademik_id' => $academicYear->id,
            'nama' => 'X IPA 1',
            'tingkat' => 10,
        ])->assertNotFound();
    }

    private function createSubject(): \App\Models\Mapel
    {
        return \App\Models\Mapel::create([
            'kode' => 'MAPEL-' . uniqid(),
            'nama' => 'Mapel Test ' . uniqid(),
        ]);
    }
}