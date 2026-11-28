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

class PrincipalScheduleCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role): User
    {
        $roleRow = Role::firstOrCreate(['code' => $role], ['name' => $role]);
        return User::create([
            'role_id' => $roleRow->id,
            'username' => uniqid($role . '_'),
            'name' => 'Akun ' . $role,
            'email' => uniqid('user') . '@example.test',
            'password' => Hash::make('password'),
            'account_status' => 'active',
        ]);
    }

    private function year(int $start = 2026, string $semester = 'GANJIL'): TahunAkademik
    {
        return TahunAkademik::create([
            'tahun_mulai' => $start,
            'tahun_selesai' => $start + 1,
            'semester' => $semester,
            'is_active' => true,
        ]);
    }

    private function schoolClass(TahunAkademik $year, string $name = 'IPA 1'): Kelas
    {
        return Kelas::create(['tahun_akademik_id' => $year->id, 'nama' => $name, 'tingkat' => 10]);
    }

    private function teacher(): Guru
    {
        $user = $this->user('GURU');
        return Guru::create([
            'user_id' => $user->id,
            'nip' => uniqid('NIP'),
            'nama_lengkap' => 'Guru Matematika',
            'jenis_kelamin' => 'L',
            'no_telepon' => '08123456789',
            'alamat' => 'Alamat Guru',
        ]);
    }

    private function student(): Siswa
    {
        $user = $this->user('SISWA');
        return Siswa::create([
            'user_id' => $user->id,
            'nisn' => uniqid('NISN'),
            'nis' => uniqid('NIS'),
            'nama_lengkap' => 'Siswa',
            'jenis_kelamin' => 'P',
            'tempat_lahir' => 'Padang',
            'tanggal_lahir' => '2010-01-01',
            'alamat' => 'Alamat',
        ]);
    }

    private function member(Kelas $class, Siswa $student, string $from = '2026-07-01', ?string $to = null): void
    {
        AnggotaKelas::create([
            'kelas_id' => $class->id,
            'siswa_id' => $student->id,
            'tanggal_mulai' => $from,
            'tanggal_selesai' => $to,
        ]);
    }

    private function schedule(
        TahunAkademik $year, Kelas $class, string $subject,
        string $day = 'Senin', string $start = '07:00', string $end = '08:00'
    ): JadwalPelajaran {
        $mapel = Mapel::create(['nama' => $subject, 'kode' => uniqid('MP')]);
        return JadwalPelajaran::create([
            'tahun_akademik_id' => $year->id,
            'kelas_id' => $class->id,
            'mapel_id' => $mapel->id,
            'guru_id' => $this->teacher()->id,
            'hari' => $day,
            'jam_mulai' => $start,
            'jam_selesai' => $end,
        ]);
    }

    private function record(JadwalPelajaran $schedule, Siswa $student, string $date = '2026-11-23'): void
    {
        Presensi::create([
            'jadwal_pelajaran_id' => $schedule->id,
            'siswa_id' => $student->id,
            'tanggal' => $date,
            'status' => 'HADIR',
        ]);
    }

    private function url(TahunAkademik $year, string $date = '2026-11-23', array $other = []): string
    {
        return '/api/attendance/principal-schedule-coverage?' . http_build_query([
            'academic_year_id' => $year->id, 'date' => $date, ...$other,
        ]);
    }

    public function test_only_principal_can_read_daily_schedule_coverage(): void
    {
        $year = $this->year();
        $this->getJson($this->url($year))->assertUnauthorized();
        foreach (['TU', 'GURU', 'SISWA'] as $role) {
            Sanctum::actingAs($this->user($role));
            $this->getJson($this->url($year))->assertForbidden();
        }
        Sanctum::actingAs($this->user('KEPALA_SEKOLAH'));
        $this->getJson($this->url($year))->assertOk()
            ->assertJsonPath('meta.unit', 'weekly_schedule_with_saved_student_records')
            ->assertJsonPath('meta.is_actual_lesson_confirmation', false);
    }

    public function test_complete_partial_and_missing_are_counted_by_schedule_not_by_teacher_or_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-24 12:00:00', 'Asia/Jakarta'));
        $year = $this->year();
        $class = $this->schoolClass($year);
        $one = $this->student(); $two = $this->student();
        $this->member($class, $one); $this->member($class, $two);
        $full = $this->schedule($year, $class, 'Matematika');
        $partial = $this->schedule($year, $class, 'Bahasa Indonesia', 'Senin', '08:00', '09:00');
        $none = $this->schedule($year, $class, 'Biologi', 'Senin', '09:00', '10:00');
        $this->record($full, $one); $this->record($full, $two); $this->record($partial, $one);
        $emptyClass = $this->schoolClass($year, 'IPA 2');
        $this->schedule($year, $emptyClass, 'Fisika', 'Senin', '10:00', '11:00');
        Sanctum::actingAs($this->user('KEPALA_SEKOLAH'));
        $response = $this->getJson($this->url($year))->assertOk()
            ->assertJsonPath('data.summary.scheduled', 4)
            ->assertJsonPath('data.summary.with_students', 3)
            ->assertJsonPath('data.summary.evaluated', 3)
            ->assertJsonPath('data.summary.with_records', 2)
            ->assertJsonPath('data.summary.complete', 1)
            ->assertJsonPath('data.summary.partial', 1)
            ->assertJsonPath('data.summary.without_records', 1)
            ->assertJsonPath('data.summary.without_students', 1);
        $items = collect($response->json('data.schedules'))->keyBy('schedule_id');
        $this->assertSame('complete', $items[$full->id]['state']);
        $this->assertSame(2, $items[$full->id]['recorded_students']);
        $this->assertSame('partial', $items[$partial->id]['state']);
        $this->assertSame('without_records', $items[$none->id]['state']);
    }

    public function test_ongoing_and_future_schedules_do_not_count_as_missing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-23 07:30:00', 'Asia/Jakarta'));
        $year = $this->year(); $class = $this->schoolClass($year);
        $one = $this->student(); $this->member($class, $one);
        $finished = $this->schedule($year, $class, 'Seni', 'Senin', '06:00', '07:00');
        $ongoing = $this->schedule($year, $class, 'Matematika', 'Senin', '07:00', '08:00');
        $upcoming = $this->schedule($year, $class, 'Bahasa', 'Senin', '10:00', '11:00');
        $this->record($ongoing, $one);
        Sanctum::actingAs($this->user('KEPALA_SEKOLAH'));
        $response = $this->getJson($this->url($year))->assertOk()
            ->assertJsonPath('data.summary.evaluated', 1)
            ->assertJsonPath('data.summary.without_records', 1)
            ->assertJsonPath('data.summary.not_finished', 2);
        $items = collect($response->json('data.schedules'))->keyBy('schedule_id');
        $this->assertSame('not_finished', $items[$upcoming->id]['state']);
        $this->assertSame('upcoming', $items[$upcoming->id]['time_state']);
        $this->assertSame('ongoing', $items[$ongoing->id]['time_state']);
        $this->assertSame(1, $items[$ongoing->id]['recorded_students']);
        $this->assertSame('finished', $items[$finished->id]['time_state']);
    }

    public function test_future_membership_historical_end_and_other_weekday_are_not_falsely_counted(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-24 12:00:00', 'Asia/Jakarta'));
        $year = $this->year(); $class = $this->schoolClass($year);
        $eligible = $this->student(); $future = $this->student(); $ended = $this->student();
        $this->member($class, $eligible, '2026-07-01', '2026-11-23');
        $this->member($class, $future, '2026-11-24');
        $this->member($class, $ended, '2026-07-01', '2026-11-22');
        $mon = $this->schedule($year, $class, 'IPA', 'Senin');
        $tue = $this->schedule($year, $class, 'Olahraga', 'Selasa', '08:00', '09:00');
        $this->record($mon, $eligible);
        $this->record($mon, $future);
        $this->record($tue, $eligible); // Replacement lesson: not a recurring Monday slot.
        Sanctum::actingAs($this->user('KEPALA_SEKOLAH'));
        $response = $this->getJson($this->url($year))->assertOk()
            ->assertJsonPath('data.summary.scheduled', 1)
            ->assertJsonPath('data.summary.complete', 1)
            ->assertJsonPath('data.schedules.0.students', 1)
            ->assertJsonPath('data.schedules.0.recorded_students', 1);
        $this->assertSame($mon->id, $response->json('data.schedules.0.schedule_id'));
    }

    public function test_class_scope_and_dates_cannot_override_another_period(): void
    {
        $year = $this->year(); $other = $this->year(2028);
        $class = $this->schoolClass($year);
        $otherClass = $this->schoolClass($other);
        Sanctum::actingAs($this->user('KEPALA_SEKOLAH'));
        $this->getJson($this->url($year, '2026-11-23', ['class_id' => $otherClass->id]))->assertUnprocessable();
        $this->getJson($this->url($year, '2026-11-23', ['class_id' => $class->id]))->assertOk()
            ->assertJsonPath('meta.class_id', $class->id);
        $this->getJson($this->url($year, '2029-11-23'))->assertUnprocessable();
        $this->getJson($this->url($year, '2026-02-30'))->assertUnprocessable();
    }

    public function test_without_schedules_keeps_zero_denominator_and_no_fake_completion(): void
    {
        $year = $this->year();
        Sanctum::actingAs($this->user('KEPALA_SEKOLAH'));
        $this->getJson($this->url($year))->assertOk()
            ->assertJsonPath('data.summary.scheduled', 0)
            ->assertJsonPath('data.summary.evaluated', 0)
            ->assertJsonCount(0, 'data.schedules');
    }
}
