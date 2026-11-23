<?php

namespace Tests\Feature;

use App\Models\AnggotaKelas;
use App\Models\JadwalPelajaran;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\Role;
use App\Models\Siswa;
use App\Models\TahunAkademik;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StudentDashboardWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role): User
    {
        $roleModel = Role::firstOrCreate(['code' => $role], ['name' => $role]);
        return User::create([
            'role_id' => $roleModel->id,
            'username' => 'dashboard-'.uniqid(),
            'name' => 'Siswa Dashboard',
            'email' => uniqid().'@example.test',
            'password' => Hash::make('password'),
            'account_status' => 'active',
        ]);
    }

    private function fixture(): array
    {
        $user = $this->account('SISWA');
        $student = Siswa::create(['user_id' => $user->id, 'nama_lengkap' => 'Siswa Dashboard', 'nisn' => uniqid('NISN')]);
        $year = TahunAkademik::create(['tahun_mulai' => 2029, 'tahun_selesai' => 2030, 'semester' => 'GANJIL', 'is_active' => true]);
        $class = Kelas::create(['tahun_akademik_id' => $year->id, 'nama' => 'IPA 1', 'tingkat' => 10]);
        $other = Kelas::create(['tahun_akademik_id' => $year->id, 'nama' => 'IPA 2', 'tingkat' => 10]);
        $mapel = Mapel::create(['kode' => uniqid('MAP'), 'nama' => 'Matematika']);
        foreach ([$class, $other] as $item) {
            JadwalPelajaran::create([
                'tahun_akademik_id' => $year->id,
                'kelas_id' => $item->id,
                'mapel_id' => $mapel->id,
                'guru_id' => \App\Models\Guru::create([
                    'user_id' => $this->account('GURU')->id,
                    'nama_lengkap' => 'Pak Guru',
                    'nip' => uniqid('NIP'),
                ])->id,
                'hari' => [0 => 'MINGGU', 1 => 'SENIN', 2 => 'SELASA', 3 => 'RABU', 4 => 'KAMIS', 5 => 'JUMAT', 6 => 'SABTU'][now('Asia/Jakarta')->dayOfWeek],
                'jam_mulai' => '09:00', 'jam_selesai' => '10:00',
            ]);
        }
        return compact('user', 'student', 'year', 'class', 'other');
    }

    private function member($student, $class, $start): void
    {
        AnggotaKelas::create([
            'siswa_id' => $student->id,
            'kelas_id' => $class->id,
            'tanggal_mulai' => $start,
        ]);
    }

    public function test_guest_and_other_role_denied(): void
    {
        $this->getJson('/api/dashboard/student-workspace')->assertUnauthorized();
        $this->actingAs($this->account('GURU'))->getJson('/api/dashboard/student-workspace')->assertForbidden();
    }

    public function test_no_membership_fails_closed_without_foreign_schedule(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['user'])->getJson('/api/dashboard/student-workspace')
            ->assertOk()->assertJsonPath('data.placement_status', 'unassigned')
            ->assertJsonCount(0, 'data.today_schedule')->assertJsonCount(0, 'data.subjects')
            ->assertJsonPath('data.assignment_summary.total', 0);
    }

    public function test_future_member_does_not_receive_schedules(): void
    {
        $f = $this->fixture();
        $this->member($f['student'], $f['class'], today('Asia/Jakarta')->addDay()->toDateString());
        $this->actingAs($f['user'])->getJson('/api/dashboard/student-workspace')
            ->assertOk()->assertJsonPath('data.placement_status', 'scheduled')
            ->assertJsonCount(0, 'data.today_schedule');
    }

    public function test_active_student_only_receives_own_class_schedule(): void
    {
        $f = $this->fixture();
        $this->member($f['student'], $f['class'], today('Asia/Jakarta')->subDay()->toDateString());
        $this->actingAs($f['user'])->getJson('/api/dashboard/student-workspace')
            ->assertOk()->assertJsonPath('data.class.id', $f['class']->id)
            ->assertJsonPath('data.placement_status', 'active')
            ->assertJsonCount(1, 'data.subjects')
            ->assertJsonCount(1, 'data.today_schedule');
    }

    public function test_two_memberships_or_two_active_periods_rejected(): void
    {
        $f = $this->fixture();
        $this->member($f['student'], $f['class'], today('Asia/Jakarta')->subDay()->toDateString());
        $this->member($f['student'], $f['other'], today('Asia/Jakarta')->subDay()->toDateString());
        $this->actingAs($f['user'])->getJson('/api/dashboard/student-workspace')->assertStatus(409);
        TahunAkademik::create(['tahun_mulai' => 2030, 'tahun_selesai' => 2031, 'semester' => 'GANJIL', 'is_active' => true]);
        $this->getJson('/api/dashboard/student-workspace')->assertStatus(409);
    }

    public function test_cannot_override_identity_or_scope(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['user'])->getJson('/api/dashboard/student-workspace?siswa_id=22')->assertUnprocessable();
        $this->getJson('/api/dashboard/student-workspace?class_id=2')->assertUnprocessable();
    }
}
