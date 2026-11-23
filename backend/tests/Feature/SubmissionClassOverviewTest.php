<?php

namespace Tests\Feature;

use App\Models\AnggotaKelas;
use App\Models\Assignment;
use App\Models\Guru;
use App\Models\JadwalPelajaran;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\Role;
use App\Models\Siswa;
use App\Models\SubmissionTugas;
use App\Models\TahunAkademik;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubmissionClassOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        $roleRecord = Role::firstOrCreate(['code' => $role], ['name' => $role]);
        return User::create([
            'role_id' => $roleRecord->id,
            'username' => strtolower($role).'-'.uniqid(),
            'name' => $role.' Test',
            'email' => uniqid().'@example.test',
            'password' => Hash::make('password'),
            'account_status' => 'active',
        ]);
    }

    private function teacher(): array
    {
        $user = $this->user('GURU');
        $teacher = Guru::create(['user_id' => $user->id, 'nip' => uniqid('NIP-'), 'nama_lengkap' => 'Guru Test']);
        return [$user, $teacher];
    }

    private function student(Kelas $class, string $mode = 'active'): array
    {
        $user = $this->user('SISWA');
        $student = Siswa::create([
            'user_id' => $user->id, 'nisn' => uniqid('NISN-'), 'nis' => uniqid('NIS-'), 'nama_lengkap' => 'Siswa Test',
        ]);
        $today = now('Asia/Jakarta');
        AnggotaKelas::create([
            'kelas_id' => $class->id,
            'siswa_id' => $student->id,
            'tanggal_mulai' => $mode === 'future' ? $today->copy()->addDay()->toDateString() : $today->copy()->subMonth()->toDateString(),
            'tanggal_selesai' => $mode === 'ended' ? $today->copy()->subDay()->toDateString() : null,
        ]);
        return [$user, $student];
    }

    private function assignment(User $teacherUser, Guru $teacher): array
    {
        $year = TahunAkademik::create(['tahun_mulai' => 2026, 'tahun_selesai' => 2027, 'semester' => 'GANJIL', 'is_active' => true]);
        $class = Kelas::create(['tahun_akademik_id' => $year->id, 'nama' => 'X IPA 1', 'tingkat' => 10]);
        $subject = Mapel::create(['kode' => uniqid('MAPEL-'), 'nama' => 'Matematika']);
        $schedule = JadwalPelajaran::create([
            'tahun_akademik_id' => $year->id,
            'kelas_id' => $class->id,
            'mapel_id' => $subject->id,
            'guru_id' => $teacher->id,
            'hari' => 'SENIN', 'jam_mulai' => '07:30', 'jam_selesai' => '09:00',
        ]);
        $assignment = Assignment::create([
            'jadwal_pelajaran_id' => $schedule->id,
            'judul' => 'Tugas Uji', 'deskripsi' => 'Ringkasan',
            'deadline' => now()->addDay(), 'created_by' => $teacherUser->id,
        ]);
        return [$assignment, $class];
    }

    private function submit(Assignment $assignment, Siswa $student, string $status = 'dikumpulkan'): void
    {
        SubmissionTugas::create([
            'assignment_id' => $assignment->id,
            'siswa_id' => $student->id,
            'jawaban' => 'Jawaban pengujian', 'status' => $status, 'submitted_at' => now(),
        ]);
    }

    public function test_teacher_sees_active_class_member_totals_not_pagination_totals(): void
    {
        [$user, $teacher] = $this->teacher();
        [$assignment, $class] = $this->assignment($user, $teacher);
        [, $student1] = $this->student($class);
        [, $student2] = $this->student($class);
        $this->student($class);
        $this->submit($assignment, $student1);
        $this->submit($assignment, $student2, 'terlambat');
        Sanctum::actingAs($user);

        $this->getJson("/api/assignments/{$assignment->id}/submissions")
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.class_overview.assignment_id', $assignment->id)
            ->assertJsonPath('meta.class_overview.class_id', $class->id)
            ->assertJsonPath('meta.class_overview.class_name', 'X IPA 1')
            ->assertJsonPath('meta.class_overview.subject_name', 'Matematika')
            ->assertJsonPath('meta.class_overview.total_students', 3)
            ->assertJsonPath('meta.class_overview.submitted_students', 2)
            ->assertJsonPath('meta.class_overview.not_submitted_students', 1)
            ->assertJsonPath('meta.class_overview.as_of', now('Asia/Jakarta')->toDateString());
    }

    public function test_overview_is_complete_even_when_submissions_span_multiple_pages(): void
    {
        [$user, $teacher] = $this->teacher();
        [$assignment, $class] = $this->assignment($user, $teacher);
        for ($i = 0; $i < 18; $i++) {
            [, $student] = $this->student($class);
            $this->submit($assignment, $student);
        }
        Sanctum::actingAs($user);
        $this->getJson("/api/assignments/{$assignment->id}/submissions?page=1")
            ->assertOk()->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.class_overview.total_students', 18)
            ->assertJsonPath('meta.class_overview.submitted_students', 18);
        $this->getJson("/api/assignments/{$assignment->id}/submissions?page=2")
            ->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.class_overview.total_students', 18)
            ->assertJsonPath('meta.class_overview.submitted_students', 18);
    }

    public function test_future_and_ended_members_do_not_inflate_current_class_overview(): void
    {
        [$user, $teacher] = $this->teacher();
        [$assignment, $class] = $this->assignment($user, $teacher);
        [, $current] = $this->student($class);
        [, $future] = $this->student($class, 'future');
        [, $ended] = $this->student($class, 'ended');
        $this->submit($assignment, $current);
        $this->submit($assignment, $future);
        $this->submit($assignment, $ended);
        Sanctum::actingAs($user);
        $this->getJson("/api/assignments/{$assignment->id}/submissions")
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.class_overview.total_students', 1)
            ->assertJsonPath('meta.class_overview.submitted_students', 1)
            ->assertJsonPath('meta.class_overview.not_submitted_students', 0);
    }

    public function test_unassigned_class_has_zero_active_members_even_with_old_submission(): void
    {
        [$user, $teacher] = $this->teacher();
        [$assignment, $class] = $this->assignment($user, $teacher);
        [, $ended] = $this->student($class, 'ended');
        $this->submit($assignment, $ended);
        Sanctum::actingAs($user);
        $this->getJson("/api/assignments/{$assignment->id}/submissions")
            ->assertOk()->assertJsonPath('meta.class_overview.total_students', 0)
            ->assertJsonPath('meta.class_overview.submitted_students', 0)
            ->assertJsonPath('meta.class_overview.not_submitted_students', 0);
    }

    public function test_other_teacher_cannot_read_class_overview_even_if_same_class(): void
    {
        [$user, $teacher] = $this->teacher();
        [$assignment, $class] = $this->assignment($user, $teacher);
        $this->student($class);
        [$other] = $this->teacher();
        Sanctum::actingAs($other);
        $this->getJson("/api/assignments/{$assignment->id}/submissions")->assertForbidden();
    }

    public function test_student_response_never_exposes_class_overview(): void
    {
        [$user, $teacher] = $this->teacher();
        [$assignment, $class] = $this->assignment($user, $teacher);
        [$studentUser, $student] = $this->student($class);
        $this->submit($assignment, $student);
        Sanctum::actingAs($studentUser);
        $response = $this->getJson("/api/assignments/{$assignment->id}/submissions")->assertOk();
        $this->assertArrayNotHasKey('class_overview', $response->json('meta'));
    }
}
