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
use App\Models\TahunAkademik;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Regression tests for the existing /api/dashboard JSON format and class scope. */
class DashboardLegacyStudentCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $roleCode): User
    {
        $role = Role::firstOrCreate(['code' => $roleCode], ['name' => $roleCode]);
        $unique = uniqid('legacy-');
        return User::create([
            'role_id' => $role->id,
            'username' => $unique,
            'name' => 'Akun Pengujian',
            'email' => $unique.'@example.test',
            'password' => Hash::make('password-test'),
            'account_status' => 'active',
        ]);
    }

    private function fixture(): array
    {
        $account = $this->user('SISWA');
        $student = Siswa::create([
            'user_id' => $account->id,
            'nama_lengkap' => 'Siswa Pengujian',
            'nis' => '12345',
            'nisn' => '0058083291',
        ]);
        $year = TahunAkademik::create([
            'tahun_mulai' => 2026,
            'tahun_selesai' => 2027,
            'semester' => 'GANJIL',
            'is_active' => true,
        ]);
        $ownClass = Kelas::create([
            'tahun_akademik_id' => $year->id,
            'nama' => 'X IPA 1',
            'tingkat' => 10,
        ]);
        $foreignClass = Kelas::create([
            'tahun_akademik_id' => $year->id,
            'nama' => 'X IPA 2',
            'tingkat' => 10,
        ]);
        $subject = Mapel::create(['kode' => 'MAT', 'nama' => 'Matematika']);
        $teacher = Guru::create([
            'user_id' => $this->user('GURU')->id,
            'nama_lengkap' => 'Guru Pengujian',
            'nip' => '20260000001',
        ]);
        $todayName = ['MINGGU', 'SENIN', 'SELASA', 'RABU', 'KAMIS', 'JUMAT', 'SABTU'][Carbon::now('Asia/Jakarta')->dayOfWeek];
        foreach ([$ownClass, $foreignClass] as $class) {
            $schedule = JadwalPelajaran::create([
                'tahun_akademik_id' => $year->id,
                'kelas_id' => $class->id,
                'mapel_id' => $subject->id,
                'guru_id' => $teacher->id,
                'hari' => $todayName,
                'jam_mulai' => '08:00',
                'jam_selesai' => '09:00',
            ]);
            Assignment::create([
                'jadwal_pelajaran_id' => $schedule->id,
                'judul' => 'Tugas '.$class->nama,
                'deadline' => Carbon::now('Asia/Jakarta')->addWeek()->format('Y-m-d H:i:s'),
                'created_by' => $teacher->user_id,
            ]);
        }
        return compact('account', 'student', 'year', 'ownClass', 'foreignClass');
    }

    private function place(Siswa $student, Kelas $class, string $date): void
    {
        AnggotaKelas::create([
            'siswa_id' => $student->id,
            'kelas_id' => $class->id,
            'tanggal_mulai' => $date,
            'tanggal_selesai' => null,
        ]);
    }

    public function test_old_endpoint_returns_legacy_fields_for_active_student(): void
    {
        $f = $this->fixture();
        $this->place($f['student'], $f['ownClass'], Carbon::today('Asia/Jakarta')->subDay()->toDateString());

        $this->actingAs($f['account'])->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.role', 'SISWA')
            ->assertJsonPath('data.class.id', $f['ownClass']->id)
            ->assertJsonCount(1, 'data.today_schedule')
            ->assertJsonPath('data.summary.assignment_count', 1)
            ->assertJsonCount(1, 'data.recent.assignments')
            ->assertJsonStructure(['data' => [
                'role', 'class', 'today_schedule',
                'summary' => ['attendance_count', 'grade_count', 'material_count', 'assignment_count'],
                'recent' => ['materials', 'assignments'],
            ]]);
    }

    public function test_old_endpoint_never_leaks_other_class_without_membership(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['account'])->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.role', 'SISWA')
            ->assertJsonPath('data.class', null)
            ->assertJsonPath('data.summary.assignment_count', 0)
            ->assertJsonPath('data.summary.material_count', 0)
            ->assertJsonCount(0, 'data.today_schedule')
            ->assertJsonCount(0, 'data.recent.assignments')
            ->assertJsonCount(0, 'data.recent.materials');
    }

    public function test_future_membership_does_not_unlock_old_dashboard(): void
    {
        $f = $this->fixture();
        $this->place($f['student'], $f['ownClass'], Carbon::today('Asia/Jakarta')->addDay()->toDateString());
        $this->actingAs($f['account'])->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.class', null)
            ->assertJsonCount(0, 'data.today_schedule');
    }

    public function test_ambiguous_membership_is_rejected_on_old_endpoint(): void
    {
        $f = $this->fixture();
        $start = Carbon::today('Asia/Jakarta')->subDay()->toDateString();
        $this->place($f['student'], $f['ownClass'], $start);
        $this->place($f['student'], $f['foreignClass'], $start);
        $this->actingAs($f['account'])->getJson('/api/dashboard')->assertStatus(409);
    }

    public function test_duplicate_active_period_is_rejected_on_old_endpoint(): void
    {
        $f = $this->fixture();
        TahunAkademik::create([
            'tahun_mulai' => 2027,
            'tahun_selesai' => 2028,
            'semester' => 'GANJIL',
            'is_active' => true,
        ]);
        $this->actingAs($f['account'])->getJson('/api/dashboard')->assertStatus(409);
    }
}
