<?php

namespace Tests\Feature;

use App\Models\AnggotaKelas;
use App\Models\Guru;
use App\Models\JadwalPelajaran;
use App\Models\Kelas;
use App\Models\KomponenNilai;
use App\Models\Mapel;
use App\Models\Role;
use App\Models\Siswa;
use App\Models\TahunAkademik;
use App\Models\User;
use App\Models\WaliKelas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GradeComponentTest extends TestCase
{
    use RefreshDatabase;

    private function createRole(string $code): int
    {
        return Role::firstOrCreate(
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

    private function createAcademicYear(
        int $startYear = 2026,
        int $endYear = 2027,
        string $semester = 'GANJIL',
        bool $active = true
    ): TahunAkademik {
        return TahunAkademik::create([
            'tahun_mulai' => $startYear,
            'tahun_selesai' => $endYear,
            'semester' => $semester,
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

    private function createSubject(): Mapel
    {
        return Mapel::create([
            'kode' => 'MAPEL-' . uniqid(),
            'nama' => 'Mapel Test ' . uniqid(),
        ]);
    }

    private function createSchedule(
        TahunAkademik $academicYear,
        Kelas $class,
        Guru $guru
    ): JadwalPelajaran {
        return JadwalPelajaran::create([
            'tahun_akademik_id' => $academicYear->id,
            'kelas_id' => $class->id,
            'mapel_id' => $this->createSubject()->id,
            'guru_id' => $guru->id,
            'hari' => 'SENIN',
            'jam_mulai' => '07:00',
            'jam_selesai' => '08:00',
        ]);
    }

    private function createMembership(
        Siswa $student,
        Kelas $class
    ): AnggotaKelas {
        return AnggotaKelas::create([
            'kelas_id' => $class->id,
            'siswa_id' => $student->id,
            'tanggal_mulai' => '2026-07-01',
            'tanggal_selesai' => null,
        ]);
    }

    private function createWaliKelas(
        Guru $guru,
        Kelas $class,
        TahunAkademik $academicYear
    ): WaliKelas {
        return WaliKelas::create([
            'guru_id' => $guru->id,
            'kelas_id' => $class->id,
            'tahun_akademik_id' => $academicYear->id,
            'tanggal_mulai' => '2026-07-01',
            'tanggal_selesai' => null,
        ]);
    }

    private function createComponent(
        JadwalPelajaran $schedule,
        string $name = 'Tugas 1',
        string $weight = '25.00'
    ): KomponenNilai {
        return KomponenNilai::create([
            'jadwal_pelajaran_id' => $schedule->id,
            'nama' => $name,
            'bobot' => $weight,
        ]);
    }

    public function test_guest_cannot_access_grade_components(): void
    {
        $this->getJson('/api/grade-components')
            ->assertUnauthorized();
    }

    public function test_teacher_can_create_grade_component_in_own_teaching_context(): void
    {
        [$user, $guru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $guru
        );

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/grade-components', [
            'jadwal_pelajaran_id' => $schedule->id,
            'nama' => 'Tugas 1',
            'bobot' => 25,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.nama',
                'Tugas 1'
            )
            ->assertJsonPath(
                'data.jadwal_pelajaran_id',
                $schedule->id
            )
            ->assertJsonPath(
                'data.bobot',
                '25.00'
            );

        $this->assertDatabaseHas('komponen_nilai', [
            'jadwal_pelajaran_id' => $schedule->id,
            'nama' => 'Tugas 1',
            'bobot' => 25,
        ]);
    }

    public function test_teacher_cannot_create_grade_component_for_another_teacher_schedule(): void
    {
        [$user, $guru] = $this->createTeacher();
        [, $otherGuru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $otherSchedule = $this->createSchedule(
            $academicYear,
            $class,
            $otherGuru
        );

        Sanctum::actingAs($user);

        $this->postJson('/api/grade-components', [
            'jadwal_pelajaran_id' => $otherSchedule->id,
            'nama' => 'Tugas Tidak Sah',
            'bobot' => 25,
        ])->assertForbidden();
    }

    public function test_non_teacher_cannot_create_grade_component(): void
    {
        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        [, $guru] = $this->createTeacher();

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $guru
        );

        foreach ([
            'TU',
            'KEPALA_SEKOLAH',
            'SISWA',
        ] as $role) {
            $user = $this->createUser($role);

            Sanctum::actingAs($user);

            $this->postJson('/api/grade-components', [
                'jadwal_pelajaran_id' => $schedule->id,
                'nama' => 'Komponen',
                'bobot' => 25,
            ])->assertForbidden();
        }
    }

    public function test_teacher_can_view_own_grade_component(): void
    {
        [$user, $guru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $guru
        );

        $component = $this->createComponent(
            $schedule
        );

        Sanctum::actingAs($user);

        $this->getJson(
            "/api/grade-components/{$component->id}"
        )
            ->assertOk()
            ->assertJsonPath(
                'data.id',
                $component->id
            )
            ->assertJsonPath(
                'data.nama',
                'Tugas 1'
            );
    }

    public function test_teacher_cannot_view_another_teacher_grade_component(): void
    {
        [$user] = $this->createTeacher();
        [, $otherGuru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $otherSchedule = $this->createSchedule(
            $academicYear,
            $class,
            $otherGuru
        );

        $component = $this->createComponent(
            $otherSchedule
        );

        Sanctum::actingAs($user);

        $this->getJson(
            "/api/grade-components/{$component->id}"
        )->assertForbidden();
    }

    public function test_wali_kelas_can_view_grade_component_from_assigned_class(): void
    {
        [$user, $guru] = $this->createTeacher();
        [, $otherGuru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();

        $class = $this->createClass(
            $academicYear,
            'X IPA 1'
        );

        $this->createWaliKelas(
            $guru,
            $class,
            $academicYear
        );

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $otherGuru
        );

        $component = $this->createComponent(
            $schedule
        );

        Sanctum::actingAs($user);

        $this->getJson(
            "/api/grade-components/{$component->id}"
        )->assertOk();
    }

    public function test_wali_kelas_cannot_view_grade_component_from_another_class(): void
    {
        [$user, $guru] = $this->createTeacher();
        [, $otherGuru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();

        $assignedClass = $this->createClass(
            $academicYear,
            'X IPA 1'
        );

        $otherClass = $this->createClass(
            $academicYear,
            'X IPA 2'
        );

        $this->createWaliKelas(
            $guru,
            $assignedClass,
            $academicYear
        );

        $otherSchedule = $this->createSchedule(
            $academicYear,
            $otherClass,
            $otherGuru
        );

        $component = $this->createComponent(
            $otherSchedule
        );

        Sanctum::actingAs($user);

        $this->getJson(
            "/api/grade-components/{$component->id}"
        )->assertForbidden();
    }

    public function test_student_can_view_grade_component_from_own_class(): void
    {
        [, $guru] = $this->createTeacher();
        [$studentUser, $student] = $this->createStudent();

        $academicYear = $this->createAcademicYear();

        $class = $this->createClass(
            $academicYear
        );

        $this->createMembership(
            $student,
            $class
        );

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $guru
        );

        $component = $this->createComponent(
            $schedule
        );

        Sanctum::actingAs($studentUser);

        $this->getJson(
            "/api/grade-components/{$component->id}"
        )->assertOk();
    }

    public function test_student_cannot_view_grade_component_from_another_class(): void
    {
        [, $guru] = $this->createTeacher();
        [$studentUser, $student] = $this->createStudent();

        $academicYear = $this->createAcademicYear();

        $ownClass = $this->createClass(
            $academicYear,
            'X IPA 1'
        );

        $otherClass = $this->createClass(
            $academicYear,
            'X IPA 2'
        );

        $this->createMembership(
            $student,
            $ownClass
        );

        $otherSchedule = $this->createSchedule(
            $academicYear,
            $otherClass,
            $guru
        );

        $component = $this->createComponent(
            $otherSchedule
        );

        Sanctum::actingAs($studentUser);

        $this->getJson(
            "/api/grade-components/{$component->id}"
        )->assertForbidden();
    }

    public function test_teacher_can_update_own_grade_component(): void
    {
        [$user, $guru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $guru
        );

        $component = $this->createComponent(
            $schedule
        );

        Sanctum::actingAs($user);

        $this->putJson(
            "/api/grade-components/{$component->id}",
            [
                'nama' => 'Tugas 1 Revisi',
                'bobot' => 30,
            ]
        )
            ->assertOk()
            ->assertJsonPath(
                'data.nama',
                'Tugas 1 Revisi'
            )
            ->assertJsonPath(
                'data.bobot',
                '30.00'
            );

        $this->assertDatabaseHas('komponen_nilai', [
            'id' => $component->id,
            'nama' => 'Tugas 1 Revisi',
            'bobot' => 30,
        ]);
    }

    public function test_teacher_cannot_update_another_teacher_grade_component(): void
    {
        [$user] = $this->createTeacher();
        [, $otherGuru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $otherGuru
        );

        $component = $this->createComponent(
            $schedule
        );

        Sanctum::actingAs($user);

        $this->putJson(
            "/api/grade-components/{$component->id}",
            [
                'nama' => 'Tidak Boleh',
            ]
        )->assertForbidden();
    }

    public function test_teacher_can_move_grade_component_to_own_schedule(): void
    {
        [$user, $guru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();

        $classOne = $this->createClass(
            $academicYear,
            'X IPA 1'
        );

        $classTwo = $this->createClass(
            $academicYear,
            'X IPA 2'
        );

        $scheduleOne = $this->createSchedule(
            $academicYear,
            $classOne,
            $guru
        );

        $scheduleTwo = $this->createSchedule(
            $academicYear,
            $classTwo,
            $guru
        );

        $component = $this->createComponent(
            $scheduleOne
        );

        Sanctum::actingAs($user);

        $this->putJson(
            "/api/grade-components/{$component->id}",
            [
                'jadwal_pelajaran_id' => $scheduleTwo->id,
            ]
        )
            ->assertOk()
            ->assertJsonPath(
                'data.jadwal_pelajaran_id',
                $scheduleTwo->id
            );

        $this->assertDatabaseHas('komponen_nilai', [
            'id' => $component->id,
            'jadwal_pelajaran_id' => $scheduleTwo->id,
        ]);
    }

    public function test_teacher_cannot_move_grade_component_to_another_teacher_schedule(): void
    {
        [$user, $guru] = $this->createTeacher();
        [, $otherGuru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();

        $classOne = $this->createClass(
            $academicYear,
            'X IPA 1'
        );

        $classTwo = $this->createClass(
            $academicYear,
            'X IPA 2'
        );

        $ownSchedule = $this->createSchedule(
            $academicYear,
            $classOne,
            $guru
        );

        $otherSchedule = $this->createSchedule(
            $academicYear,
            $classTwo,
            $otherGuru
        );

        $component = $this->createComponent(
            $ownSchedule
        );

        Sanctum::actingAs($user);

        $this->putJson(
            "/api/grade-components/{$component->id}",
            [
                'jadwal_pelajaran_id' => $otherSchedule->id,
            ]
        )->assertForbidden();
    }

    public function test_teacher_can_delete_own_grade_component(): void
    {
        [$user, $guru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $guru
        );

        $component = $this->createComponent(
            $schedule
        );

        Sanctum::actingAs($user);

        $this->deleteJson(
            "/api/grade-components/{$component->id}"
        )
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Komponen nilai berhasil dihapus.'
            );

        $this->assertDatabaseMissing('komponen_nilai', [
            'id' => $component->id,
        ]);
    }

    public function test_teacher_cannot_delete_another_teacher_grade_component(): void
    {
        [$user] = $this->createTeacher();
        [, $otherGuru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $otherGuru
        );

        $component = $this->createComponent(
            $schedule
        );

        Sanctum::actingAs($user);

        $this->deleteJson(
            "/api/grade-components/{$component->id}"
        )->assertForbidden();
    }

    public function test_grade_component_requires_required_fields(): void
    {
        [$user] = $this->createTeacher();

        Sanctum::actingAs($user);

        $this->postJson(
            '/api/grade-components',
            []
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'jadwal_pelajaran_id',
                'nama',
                'bobot',
            ]);
    }

    public function test_grade_component_rejects_unknown_schedule(): void
    {
        [$user] = $this->createTeacher();

        Sanctum::actingAs($user);

        $this->postJson(
            '/api/grade-components',
            [
                'jadwal_pelajaran_id' => 999999,
                'nama' => 'Tugas',
                'bobot' => 25,
            ]
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'jadwal_pelajaran_id',
            ]);
    }

    public function test_grade_component_requires_numeric_weight(): void
    {
        [$user, $guru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $guru
        );

        Sanctum::actingAs($user);

        $this->postJson(
            '/api/grade-components',
            [
                'jadwal_pelajaran_id' => $schedule->id,
                'nama' => 'Tugas',
                'bobot' => 'bukan angka',
            ]
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'bobot',
            ]);
    }

    public function test_grade_component_name_must_be_unique_within_same_schedule(): void
    {
        [$user, $guru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $guru
        );

        $this->createComponent(
            $schedule,
            'Tugas 1'
        );

        Sanctum::actingAs($user);

        $this->postJson(
            '/api/grade-components',
            [
                'jadwal_pelajaran_id' => $schedule->id,
                'nama' => 'Tugas 1',
                'bobot' => 30,
            ]
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'nama',
            ]);
    }

    public function test_grade_component_same_name_is_allowed_on_different_schedule(): void
    {
        [$user, $guru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();

        $classOne = $this->createClass(
            $academicYear,
            'X IPA 1'
        );

        $classTwo = $this->createClass(
            $academicYear,
            'X IPA 2'
        );

        $scheduleOne = $this->createSchedule(
            $academicYear,
            $classOne,
            $guru
        );

        $scheduleTwo = $this->createSchedule(
            $academicYear,
            $classTwo,
            $guru
        );

        $this->createComponent(
            $scheduleOne,
            'Tugas 1'
        );

        Sanctum::actingAs($user);

        $this->postJson(
            '/api/grade-components',
            [
                'jadwal_pelajaran_id' => $scheduleTwo->id,
                'nama' => 'Tugas 1',
                'bobot' => 25,
            ]
        )
            ->assertCreated()
            ->assertJsonPath(
                'data.nama',
                'Tugas 1'
            );
    }

    public function test_grade_component_list_supports_filters_and_pagination(): void
    {
        [$user, $guru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear(
            2026,
            2027,
            'GANJIL'
        );

        $otherAcademicYear = $this->createAcademicYear(
            2027,
            2028,
            'GENAP',
            false
        );

        $classOne = $this->createClass(
            $academicYear,
            'X IPA 1'
        );

        $classTwo = $this->createClass(
            $otherAcademicYear,
            'X IPA 2'
        );

        $scheduleOne = $this->createSchedule(
            $academicYear,
            $classOne,
            $guru
        );

        $scheduleTwo = $this->createSchedule(
            $otherAcademicYear,
            $classTwo,
            $guru
        );

        $componentOne = $this->createComponent(
            $scheduleOne,
            'Tugas Ganjil'
        );

        $componentTwo = $this->createComponent(
            $scheduleTwo,
            'Tugas Genap'
        );

        Sanctum::actingAs($user);

        $response = $this->getJson(
            '/api/grade-components'
            . '?academic_year_id='
            . $academicYear->id
            . '&semester=GANJIL'
            . '&per_page=1'
        );

        $response
            ->assertOk()
            ->assertJsonPath(
                'meta.per_page',
                1
            )
            ->assertJsonPath(
                'meta.total',
                1
            )
            ->assertJsonFragment([
                'id' => $componentOne->id,
            ])
            ->assertJsonMissing([
                'id' => $componentTwo->id,
            ]);
    }

    public function test_grade_component_show_returns_404_when_not_found(): void
    {
        $user = $this->createUser('TU');

        Sanctum::actingAs($user);

        $this->getJson(
            '/api/grade-components/999999'
        )->assertNotFound();
    }

    public function test_tu_can_view_grade_component(): void
    {
        $user = $this->createUser('TU');

        [, $guru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $guru
        );

        $component = $this->createComponent(
            $schedule
        );

        Sanctum::actingAs($user);

        $this->getJson(
            "/api/grade-components/{$component->id}"
        )->assertOk();
    }

    public function test_kepala_sekolah_can_view_grade_component(): void
    {
        $user = $this->createUser(
            'KEPALA_SEKOLAH'
        );

        [, $guru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $guru
        );

        $component = $this->createComponent(
            $schedule
        );

        Sanctum::actingAs($user);

        $this->getJson(
            "/api/grade-components/{$component->id}"
        )->assertOk();
    }
}