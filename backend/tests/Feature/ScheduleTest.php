<?php

namespace Tests\Feature;

use App\Models\Guru;
use App\Models\JadwalPelajaran;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\Role;
use App\Models\Siswa;
use App\Models\AnggotaKelas;
use App\Models\TahunAkademik;
use App\Models\User;
use App\Models\WaliKelas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ScheduleTest extends TestCase
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
        Mapel $subject,
        Guru $guru,
        string $day = 'SENIN',
        string $start = '07:00',
        string $end = '08:00'
    ): JadwalPelajaran {
        return JadwalPelajaran::create([
            'tahun_akademik_id' => $academicYear->id,
            'kelas_id' => $class->id,
            'mapel_id' => $subject->id,
            'guru_id' => $guru->id,
            'hari' => $day,
            'jam_mulai' => $start,
            'jam_selesai' => $end,
        ]);
    }

    private function createWaliAssignment(
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

    private function addStudentToClass(
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

    public function test_guest_cannot_access_schedule_list(): void
    {
        $this->getJson('/api/schedules')
            ->assertUnauthorized();
    }

    public function test_tu_can_view_schedule_list(): void
    {
        $user = $this->createUser('TU');

        Sanctum::actingAs($user);

        $this->getJson('/api/schedules')
            ->assertOk()
            ->assertJsonStructure([
                'data',
                'meta',
            ]);
    }

    public function test_kepala_sekolah_can_view_schedule_list(): void
    {
        $user = $this->createUser('KEPALA_SEKOLAH');

        Sanctum::actingAs($user);

        $this->getJson('/api/schedules')
            ->assertOk();
    }

    public function test_guru_can_view_schedule_in_teaching_scope(): void
    {
        [$user, $guru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);
        $subject = $this->createSubject();

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $subject,
            $guru
        );

        Sanctum::actingAs($user);

        $this->getJson('/api/schedules')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $schedule->id,
            ]);
    }

    public function test_guru_cannot_view_schedule_outside_teaching_scope(): void
    {
        [$user, $guru] = $this->createTeacher();
        [, $otherGuru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();

        $class = $this->createClass(
            $academicYear,
            'X IPA 1'
        );

        $otherClass = $this->createClass(
            $academicYear,
            'X IPA 2'
        );

        $subject = $this->createSubject();

        $ownSchedule = $this->createSchedule(
            $academicYear,
            $class,
            $subject,
            $guru
        );

        $otherSchedule = $this->createSchedule(
            $academicYear,
            $otherClass,
            $subject,
            $otherGuru,
            'SELASA'
        );

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/schedules');

        $response
            ->assertOk()
            ->assertJsonFragment([
                'id' => $ownSchedule->id,
            ])
            ->assertJsonMissing([
                'id' => $otherSchedule->id,
            ]);
    }

    public function test_wali_kelas_can_view_schedule_of_assigned_class(): void
    {
        [$user, $guru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);
        $subject = $this->createSubject();

        $this->createWaliAssignment(
            $guru,
            $class,
            $academicYear
        );

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $subject,
            $guru
        );

        Sanctum::actingAs($user);

        $this->getJson('/api/schedules')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $schedule->id,
            ]);
    }

    public function test_wali_kelas_can_view_schedule_of_assigned_class_even_when_taught_by_another_teacher(): void
    {
        [$user, $waliGuru] = $this->createTeacher();
        [, $teacher] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();

        $class = $this->createClass($academicYear);

        WaliKelas::create([
            'guru_id' => $waliGuru->id,
            'kelas_id' => $class->id,
            'tahun_akademik_id' => $academicYear->id,
            'tanggal_mulai' => '2026-07-01',
            'tanggal_selesai' => null,
        ]);

        $subject = $this->createSubject();

        JadwalPelajaran::create([
            'tahun_akademik_id' => $academicYear->id,
            'kelas_id' => $class->id,
            'mapel_id' => $subject->id,
            'guru_id' => $teacher->id,
            'hari' => 'Senin',
            'jam_mulai' => '07:00',
            'jam_selesai' => '08:00',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/schedules');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $response->assertJsonPath(
            'data.0.kelas_id',
            $class->id
        );

        $response->assertJsonPath(
            'data.0.guru_id',
            $teacher->id
        );
    }

    public function test_wali_kelas_cannot_view_schedule_of_unassigned_class(): void
    {
        [$user, $waliGuru] = $this->createTeacher();
        [, $otherTeacher] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();

        $assignedClass = $this->createClass(
            $academicYear,
            'X IPA 1'
        );

        $otherClass = $this->createClass(
            $academicYear,
            'X IPA 2'
        );

        WaliKelas::create([
            'guru_id' => $waliGuru->id,
            'kelas_id' => $assignedClass->id,
            'tahun_akademik_id' => $academicYear->id,
            'tanggal_mulai' => '2026-07-01',
            'tanggal_selesai' => null,
        ]);

        $subject = $this->createSubject();

        JadwalPelajaran::create([
            'tahun_akademik_id' => $academicYear->id,
            'kelas_id' => $otherClass->id,
            'mapel_id' => $subject->id,
            'guru_id' => $otherTeacher->id,
            'hari' => 'Senin',
            'jam_mulai' => '07:00',
            'jam_selesai' => '08:00',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/schedules');

        $response
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_siswa_can_view_schedule_of_own_class(): void
    {
        [$user, $student] = $this->createStudent();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $teacherUser = $this->createUser('GURU');

        $guru = Guru::create([
            'user_id' => $teacherUser->id,
            'nip' => 'NIP-' . uniqid(),
            'nama_lengkap' => 'Guru Jadwal',
        ]);

        $subject = $this->createSubject();

        $this->addStudentToClass(
            $student,
            $class
        );

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $subject,
            $guru
        );

        Sanctum::actingAs($user);

        $this->getJson('/api/schedules')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $schedule->id,
            ]);
    }

    public function test_siswa_cannot_view_schedule_of_another_class(): void
    {
        [$user, $student] = $this->createStudent();

        $academicYear = $this->createAcademicYear();

        $ownClass = $this->createClass(
            $academicYear,
            'X IPA 1'
        );

        $otherClass = $this->createClass(
            $academicYear,
            'X IPA 2'
        );

        [$teacherUser, $guru] = $this->createTeacher();

        $subject = $this->createSubject();

        $this->addStudentToClass(
            $student,
            $ownClass
        );

        $ownSchedule = $this->createSchedule(
            $academicYear,
            $ownClass,
            $subject,
            $guru
        );

        $otherSchedule = $this->createSchedule(
            $academicYear,
            $otherClass,
            $subject,
            $guru,
            'SELASA'
        );

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/schedules');

        $response
            ->assertOk()
            ->assertJsonFragment([
                'id' => $ownSchedule->id,
            ])
            ->assertJsonMissing([
                'id' => $otherSchedule->id,
            ]);
    }

    public function test_tu_can_create_schedule(): void
    {
        $user = $this->createUser('TU');

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);
        $subject = $this->createSubject();

        [, $guru] = $this->createTeacher();

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/schedules', [
            'tahun_akademik_id' => $academicYear->id,
            'kelas_id' => $class->id,
            'mapel_id' => $subject->id,
            'guru_id' => $guru->id,
            'hari' => 'SENIN',
            'jam_mulai' => '07:00',
            'jam_selesai' => '08:00',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.tahun_akademik_id',
                $academicYear->id
            )
            ->assertJsonPath(
                'data.kelas_id',
                $class->id
            )
            ->assertJsonPath(
                'data.mapel_id',
                $subject->id
            )
            ->assertJsonPath(
                'data.guru_id',
                $guru->id
            )
            ->assertJsonPath(
                'data.hari',
                'SENIN'
            );

        $this->assertDatabaseHas('jadwal_pelajaran', [
            'tahun_akademik_id' => $academicYear->id,
            'kelas_id' => $class->id,
            'mapel_id' => $subject->id,
            'guru_id' => $guru->id,
            'hari' => 'SENIN',
            'jam_mulai' => '07:00',
            'jam_selesai' => '08:00',
        ]);
    }

    public function test_non_tu_cannot_create_schedule(): void
    {
        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);
        $subject = $this->createSubject();
        [, $guru] = $this->createTeacher();

        foreach (['KEPALA_SEKOLAH', 'GURU', 'SISWA'] as $role) {
            $user = $this->createUser($role);

            Sanctum::actingAs($user);

            $this->postJson('/api/schedules', [
                'tahun_akademik_id' => $academicYear->id,
                'kelas_id' => $class->id,
                'mapel_id' => $subject->id,
                'guru_id' => $guru->id,
                'hari' => 'SENIN',
                'jam_mulai' => '07:00',
                'jam_selesai' => '08:00',
            ])->assertForbidden();
        }
    }

    public function test_create_schedule_requires_valid_fields(): void
    {
        $user = $this->createUser('TU');

        Sanctum::actingAs($user);

        $this->postJson('/api/schedules', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'tahun_akademik_id',
                'kelas_id',
                'mapel_id',
                'guru_id',
                'hari',
                'jam_mulai',
                'jam_selesai',
            ]);
    }

    public function test_create_schedule_rejects_unknown_relations(): void
    {
        $user = $this->createUser('TU');

        Sanctum::actingAs($user);

        $this->postJson('/api/schedules', [
            'tahun_akademik_id' => 999999,
            'kelas_id' => 999999,
            'mapel_id' => 999999,
            'guru_id' => 999999,
            'hari' => 'SENIN',
            'jam_mulai' => '07:00',
            'jam_selesai' => '08:00',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'tahun_akademik_id',
                'kelas_id',
                'mapel_id',
                'guru_id',
            ]);
    }

    public function test_create_schedule_rejects_invalid_time_range(): void
    {
        $user = $this->createUser('TU');

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);
        $subject = $this->createSubject();
        [, $guru] = $this->createTeacher();

        Sanctum::actingAs($user);

        $this->postJson('/api/schedules', [
            'tahun_akademik_id' => $academicYear->id,
            'kelas_id' => $class->id,
            'mapel_id' => $subject->id,
            'guru_id' => $guru->id,
            'hari' => 'SENIN',
            'jam_mulai' => '09:00',
            'jam_selesai' => '08:00',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'jam_selesai',
            ]);
    }

    public function test_create_schedule_rejects_class_schedule_conflict(): void
    {
        $user = $this->createUser('TU');

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $subjectOne = $this->createSubject();
        $subjectTwo = $this->createSubject();

        [, $guruOne] = $this->createTeacher();
        [, $guruTwo] = $this->createTeacher();

        $this->createSchedule(
            $academicYear,
            $class,
            $subjectOne,
            $guruOne,
            'SENIN',
            '07:00',
            '08:00'
        );

        Sanctum::actingAs($user);

        $this->postJson('/api/schedules', [
            'tahun_akademik_id' => $academicYear->id,
            'kelas_id' => $class->id,
            'mapel_id' => $subjectTwo->id,
            'guru_id' => $guruTwo->id,
            'hari' => 'SENIN',
            'jam_mulai' => '07:30',
            'jam_selesai' => '08:30',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'kelas_id',
            ]);
    }

    public function test_create_schedule_rejects_teacher_schedule_conflict(): void
    {
        $user = $this->createUser('TU');

        $academicYear = $this->createAcademicYear();

        $classOne = $this->createClass(
            $academicYear,
            'X IPA 1'
        );

        $classTwo = $this->createClass(
            $academicYear,
            'X IPA 2'
        );

        $subjectOne = $this->createSubject();
        $subjectTwo = $this->createSubject();

        [, $guru] = $this->createTeacher();

        $this->createSchedule(
            $academicYear,
            $classOne,
            $subjectOne,
            $guru,
            'SENIN',
            '07:00',
            '08:00'
        );

        Sanctum::actingAs($user);

        $this->postJson('/api/schedules', [
            'tahun_akademik_id' => $academicYear->id,
            'kelas_id' => $classTwo->id,
            'mapel_id' => $subjectTwo->id,
            'guru_id' => $guru->id,
            'hari' => 'SENIN',
            'jam_mulai' => '07:30',
            'jam_selesai' => '08:30',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'guru_id',
            ]);
    }

    public function test_non_overlapping_schedule_is_allowed(): void
    {
        $user = $this->createUser('TU');

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $subjectOne = $this->createSubject();
        $subjectTwo = $this->createSubject();

        [, $guruOne] = $this->createTeacher();
        [, $guruTwo] = $this->createTeacher();

        $this->createSchedule(
            $academicYear,
            $class,
            $subjectOne,
            $guruOne,
            'SENIN',
            '07:00',
            '08:00'
        );

        Sanctum::actingAs($user);

        $this->postJson('/api/schedules', [
            'tahun_akademik_id' => $academicYear->id,
            'kelas_id' => $class->id,
            'mapel_id' => $subjectTwo->id,
            'guru_id' => $guruTwo->id,
            'hari' => 'SENIN',
            'jam_mulai' => '08:00',
            'jam_selesai' => '09:00',
        ])
            ->assertCreated();
    }

    public function test_tu_can_view_schedule_detail(): void
    {
        $user = $this->createUser('TU');

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);
        $subject = $this->createSubject();
        [, $guru] = $this->createTeacher();

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $subject,
            $guru
        );

        Sanctum::actingAs($user);

        $this->getJson("/api/schedules/{$schedule->id}")
            ->assertOk()
            ->assertJsonPath(
                'data.id',
                $schedule->id
            );
    }

    public function test_schedule_show_returns_404_when_not_found(): void
    {
        $user = $this->createUser('TU');

        Sanctum::actingAs($user);

        $this->getJson('/api/schedules/999999')
            ->assertNotFound();
    }

    public function test_tu_can_update_schedule(): void
    {
        $user = $this->createUser('TU');

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);
        $subject = $this->createSubject();
        [, $guru] = $this->createTeacher();

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $subject,
            $guru
        );

        Sanctum::actingAs($user);

        $this->putJson("/api/schedules/{$schedule->id}", [
            'hari' => 'SELASA',
            'jam_mulai' => '09:00',
            'jam_selesai' => '10:00',
        ])
            ->assertOk()
            ->assertJsonPath(
                'data.id',
                $schedule->id
            )
            ->assertJsonPath(
                'data.hari',
                'SELASA'
            )
            ->assertJsonPath(
                'data.jam_mulai',
                '09:00'
            )
            ->assertJsonPath(
                'data.jam_selesai',
                '10:00'
            );

        $schedule->refresh();

        $this->assertSame('SELASA', $schedule->hari);
    }

    public function test_non_tu_cannot_update_schedule(): void
    {
        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);
        $subject = $this->createSubject();
        [, $guru] = $this->createTeacher();

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $subject,
            $guru
        );

        foreach (['KEPALA_SEKOLAH', 'GURU', 'SISWA'] as $role) {
            $user = $this->createUser($role);

            Sanctum::actingAs($user);

            $this->putJson("/api/schedules/{$schedule->id}", [
                'hari' => 'SELASA',
                'jam_mulai' => '09:00',
                'jam_selesai' => '10:00',
            ])->assertForbidden();
        }
    }

    public function test_schedule_update_returns_404_when_not_found(): void
    {
        $user = $this->createUser('TU');

        $academicYear = $this->createAcademicYear();

        Sanctum::actingAs($user);

        $this->putJson('/api/schedules/999999', [
            'hari' => 'SELASA',
            'jam_mulai' => '09:00',
            'jam_selesai' => '10:00',
        ])->assertNotFound();
    }

    public function test_tu_can_delete_schedule(): void
    {
        $user = $this->createUser('TU');

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);
        $subject = $this->createSubject();
        [, $guru] = $this->createTeacher();

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $subject,
            $guru
        );

        Sanctum::actingAs($user);

        $this->deleteJson("/api/schedules/{$schedule->id}")
            ->assertOk();

        $this->assertDatabaseMissing('jadwal_pelajaran', [
            'id' => $schedule->id,
        ]);
    }

    public function test_non_tu_cannot_delete_schedule(): void
    {
        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);
        $subject = $this->createSubject();
        [, $guru] = $this->createTeacher();

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $subject,
            $guru
        );

        foreach (['KEPALA_SEKOLAH', 'GURU', 'SISWA'] as $role) {
            $user = $this->createUser($role);

            Sanctum::actingAs($user);

            $this->deleteJson("/api/schedules/{$schedule->id}")
                ->assertForbidden();
        }
    }

    public function test_schedule_delete_returns_404_when_not_found(): void
    {
        $user = $this->createUser('TU');

        Sanctum::actingAs($user);

        $this->deleteJson('/api/schedules/999999')
            ->assertNotFound();
    }

    public function test_schedule_list_supports_academic_year_filter(): void
    {
        $user = $this->createUser('TU');

        $academicYearOne = $this->createAcademicYear();

        $academicYearTwo = $this->createAcademicYear(
            2027,
            2028,
            'GANJIL',
            false
        );

        $classOne = $this->createClass(
            $academicYearOne,
            'X IPA 1'
        );

        $classTwo = $this->createClass(
            $academicYearTwo,
            'X IPA 1'
        );

        $subjectOne = $this->createSubject();
        $subjectTwo = $this->createSubject();

        [, $guruOne] = $this->createTeacher();
        [, $guruTwo] = $this->createTeacher();

        $scheduleOne = $this->createSchedule(
            $academicYearOne,
            $classOne,
            $subjectOne,
            $guruOne
        );

        $scheduleTwo = $this->createSchedule(
            $academicYearTwo,
            $classTwo,
            $subjectTwo,
            $guruTwo
        );

        Sanctum::actingAs($user);

        $this->getJson(
            "/api/schedules?academic_year_id={$academicYearOne->id}"
        )
            ->assertOk()
            ->assertJsonFragment([
                'id' => $scheduleOne->id,
            ])
            ->assertJsonMissing([
                'id' => $scheduleTwo->id,
            ]);
    }

    public function test_schedule_list_supports_class_filter(): void
    {
        $user = $this->createUser('TU');

        $academicYear = $this->createAcademicYear();

        $classOne = $this->createClass(
            $academicYear,
            'X IPA 1'
        );

        $classTwo = $this->createClass(
            $academicYear,
            'X IPA 2'
        );

        $subject = $this->createSubject();

        [, $guru] = $this->createTeacher();

        $scheduleOne = $this->createSchedule(
            $academicYear,
            $classOne,
            $subject,
            $guru
        );

        $scheduleTwo = $this->createSchedule(
            $academicYear,
            $classTwo,
            $subject,
            $guru,
            'SELASA'
        );

        Sanctum::actingAs($user);

        $this->getJson(
            "/api/schedules?class_id={$classOne->id}"
        )
            ->assertOk()
            ->assertJsonFragment([
                'id' => $scheduleOne->id,
            ])
            ->assertJsonMissing([
                'id' => $scheduleTwo->id,
            ]);
    }

    public function test_schedule_list_supports_subject_filter(): void
    {
        $user = $this->createUser('TU');

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $subjectOne = $this->createSubject();
        $subjectTwo = $this->createSubject();

        [, $guru] = $this->createTeacher();

        $scheduleOne = $this->createSchedule(
            $academicYear,
            $class,
            $subjectOne,
            $guru
        );

        $scheduleTwo = $this->createSchedule(
            $academicYear,
            $class,
            $subjectTwo,
            $guru,
            'SELASA'
        );

        Sanctum::actingAs($user);

        $this->getJson(
            "/api/schedules?subject_id={$subjectOne->id}"
        )
            ->assertOk()
            ->assertJsonFragment([
                'id' => $scheduleOne->id,
            ])
            ->assertJsonMissing([
                'id' => $scheduleTwo->id,
            ]);
    }

    public function test_schedule_list_supports_teacher_filter(): void
    {
        $user = $this->createUser('TU');

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);
        $subject = $this->createSubject();

        [, $guruOne] = $this->createTeacher();
        [, $guruTwo] = $this->createTeacher();

        $scheduleOne = $this->createSchedule(
            $academicYear,
            $class,
            $subject,
            $guruOne
        );

        $scheduleTwo = $this->createSchedule(
            $academicYear,
            $class,
            $subject,
            $guruTwo,
            'SELASA'
        );

        Sanctum::actingAs($user);

        $this->getJson(
            "/api/schedules?teacher_id={$guruOne->id}"
        )
            ->assertOk()
            ->assertJsonFragment([
                'id' => $scheduleOne->id,
            ])
            ->assertJsonMissing([
                'id' => $scheduleTwo->id,
            ]);
    }

    public function test_schedule_list_supports_day_filter(): void
    {
        $user = $this->createUser('TU');

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);
        $subject = $this->createSubject();

        [, $guru] = $this->createTeacher();

        $monday = $this->createSchedule(
            $academicYear,
            $class,
            $subject,
            $guru,
            'SENIN'
        );

        $tuesday = $this->createSchedule(
            $academicYear,
            $class,
            $subject,
            $guru,
            'SELASA',
            '09:00',
            '10:00'
        );

        Sanctum::actingAs($user);

        $this->getJson('/api/schedules?day=SENIN')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $monday->id,
            ])
            ->assertJsonMissing([
                'id' => $tuesday->id,
            ]);
    }

    public function test_schedule_list_supports_pagination(): void
    {
        $user = $this->createUser('TU');

        $academicYear = $this->createAcademicYear();
        $subject = $this->createSubject();

        [, $guru] = $this->createTeacher();

        for ($index = 1; $index <= 3; $index++) {
            $class = $this->createClass(
                $academicYear,
                'X IPA ' . $index
            );

            $this->createSchedule(
                $academicYear,
                $class,
                $subject,
                $guru
            );
        }

        Sanctum::actingAs($user);

        $this->getJson('/api/schedules?per_page=2&page=1')
            ->assertOk()
            ->assertJsonPath(
                'meta.per_page',
                2
            )
            ->assertJsonPath(
                'meta.current_page',
                1
            )
            ->assertJsonPath(
                'meta.total',
                3
            );
    }
}