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
use App\Models\TahunAkademik;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentAttendanceWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-22 12:00:00', 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role): User
    {
        $roleModel = Role::firstOrCreate(['code' => $role], ['name' => $role]);
        return User::create([
            'role_id' => $roleModel->id,
            'username' => strtolower($role).'_'.uniqid(),
            'name' => 'Pengguna '.$role,
            'email' => uniqid('test_').'@example.test',
            'password' => Hash::make('password'),
            'account_status' => 'active',
        ]);
    }

    private function setupContext(bool $membershipActive = true): array
    {
        $year = TahunAkademik::create([
            'tahun_mulai' => 2026, 'tahun_selesai' => 2027,
            'semester' => 'GANJIL', 'is_active' => true,
        ]);
        $class = Kelas::create([
            'tahun_akademik_id' => $year->id,
            'nama' => 'X IPA 1', 'tingkat' => 10,
        ]);
        $account = $this->user('SISWA');
        $student = Siswa::create([
            'user_id' => $account->id,
            'nisn' => 'NISN-'.uniqid(), 'nis' => 'NIS-'.uniqid(),
            'nama_lengkap' => 'Siswa Uji', 'jenis_kelamin' => 'L',
            'tanggal_lahir' => '2010-01-01', 'tempat_lahir' => 'Padang',
            'no_telepon' => '08123456789', 'alamat' => 'Padang',
        ]);
        AnggotaKelas::create([
            'siswa_id' => $student->id, 'kelas_id' => $class->id,
            'tanggal_mulai' => $membershipActive ? '2026-07-01' : '2026-12-01',
            'tanggal_selesai' => null,
        ]);
        $subject = Mapel::create([
            'nama' => 'Matematika', 'kode' => 'MTK-'.uniqid(),
        ]);
        return compact('year', 'class', 'account', 'student', 'subject');
    }

    private function schedule(array $ctx, string $day, string $teacherName): JadwalPelajaran
    {
        $user = $this->user('GURU');
        $teacher = Guru::create([
            'user_id' => $user->id, 'nip' => 'NIP-'.uniqid(),
            'nama_lengkap' => $teacherName, 'jenis_kelamin' => 'L',
            'no_telepon' => '08123456789', 'alamat' => 'Padang',
        ]);
        return JadwalPelajaran::create([
            'tahun_akademik_id' => $ctx['year']->id,
            'kelas_id' => $ctx['class']->id,
            'mapel_id' => $ctx['subject']->id,
            'guru_id' => $teacher->id,
            'hari' => $day, 'jam_mulai' => '07:00', 'jam_selesai' => '08:30',
        ]);
    }

    private function attendance(array $ctx, JadwalPelajaran $schedule, string $date, string $status): void
    {
        Presensi::create([
            'jadwal_pelajaran_id' => $schedule->id,
            'siswa_id' => $ctx['student']->id,
            'tanggal' => $date,
            'status' => $status,
        ]);
    }

    public function test_guest_is_rejected(): void
    {
        $this->getJson('/api/attendance/student-workspace')->assertUnauthorized();
    }

    public function test_non_student_role_is_rejected(): void
    {
        Sanctum::actingAs($this->user('TU'));
        $this->getJson('/api/attendance/student-workspace')->assertForbidden();
    }

    public function test_two_teachers_for_one_subject_produce_one_card_with_correct_totals(): void
    {
        $ctx = $this->setupContext();
        $first = $this->schedule($ctx, 'Selasa', 'Pak Haikal');
        $second = $this->schedule($ctx, 'Sabtu', 'Pak Nabil');
        $this->attendance($ctx, $first, '2026-11-17', 'HADIR');
        $this->attendance($ctx, $second, '2026-11-21', 'IZIN');
        $this->attendance($ctx, $second, '2026-11-28', 'ALPA'); // Future: excluded
        Sanctum::actingAs($ctx['account']);

        $this->getJson('/api/attendance/student-workspace')
            ->assertOk()
            ->assertJsonCount(1, 'data.subjects')
            ->assertJsonCount(2, 'data.subjects.0.schedules')
            ->assertJsonPath('data.subjects.0.summary.HADIR', 1)
            ->assertJsonPath('data.subjects.0.summary.IZIN', 1)
            ->assertJsonPath('data.subjects.0.summary.ALPA', 0)
            ->assertJsonPath('data.subjects.0.summary.total', 2)
            ->assertJsonCount(0, 'data.history');
    }

    public function test_history_is_filtered_by_subject_and_excludes_future_records(): void
    {
        $ctx = $this->setupContext();
        $schedule = $this->schedule($ctx, 'Selasa', 'Pak Haikal');
        $this->attendance($ctx, $schedule, '2026-11-17', 'HADIR');
        $this->attendance($ctx, $schedule, '2026-11-24', 'ALPA');
        Sanctum::actingAs($ctx['account']);

        $this->getJson('/api/attendance/student-workspace?subject_id='.$ctx['subject']->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.history')
            ->assertJsonPath('data.history.0.date', '2026-11-17')
            ->assertJsonPath('data.history.0.teacher', 'Pak Haikal')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_unstarted_membership_has_no_subject_cards_or_history(): void
    {
        $ctx = $this->setupContext(false);
        $schedule = $this->schedule($ctx, 'Selasa', 'Pak Haikal');
        $this->attendance($ctx, $schedule, '2026-11-17', 'HADIR');
        Sanctum::actingAs($ctx['account']);

        $this->getJson('/api/attendance/student-workspace')
            ->assertOk()
            ->assertJsonPath('data.context.status', 'scheduled')
            ->assertJsonCount(0, 'data.subjects')
            ->assertJsonCount(0, 'data.history');
    }

    public function test_unknown_subject_is_rejected(): void
    {
        $ctx = $this->setupContext();
        $this->schedule($ctx, 'Selasa', 'Pak Haikal');
        Sanctum::actingAs($ctx['account']);

        $this->getJson('/api/attendance/student-workspace?subject_id=999999')
            ->assertStatus(422);
    }

    public function test_invalid_pagination_is_rejected(): void
    {
        $ctx = $this->setupContext();
        Sanctum::actingAs($ctx['account']);
        $this->getJson('/api/attendance/student-workspace?per_page=99')->assertStatus(422);
    }
}
