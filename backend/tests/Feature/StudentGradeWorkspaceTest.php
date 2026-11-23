<?php

namespace Tests\Feature;

use App\Models\AnggotaKelas;
use App\Models\Guru;
use App\Models\JadwalPelajaran;
use App\Models\Kelas;
use App\Models\KomponenNilai;
use App\Models\Mapel;
use App\Models\Nilai;
use App\Models\Role;
use App\Models\Siswa;
use App\Models\TahunAkademik;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StudentGradeWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        $r = Role::firstOrCreate(['code' => $role], ['name' => $role]);
        return User::create([
            'role_id' => $r->id,
            'username' => uniqid('grade-v2-'),
            'name' => 'Demo',
            'email' => uniqid().'@example.test',
            'password' => Hash::make('password'),
            'account_status' => 'active',
        ]);
    }

    private function fixtures(): array
    {
        $studentUser = $this->user('SISWA');
        $student = Siswa::create(['user_id' => $studentUser->id, 'nama_lengkap' => 'Siswa A', 'nisn' => uniqid('nisn')]);
        $otherUser = $this->user('SISWA');
        $other = Siswa::create(['user_id' => $otherUser->id, 'nama_lengkap' => 'Siswa B', 'nisn' => uniqid('nisn')]);
        $teacherUser = $this->user('GURU');
        $teacher = Guru::create(['user_id' => $teacherUser->id, 'nama_lengkap' => 'Guru A', 'nip' => uniqid('nip')]);
        $otherTeacherUser = $this->user('GURU');
        $teacher2 = Guru::create(['user_id' => $otherTeacherUser->id, 'nama_lengkap' => 'Guru B', 'nip' => uniqid('nip')]);
        $year = TahunAkademik::create(['tahun_mulai' => 2026, 'tahun_selesai' => 2027, 'semester' => 'GANJIL', 'is_active' => true]);
        $class = Kelas::create(['tahun_akademik_id' => $year->id, 'nama' => 'IPA 1', 'tingkat' => 10]);
        $foreign = Kelas::create(['tahun_akademik_id' => $year->id, 'nama' => 'IPA 2', 'tingkat' => 10]);
        $mapel = Mapel::create(['kode' => uniqid('MP'), 'nama' => 'Matematika']);
        $schedule = JadwalPelajaran::create([
            'tahun_akademik_id' => $year->id, 'kelas_id' => $class->id, 'mapel_id' => $mapel->id,
            'guru_id' => $teacher->id, 'hari' => 'SELASA', 'jam_mulai' => '10:00', 'jam_selesai' => '11:00',
        ]);
        $schedule2 = JadwalPelajaran::create([
            'tahun_akademik_id' => $year->id, 'kelas_id' => $class->id, 'mapel_id' => $mapel->id,
            'guru_id' => $teacher2->id, 'hari' => 'SABTU', 'jam_mulai' => '08:00', 'jam_selesai' => '09:00',
        ]);
        $otherSchedule = JadwalPelajaran::create([
            'tahun_akademik_id' => $year->id, 'kelas_id' => $foreign->id, 'mapel_id' => $mapel->id,
            'guru_id' => $teacher->id, 'hari' => 'SENIN', 'jam_mulai' => '08:00', 'jam_selesai' => '09:00',
        ]);
        $graded = KomponenNilai::create(['jadwal_pelajaran_id' => $schedule->id, 'nama' => 'UTS', 'bobot' => 30]);
        $ungraded = KomponenNilai::create(['jadwal_pelajaran_id' => $schedule2->id, 'nama' => 'UAS', 'bobot' => 40]);
        $foreignComponent = KomponenNilai::create(['jadwal_pelajaran_id' => $otherSchedule->id, 'nama' => 'Rahasia', 'bobot' => 10]);
        Nilai::create(['komponen_nilai_id' => $graded->id, 'siswa_id' => $student->id, 'nilai' => 0, 'catatan' => 'Belajar lagi']);
        Nilai::create(['komponen_nilai_id' => $graded->id, 'siswa_id' => $other->id, 'nilai' => 100, 'catatan' => 'Nilai siswa B']);
        Nilai::create(['komponen_nilai_id' => $foreignComponent->id, 'siswa_id' => $student->id, 'nilai' => 99]);
        return compact('studentUser', 'student', 'other', 'year', 'class', 'foreign', 'graded', 'ungraded');
    }

    private function member(Siswa $student, Kelas $class, string $start, ?string $end = null): void
    {
        AnggotaKelas::create(['siswa_id' => $student->id, 'kelas_id' => $class->id, 'tanggal_mulai' => $start, 'tanggal_selesai' => $end]);
    }

    public function test_guest_and_non_student_cannot_use_workspace(): void
    {
        $this->getJson('/api/grades/student-workspace')->assertUnauthorized();
        $this->actingAs($this->user('GURU'))->getJson('/api/grades/student-workspace')->assertForbidden();
    }

    public function test_one_subject_two_teachers_and_zero_score_is_assessed_without_peer_leak(): void
    {
        $f = $this->fixtures();
        $this->member($f['student'], $f['class'], today('Asia/Jakarta')->subDays(4)->toDateString());
        $response = $this->actingAs($f['studentUser'])->getJson('/api/grades/student-workspace');
        $response->assertOk()->assertJsonPath('data.summary.subjects', 1)
            ->assertJsonPath('data.summary.components', 2)
            ->assertJsonPath('data.summary.assessed', 1)
            ->assertJsonPath('data.summary.pending', 1)
            ->assertJsonPath('data.subjects.0.components.0.score', 0)
            ->assertJsonPath('data.subjects.0.components.0.assessed', true)
            ->assertJsonCount(2, 'data.subjects.0.teachers')
            ->assertJsonCount(2, 'data.subjects.0.components');
        $this->assertStringNotContainsString('Nilai siswa B', $response->getContent());
        $this->assertStringNotContainsString('Rahasia', $response->getContent());
    }

    public function test_future_membership_cannot_receive_components_or_grades(): void
    {
        $f = $this->fixtures();
        $this->member($f['student'], $f['class'], today('Asia/Jakarta')->addDays(5)->toDateString());
        $this->actingAs($f['studentUser'])->getJson('/api/grades/student-workspace')
            ->assertOk()->assertJsonPath('data.summary.components', 0)->assertJsonCount(0, 'data.subjects');
    }

    public function test_historical_membership_returns_previous_components_even_when_ended(): void
    {
        $f = $this->fixtures();
        $this->member($f['student'], $f['class'], today('Asia/Jakarta')->subMonths(2)->toDateString(), today('Asia/Jakarta')->subMonth()->toDateString());
        $f['year']->update(['is_active' => false]);
        $this->actingAs($f['studentUser'])->getJson('/api/grades/student-workspace?academic_year_id='.$f['year']->id)
            ->assertOk()->assertJsonPath('data.membership_status', 'historical')->assertJsonPath('data.summary.components', 2);
    }

    public function test_multiple_active_classes_fail_closed_and_bad_filter_is_rejected(): void
    {
        $f = $this->fixtures();
        $this->member($f['student'], $f['class'], today('Asia/Jakarta')->subDays(4)->toDateString());
        $this->member($f['student'], $f['foreign'], today('Asia/Jakarta')->subDays(4)->toDateString());
        $this->actingAs($f['studentUser'])->getJson('/api/grades/student-workspace')->assertStatus(409);
        $this->getJson('/api/grades/student-workspace?academic_year_id=invalid')->assertStatus(422);
    }
}
