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
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PrincipalAttendanceInsightsTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void { parent::setUp(); \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2027-04-01 10:00:00','Asia/Jakarta')); }
    protected function tearDown(): void { \Illuminate\Support\Carbon::setTestNow(); parent::tearDown(); }

    private function user(string $role): User
    {
        $entity = Role::firstOrCreate(['code' => $role], ['name' => $role]);
        return User::create([
            'role_id' => $entity->id,
            'username' => $role . uniqid(),
            'name' => $role,
            'email' => uniqid() . '@example.test',
            'password' => Hash::make('password'),
            'account_status' => 'active',
        ]);
    }

    private function year(string $semester, bool $active = true): TahunAkademik
    {
        return TahunAkademik::create([
            'tahun_mulai' => 2026,
            'tahun_selesai' => 2027,
            'semester' => $semester,
            'is_active' => $active,
        ]);
    }

    private function schedule(TahunAkademik $year, string $subject, Siswa $student, ?Kelas $existingClass = null): JadwalPelajaran
    {
        $teacherUser = $this->user('GURU');
        $teacher = Guru::create([
            'user_id' => $teacherUser->id,
            'nip' => uniqid('NIP'),
            'nama_lengkap' => 'Guru Contoh',
            'jenis_kelamin' => 'L',
            'no_telepon' => '08123456789',
            'alamat' => 'Alamat',
        ]);
        $class = $existingClass ?? Kelas::create([
            'tahun_akademik_id' => $year->id,
            'nama' => 'IPA 1',
            'tingkat' => 10,
        ]);
        $mapel = Mapel::create(['nama' => $subject, 'kode' => uniqid('MP')]);
        AnggotaKelas::firstOrCreate(
            ['kelas_id' => $class->id, 'siswa_id' => $student->id],
            ['tanggal_mulai' => '2026-07-01', 'tanggal_selesai' => null]
        );
        return JadwalPelajaran::create([
            'tahun_akademik_id' => $year->id,
            'kelas_id' => $class->id,
            'mapel_id' => $mapel->id,
            'guru_id' => $teacher->id,
            'hari' => 'Senin',
            'jam_mulai' => '07:00',
            'jam_selesai' => '08:00',
        ]);
    }

    private function student(): Siswa
    {
        $user = $this->user('SISWA');
        return Siswa::create([
            'user_id' => $user->id,
            'nisn' => uniqid('NISN'),
            'nis' => uniqid('NIS'),
            'nama_lengkap' => 'Siswa Contoh',
            'jenis_kelamin' => 'P',
            'tanggal_lahir' => '2010-01-01',
            'tempat_lahir' => 'Padang',
            'no_telepon' => '08123456789',
            'alamat' => 'Alamat',
        ]);
    }

    private function record(JadwalPelajaran $schedule, Siswa $student, string $date, string $status): void
    {
        Presensi::create([
            'jadwal_pelajaran_id' => $schedule->id,
            'siswa_id' => $student->id,
            'tanggal' => $date,
            'status' => $status,
        ]);
    }

    public function test_only_headmaster_can_read_insights(): void
    {
        $year = $this->year('GANJIL');
        $url = '/api/attendance/principal-insights?academic_year_id=' . $year->id . '&scope=SEMESTER';
        $this->getJson($url)->assertUnauthorized();
        foreach (['TU', 'GURU', 'SISWA'] as $role) {
            Sanctum::actingAs($this->user($role));
            $this->getJson($url)->assertForbidden();
        }
        Sanctum::actingAs($this->user('KEPALA_SEKOLAH'));
        $this->getJson($url)->assertOk()->assertJsonPath('meta.unit', 'stored_student_meeting_records');
    }

    public function test_counts_are_per_meeting_and_break_down_by_subject(): void
    {
        $year = $this->year('GANJIL');
        $student = $this->student();
        $math = $this->schedule($year, 'Matematika', $student);
        $language = $this->schedule($year, 'Bahasa Indonesia', $student, $math->kelas);
        $this->record($math, $student, '2026-11-23', 'HADIR');
        $this->record($language, $student, '2026-11-23', 'IZIN');
        $this->record($math, $student, '2026-11-24', 'HADIR');
        Sanctum::actingAs($this->user('KEPALA_SEKOLAH'));
        $response = $this->getJson('/api/attendance/principal-insights?' . http_build_query([
            'academic_year_id' => $year->id, 'scope' => 'SEMESTER',
            'date_from' => '2026-11-23', 'date_to' => '2026-11-23',
        ]))->assertOk()->assertJsonPath('data.totals.total', 2)
            ->assertJsonPath('data.totals.hadir', 1)
            ->assertJsonPath('data.totals.izin', 1);
        $this->assertCount(1, $response->json('data.classes'));
        $this->assertCount(2, $response->json('data.classes.0.subjects'));
        $this->assertSame(2, collect($response->json('data.classes'))->sum('totals.total'));
    }

    public function test_full_year_includes_both_registered_semesters_only(): void
    {
        $ganjil = $this->year('GANJIL');
        $genap = $this->year('GENAP', false);
        $student = $this->student();
        $this->record($this->schedule($ganjil, 'Kimia', $student), $student, '2026-11-23', 'HADIR');
        $this->record($this->schedule($genap, 'Fisika', $student), $student, '2027-03-12', 'SAKIT');
        Sanctum::actingAs($this->user('KEPALA_SEKOLAH'));
        $this->getJson('/api/attendance/principal-insights?' . http_build_query([
            'academic_year_id' => $ganjil->id, 'scope' => 'YEAR',
        ]))->assertOk()->assertJsonPath('data.totals.total', 2)
            ->assertJsonPath('data.totals.sakit', 1)
            ->assertJsonCount(2, 'meta.periods');
    }

    public function test_rejects_invalid_dates_and_year_scope_class_override(): void
    {
        $year = $this->year('GANJIL');
        Sanctum::actingAs($this->user('KEPALA_SEKOLAH'));
        $url = '/api/attendance/principal-insights?academic_year_id=' . $year->id;
        $this->getJson($url . '&scope=SEMESTER&date_from=2026-11-23')->assertUnprocessable();
        $this->getJson($url . '&scope=SEMESTER&date_from=2026-11-24&date_to=2026-11-23')->assertUnprocessable();
        $this->getJson($url . '&scope=YEAR&class_id=1')->assertUnprocessable();
        $this->getJson($url . '&scope=WRONG')->assertUnprocessable();
    }

    public function test_record_outside_student_membership_is_ignored(): void
    {
        $year = $this->year('GANJIL');
        $student = $this->student();
        $schedule = $this->schedule($year, 'Geografi', $student);
        $this->record($schedule, $student, '2026-06-29', 'ALPA');
        Sanctum::actingAs($this->user('KEPALA_SEKOLAH'));
        $this->getJson('/api/attendance/principal-insights?academic_year_id=' . $year->id . '&scope=SEMESTER')
            ->assertOk()->assertJsonPath('data.totals.total', 0);
    }
}
