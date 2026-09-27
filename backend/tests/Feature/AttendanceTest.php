<?php

namespace Tests\Feature;

use App\Models\AnggotaKelas;
use App\Models\Guru;
use App\Models\JadwalPelajaran;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\Presensi;
use App\Models\Role;
use App\Models\Siswa;
use App\Models\User;
use App\Models\WaliKelas;
use App\Models\TahunAkademik;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttendanceTest extends TestCase
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

    private function createAcademicYear(
        array $overrides = []
    ): TahunAkademik {
        return TahunAkademik::create(array_merge([
            'tahun_mulai' => 2026,
            'tahun_selesai' => 2027,
            'semester' => 'GANJIL',
            'is_active' => true,
        ], $overrides));
    }

    private function createClass(
        TahunAkademik $academicYear,
        array $overrides = []
    ): Kelas {
        return Kelas::create(array_merge([
            'tahun_akademik_id' => $academicYear->id,
            'nama' => 'X IPA ' . uniqid(),
            'tingkat' => 10,
        ], $overrides));
    }

    private function createTeacher(
        array $overrides = [],
        ?User $user = null
    ): Guru {
        $user ??= $this->createUser('GURU');

        return Guru::create(array_merge([
            'user_id' => $user->id,
            'nip' => 'NIP-' . uniqid(),
            'nama_lengkap' => 'Guru Test',
            'jenis_kelamin' => 'L',
            'no_telepon' => '08123456789',
            'alamat' => 'Alamat Guru',
        ], $overrides));
    }

    private function createStudent(
        array $overrides = [],
        ?User $user = null
    ): Siswa {
        $user ??= $this->createUser('SISWA');

        return Siswa::create(array_merge([
            'user_id' => $user->id,
            'nisn' => 'NISN-' . uniqid(),
            'nis' => 'NIS-' . uniqid(),
            'nama_lengkap' => 'Siswa Test',
            'jenis_kelamin' => 'L',
            'tanggal_lahir' => '2010-01-01',
            'tempat_lahir' => 'Padang',
            'no_telepon' => '08123456789',
            'alamat' => 'Alamat Siswa',
        ], $overrides));
    }

    private function addStudentToClass(
        Siswa $student,
        Kelas $class,
        string $tanggalMulai = '2026-07-01',
        ?string $tanggalSelesai = null
    ): AnggotaKelas {
        return AnggotaKelas::create([
            'siswa_id' => $student->id,
            'kelas_id' => $class->id,
            'tanggal_mulai' => $tanggalMulai,
            'tanggal_selesai' => $tanggalSelesai,
        ]);
    }

    private function createSchedule(
        Guru $teacher,
        Kelas $class,
        TahunAkademik $academicYear
    ): JadwalPelajaran {
        $mapel = Mapel::create([
            'nama' => 'Mapel Test ' . uniqid(),
            'kode' => 'MAPEL-' . uniqid(),
        ]);

        return JadwalPelajaran::create([
            'tahun_akademik_id' => $academicYear->id,
            'kelas_id' => $class->id,
            'mapel_id' => $mapel->id,
            'guru_id' => $teacher->id,
            'hari' => 'Senin',
            'jam_mulai' => '07:00',
            'jam_selesai' => '08:30',
        ]);
    }

    private function createTeachingContext(): array
    {
        $teacher = $this->createTeacher();

        $academicYear = $this->createAcademicYear();

        $class = $this->createClass($academicYear);

        $student = $this->createStudent();

        $this->addStudentToClass(
            $student,
            $class
        );

        $schedule = $this->createSchedule(
            $teacher,
            $class,
            $academicYear
        );

        return [
            'teacher' => $teacher,
            'user' => $teacher->user,
            'academicYear' => $academicYear,
            'class' => $class,
            'student' => $student,
            'schedule' => $schedule,
        ];
    }

    public function test_guest_cannot_access_attendance(): void
    {
        $this
            ->getJson('/api/attendance')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_all_authenticated_roles_can_view_attendance(): void
    {
        foreach ([
            'TU',
            'KEPALA_SEKOLAH',
            'GURU',
            'SISWA',
        ] as $role) {
            $user = $this->createUser($role);

            if ($role === 'GURU') {
                $this->createTeacher([], $user);
            }

            if ($role === 'SISWA') {
                $this->createStudent([], $user);
            }

            Sanctum::actingAs($user);

            $this
                ->getJson('/api/attendance')
                ->assertOk()
                ->assertJsonStructure([
                    'data',
                    'meta',
                ]);
        }
    }

    public function test_non_guru_cannot_create_attendance(): void
    {
        $tu = $this->createUser('TU');

        Sanctum::actingAs($tu);

        $this
            ->postJson('/api/attendance', [])
            ->assertStatus(403)
            ->assertJsonPath(
                'message',
                'Anda tidak memiliki akses ke resource ini.'
            );
    }

    public function test_non_guru_cannot_update_attendance(): void
    {
        $context = $this->createTeachingContext();

        $attendance = Presensi::create([
            'jadwal_pelajaran_id' => $context['schedule']->id,
            'siswa_id' => $context['student']->id,
            'tanggal' => '2026-09-27',
            'status' => 'HADIR',
        ]);

        $tu = $this->createUser('TU');

        Sanctum::actingAs($tu);

        $this
            ->putJson("/api/attendance/{$attendance->id}", [
                'status' => 'IZIN',
            ])
            ->assertStatus(403)
            ->assertJsonPath(
                'message',
                'Anda tidak memiliki akses ke resource ini.'
            );
    }

    public function test_guru_can_create_attendance_for_own_schedule(): void
    {
        $context = $this->createTeachingContext();

        Sanctum::actingAs($context['user']);

        $response = $this->postJson('/api/attendance', [
            'jadwal_pelajaran_id' => $context['schedule']->id,
            'siswa_id' => $context['student']->id,
            'tanggal' => '2026-09-27',
            'status' => 'HADIR',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.status', 'HADIR');

        $this->assertDatabaseHas('presensi', [
            'jadwal_pelajaran_id' => $context['schedule']->id,
            'siswa_id' => $context['student']->id,
            'tanggal' => '2026-09-27 00:00:00',
            'status' => 'HADIR',
        ]);
    }

    public function test_guru_cannot_create_attendance_for_another_gurus_schedule(): void
    {
        $context = $this->createTeachingContext();

        $otherTeacher = $this->createTeacher();

        Sanctum::actingAs($otherTeacher->user);

        $this
            ->postJson('/api/attendance', [
                'jadwal_pelajaran_id' => $context['schedule']->id,
                'siswa_id' => $context['student']->id,
                'tanggal' => '2026-09-27',
                'status' => 'HADIR',
            ])
            ->assertStatus(403);
    }

    public function test_student_must_belong_to_schedule_class(): void
    {
        $context = $this->createTeachingContext();

        $otherClass = $this->createClass(
            $context['academicYear']
        );

        $otherStudent = $this->createStudent();

        $this->addStudentToClass(
            $otherStudent,
            $otherClass
        );

        Sanctum::actingAs($context['user']);

        $this
            ->postJson('/api/attendance', [
                'jadwal_pelajaran_id' => $context['schedule']->id,
                'siswa_id' => $otherStudent->id,
                'tanggal' => '2026-09-27',
                'status' => 'HADIR',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('siswa_id');
    }

    public function test_invalid_status_is_rejected(): void
    {
        $context = $this->createTeachingContext();

        Sanctum::actingAs($context['user']);

        $this
            ->postJson('/api/attendance', [
                'jadwal_pelajaran_id' => $context['schedule']->id,
                'siswa_id' => $context['student']->id,
                'tanggal' => '2026-09-27',
                'status' => 'TERLAMBAT',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_all_valid_attendance_statuses_are_accepted(): void
    {
        $context = $this->createTeachingContext();

        foreach ([
            'HADIR',
            'IZIN',
            'SAKIT',
            'ALPA',
        ] as $index => $status) {
            Sanctum::actingAs($context['user']);

            $tanggal = Carbon::parse('2026-09-27')
                ->addDays($index)
                ->toDateString();

            $this
                ->postJson('/api/attendance', [
                    'jadwal_pelajaran_id' => $context['schedule']->id,
                    'siswa_id' => $context['student']->id,
                    'tanggal' => $tanggal,
                    'status' => $status,
                ])
                ->assertCreated();
        }
    }

    public function test_duplicate_attendance_is_rejected(): void
    {
        $context = $this->createTeachingContext();

        $payload = [
            'jadwal_pelajaran_id' => $context['schedule']->id,
            'siswa_id' => $context['student']->id,
            'tanggal' => '2026-09-27',
            'status' => 'HADIR',
        ];

        Sanctum::actingAs($context['user']);

        $this
            ->postJson('/api/attendance', $payload)
            ->assertCreated();

        $this
            ->postJson('/api/attendance', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('tanggal');
    }

    public function test_guru_can_update_own_attendance(): void
    {
        $context = $this->createTeachingContext();

        $attendance = Presensi::create([
            'jadwal_pelajaran_id' => $context['schedule']->id,
            'siswa_id' => $context['student']->id,
            'tanggal' => '2026-09-27',
            'status' => 'HADIR',
        ]);

        Sanctum::actingAs($context['user']);

        $this
            ->putJson("/api/attendance/{$attendance->id}", [
                'status' => 'IZIN',
                'catatan' => 'Izin keluarga',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'IZIN')
            ->assertJsonPath('data.catatan', 'Izin keluarga');
    }

    public function test_guru_cannot_update_another_gurus_attendance(): void
    {
        $context = $this->createTeachingContext();

        $attendance = Presensi::create([
            'jadwal_pelajaran_id' => $context['schedule']->id,
            'siswa_id' => $context['student']->id,
            'tanggal' => '2026-09-27',
            'status' => 'HADIR',
        ]);

        $otherTeacher = $this->createTeacher();

        Sanctum::actingAs($otherTeacher->user);

        $this
            ->putJson("/api/attendance/{$attendance->id}", [
                'status' => 'ALPA',
            ])
            ->assertStatus(403);
    }

    public function test_siswa_can_only_view_own_attendance(): void
    {
        $context = $this->createTeachingContext();

        $studentUser = $this->createUser('SISWA');

        $ownStudent = $this->createStudent([], $studentUser);

        $otherStudent = $this->createStudent();

        $this->addStudentToClass(
            $ownStudent,
            $context['class']
        );

        $this->addStudentToClass(
            $otherStudent,
            $context['class']
        );

        Presensi::create([
            'jadwal_pelajaran_id' => $context['schedule']->id,
            'siswa_id' => $ownStudent->id,
            'tanggal' => '2026-09-27',
            'status' => 'HADIR',
        ]);

        Presensi::create([
            'jadwal_pelajaran_id' => $context['schedule']->id,
            'siswa_id' => $otherStudent->id,
            'tanggal' => '2026-09-27',
            'status' => 'ALPA',
        ]);

        Sanctum::actingAs($studentUser);

        $response = $this
            ->getJson('/api/attendance');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath(
                'data.0.siswa_id',
                $ownStudent->id
            );
    }

    public function test_attendance_filters_work(): void
    {
        $context = $this->createTeachingContext();

        Presensi::create([
            'jadwal_pelajaran_id' => $context['schedule']->id,
            'siswa_id' => $context['student']->id,
            'tanggal' => '2026-09-27',
            'status' => 'HADIR',
        ]);

        Presensi::create([
            'jadwal_pelajaran_id' => $context['schedule']->id,
            'siswa_id' => $context['student']->id,
            'tanggal' => '2026-09-28',
            'status' => 'ALPA',
        ]);

        Sanctum::actingAs($context['user']);

        $response = $this->getJson(
            '/api/attendance?' . http_build_query([
                'schedule_id' => $context['schedule']->id,
                'student_id' => $context['student']->id,
                'status' => 'ALPA',
                'date_from' => '2026-09-28',
                'date_to' => '2026-09-28',
            ])
        );

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'ALPA');
    }

    public function test_attendance_recap_returns_status_counts(): void
    {
        $context = $this->createTeachingContext();

        foreach ([
            'HADIR',
            'HADIR',
            'IZIN',
            'SAKIT',
            'ALPA',
        ] as $index => $status) {
            $tanggal = Carbon::parse('2026-09-27')
                ->addDays($index)
                ->toDateString();

            Presensi::create([
                'jadwal_pelajaran_id' => $context['schedule']->id,
                'siswa_id' => $context['student']->id,
                'tanggal' => $tanggal,
                'status' => $status,
            ]);
        }

        Sanctum::actingAs($context['user']);

        $this
            ->getJson('/api/attendance/recap')
            ->assertOk()
            ->assertJsonPath('data.total', 5)
            ->assertJsonPath('data.hadir', 2)
            ->assertJsonPath('data.izin', 1)
            ->assertJsonPath('data.sakit', 1)
            ->assertJsonPath('data.alpa', 1);
    }

    public function test_wali_kelas_cannot_view_attendance_from_another_academic_year(): void
    {
        $teacher = $this->createTeacher();
        $otherTeacher = $this->createTeacher();

        $currentAcademicYear = $this->createAcademicYear([
            'tahun_mulai' => 2026,
            'tahun_selesai' => 2027,
        ]);

        $previousAcademicYear = $this->createAcademicYear([
            'tahun_mulai' => 2025,
            'tahun_selesai' => 2026,
        ]);

        $currentClass = $this->createClass($currentAcademicYear);
        $previousClass = $this->createClass($previousAcademicYear);

        $currentStudent = $this->createStudent();
        $previousStudent = $this->createStudent();

        $this->addStudentToClass(
            $currentStudent,
            $currentClass
        );

        $this->addStudentToClass(
            $previousStudent,
            $previousClass,
            '2025-07-01'
        );

        $currentSchedule = $this->createSchedule(
            $otherTeacher,
            $currentClass,
            $currentAcademicYear
        );

        $previousSchedule = $this->createSchedule(
            $otherTeacher,
            $previousClass,
            $previousAcademicYear
        );

        WaliKelas::create([
            'guru_id' => $teacher->id,
            'kelas_id' => $currentClass->id,
            'tahun_akademik_id' => $currentAcademicYear->id,
            'tanggal_mulai' => '2026-07-01',
            'tanggal_selesai' => null,
        ]);

        Presensi::create([
            'jadwal_pelajaran_id' => $currentSchedule->id,
            'siswa_id' => $currentStudent->id,
            'tanggal' => '2026-09-27',
            'status' => 'HADIR',
        ]);

        Presensi::create([
            'jadwal_pelajaran_id' => $previousSchedule->id,
            'siswa_id' => $previousStudent->id,
            'tanggal' => '2025-09-27',
            'status' => 'ALPA',
        ]);

        Sanctum::actingAs($teacher->user);

        $response = $this->getJson('/api/attendance');

        $response
            ->assertOk()
            ->assertJsonFragment([
                'id' => $currentStudent->id,
            ])
            ->assertJsonMissing([
                'id' => $previousStudent->id,
            ]);
    }

    public function test_update_attendance_rejects_date_when_student_is_not_active_member(): void
    {
        $context = $this->createTeachingContext();

        $context['student']
            ->anggotaKelas()
            ->where('kelas_id', $context['class']->id)
            ->update([
                'tanggal_selesai' => '2026-12-31',
            ]);

        $attendance = Presensi::create([
            'jadwal_pelajaran_id' => $context['schedule']->id,
            'siswa_id' => $context['student']->id,
            'tanggal' => '2026-09-27',
            'status' => 'HADIR',
        ]);

        Sanctum::actingAs($context['user']);

        $this
            ->putJson("/api/attendance/{$attendance->id}", [
                'tanggal' => '2027-01-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tanggal');
    }

    public function test_update_attendance_rejects_duplicate_date(): void
    {
        $context = $this->createTeachingContext();

        $firstAttendance = Presensi::create([
            'jadwal_pelajaran_id' => $context['schedule']->id,
            'siswa_id' => $context['student']->id,
            'tanggal' => '2026-09-27',
            'status' => 'HADIR',
        ]);

        $secondAttendance = Presensi::create([
            'jadwal_pelajaran_id' => $context['schedule']->id,
            'siswa_id' => $context['student']->id,
            'tanggal' => '2026-09-28',
            'status' => 'IZIN',
        ]);

        Sanctum::actingAs($context['user']);

        $this
            ->putJson("/api/attendance/{$secondAttendance->id}", [
                'tanggal' => '2026-09-27',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tanggal');

        $this->assertDatabaseHas('presensi', [
            'id' => $firstAttendance->id,
            'tanggal' => '2026-09-27 00:00:00',
        ]);

        $this->assertDatabaseHas('presensi', [
            'id' => $secondAttendance->id,
            'tanggal' => '2026-09-28 00:00:00',
        ]);
    }
    public function test_guru_can_view_wali_kelas_attendance_scope(): void
    {
        $teacher = $this->createTeacher();

        $academicYear = $this->createAcademicYear();

        $class = $this->createClass($academicYear);

        $student = $this->createStudent();

        $this->addStudentToClass(
            $student,
            $class
        );

        $this->createSchedule(
            $teacher,
            $class,
            $academicYear
        );

        WaliKelas::create([
            'guru_id' => $teacher->id,
            'kelas_id' => $class->id,
            'tahun_akademik_id' => $academicYear->id,
            'tanggal_mulai' => '2026-07-01',
            'tanggal_selesai' => null,
        ]);

        $schedule = JadwalPelajaran::query()
            ->where('kelas_id', $class->id)
            ->firstOrFail();

        Presensi::create([
            'jadwal_pelajaran_id' => $schedule->id,
            'siswa_id' => $student->id,
            'tanggal' => '2026-09-27',
            'status' => 'HADIR',
        ]);

        Sanctum::actingAs($teacher->user);

        $this
            ->getJson('/api/attendance')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $student->id,
            ]);
    }
}