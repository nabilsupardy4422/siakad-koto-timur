<?php

namespace Tests\Feature;

use App\Models\{AnggotaKelas, Assignment, Guru, JadwalPelajaran, Kelas, KomponenNilai, Mapel, Role, Siswa, SubmissionTugas, TahunAkademik, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentAssignmentWorkspaceTest extends TestCase
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

    private function createAssignment(
        JadwalPelajaran $schedule,
        User $creator,
        array $attributes = []
    ): Assignment {
        return Assignment::create(array_merge([
            'jadwal_pelajaran_id' => $schedule->id,
            'judul' => 'Tugas Test',
            'deskripsi' => 'Deskripsi tugas test',
            'deadline' => '2026-10-10 23:59:00',
            'created_by' => $creator->id,
        ], $attributes));
    }


    public function test_guest_and_non_student_cannot_read_student_workspace(): void
    {
        $this->getJson('/api/assignments/student-workspace')->assertUnauthorized();
        Sanctum::actingAs($this->createUser('GURU'));
        $this->getJson('/api/assignments/student-workspace')->assertForbidden();
    }

    public function test_student_sees_only_class_assignments_and_own_submissions(): void
    {
        [$teacher, $guru] = $this->createTeacher();
        [$studentUser, $student] = $this->createStudent();
        [$otherUser, $other] = $this->createStudent();
        $year = $this->createAcademicYear();
        $class = $this->createClass($year);
        $schedule = $this->createSchedule($year, $class, $guru);
        $this->createMembership($student, $class);
        $this->createMembership($other, $class);
        $assignment = $this->createAssignment($schedule, $teacher);
        $otherClass = $this->createClass($year, 'X IPA 2');
        $otherSchedule = $this->createSchedule($year, $otherClass, $guru);
        $this->createAssignment($otherSchedule, $teacher);
        SubmissionTugas::create([
            'assignment_id' => $assignment->id, 'siswa_id' => $student->id,
            'jawaban' => 'Jawaban sendiri', 'status' => 'dikumpulkan', 'submitted_at' => now(),
        ]);
        SubmissionTugas::create([
            'assignment_id' => $assignment->id, 'siswa_id' => $other->id,
            'jawaban' => 'RAHASIA SISWA LAIN', 'status' => 'dikumpulkan', 'submitted_at' => now(),
        ]);
        Sanctum::actingAs($studentUser);
        $response = $this->getJson('/api/assignments/student-workspace');
        $response->assertOk()->assertJsonCount(1, 'data.assignments')
            ->assertJsonPath('data.assignments.0.id', $assignment->id)
            ->assertJsonPath('meta.total', 1)
            ->assertDontSee('RAHASIA SISWA LAIN');
        $this->assertCount(1, $response->json('data.submissions'));
    }

    public function test_pagination_includes_more_than_one_page_without_partial_summary(): void
    {
        [$teacher, $guru] = $this->createTeacher();
        [$user, $student] = $this->createStudent();
        $year = $this->createAcademicYear();
        $class = $this->createClass($year);
        $schedule = $this->createSchedule($year, $class, $guru);
        $this->createMembership($student, $class);
        foreach (range(1, 3) as $i) {
            $this->createAssignment($schedule, $teacher, ['judul' => 'Tugas '.$i]);
        }
        Sanctum::actingAs($user);
        $response = $this->getJson('/api/assignments/student-workspace?per_page=2&page=2');
        $response->assertOk()->assertJsonCount(1, 'data.assignments')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 3);
    }

    public function test_future_membership_and_invalid_pagination_fail_closed(): void
    {
        [$teacher, $guru] = $this->createTeacher();
        [$user, $student] = $this->createStudent();
        $year = $this->createAcademicYear();
        $class = $this->createClass($year);
        $schedule = $this->createSchedule($year, $class, $guru);
        AnggotaKelas::create([
            'kelas_id' => $class->id, 'siswa_id' => $student->id,
            'tanggal_mulai' => now('Asia/Jakarta')->addDays(4)->toDateString(),
            'tanggal_selesai' => null,
        ]);
        $this->createAssignment($schedule, $teacher);
        Sanctum::actingAs($user);
        $this->getJson('/api/assignments/student-workspace')
            ->assertOk()->assertJsonCount(0, 'data.assignments');
        $this->getJson('/api/assignments/student-workspace?per_page=101')->assertUnprocessable();
        $this->getJson('/api/assignments/student-workspace?page=0')->assertUnprocessable();
    }
}
