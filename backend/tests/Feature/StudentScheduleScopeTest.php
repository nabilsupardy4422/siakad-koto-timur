<?php

namespace Tests\Feature;

use App\Models\AnggotaKelas;
use App\Models\Guru;
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

class StudentScheduleScopeTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        $roleModel = Role::firstOrCreate(['code' => $role], ['name' => $role]);
        return User::create([
            'role_id' => $roleModel->id,
            'username' => 'scope-'.$role.'-'.uniqid(),
            'name' => 'User',
            'email' => uniqid().'@example.test',
            'password' => Hash::make('password'),
            'account_status' => 'active',
        ]);
    }

    private function fixture(): array
    {
        $user = $this->user('SISWA');
        $student = Siswa::create(['user_id' => $user->id, 'nama_lengkap' => 'Siswa Aktif', 'nisn' => uniqid('NISN-')]);
        $year = TahunAkademik::create(['tahun_mulai' => 2029, 'tahun_selesai' => 2030, 'semester' => 'GENAP', 'is_active' => true]);
        $class = Kelas::create(['tahun_akademik_id' => $year->id, 'nama' => 'IPA 1', 'tingkat' => 12]);
        $otherClass = Kelas::create(['tahun_akademik_id' => $year->id, 'nama' => 'IPS 1', 'tingkat' => 12]);
        $teacherUser = $this->user('GURU');
        $teacher = Guru::create(['user_id' => $teacherUser->id, 'nip' => uniqid('NIP-'), 'nama_lengkap' => 'Pak Pengampu']);
        $subject = Mapel::create(['kode' => uniqid('MAP-'), 'nama' => 'Matematika']);
        $ownSchedule = JadwalPelajaran::create([
            'tahun_akademik_id' => $year->id, 'kelas_id' => $class->id, 'mapel_id' => $subject->id,
            'guru_id' => $teacher->id, 'hari' => 'SELASA', 'jam_mulai' => '09:00', 'jam_selesai' => '11:00',
        ]);
        $foreignSchedule = JadwalPelajaran::create([
            'tahun_akademik_id' => $year->id, 'kelas_id' => $otherClass->id, 'mapel_id' => $subject->id,
            'guru_id' => $teacher->id, 'hari' => 'RABU', 'jam_mulai' => '08:00', 'jam_selesai' => '09:00',
        ]);
        return compact('user', 'student', 'year', 'class', 'otherClass', 'teacher', 'subject', 'ownSchedule', 'foreignSchedule');
    }

    private function member(Siswa $student, Kelas $class, string $start, ?string $end = null): AnggotaKelas
    {
        return AnggotaKelas::create(['kelas_id' => $class->id, 'siswa_id' => $student->id, 'tanggal_mulai' => $start, 'tanggal_selesai' => $end]);
    }

    public function test_active_student_only_sees_own_class_with_exact_period_teacher_and_times(): void
    {
        $f = $this->fixture();
        $this->member($f['student'], $f['class'], today('Asia/Jakarta')->subDay()->toDateString());
        $response = $this->actingAs($f['user'])->getJson('/api/schedules?per_page=1&page=1')->assertOk()
            ->assertJsonPath('meta.student_context.status', 'active')
            ->assertJsonPath('meta.student_context.class.nama', 'IPA 1')
            ->assertJsonPath('meta.student_context.academic_year.semester', 'GENAP')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $f['ownSchedule']->id)
            ->assertJsonPath('data.0.hari', 'SELASA')
            ->assertJsonPath('data.0.guru.nama_lengkap', 'Pak Pengampu')
            ->assertJsonPath('data.0.mapel.nama', 'Matematika');
        $this->assertStringStartsWith('09:00', $response->json('data.0.jam_mulai'));
        $this->assertStringStartsWith('11:00', $response->json('data.0.jam_selesai'));
        $this->getJson('/api/schedules/'.$f['ownSchedule']->id)->assertOk();
        $this->getJson('/api/schedules/'.$f['foreignSchedule']->id)->assertForbidden();
        $this->getJson('/api/schedules?class_id='.$f['otherClass']->id)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_future_membership_cannot_see_list_or_direct_schedule(): void
    {
        $f = $this->fixture();
        $starts = today('Asia/Jakarta')->addDays(4)->toDateString();
        $this->member($f['student'], $f['class'], $starts);
        $this->actingAs($f['user'])->getJson('/api/schedules')
            ->assertOk()->assertJsonPath('meta.student_context.status', 'scheduled')
            ->assertJsonPath('meta.student_context.starts_on', $starts)
            ->assertJsonCount(0, 'data');
        $this->getJson('/api/schedules/'.$f['ownSchedule']->id)->assertForbidden();
    }

    public function test_end_date_is_inclusive_but_next_day_denied(): void
    {
        $f = $this->fixture();
        $this->member($f['student'], $f['class'], today('Asia/Jakarta')->subWeek()->toDateString(), today('Asia/Jakarta')->toDateString());
        $this->actingAs($f['user'])->getJson('/api/schedules')->assertJsonPath('meta.student_context.status', 'active');
        $this->travel(1)->days();
        $this->getJson('/api/schedules')->assertJsonPath('meta.student_context.status', 'ended')->assertJsonCount(0, 'data');
        $this->getJson('/api/schedules/'.$f['ownSchedule']->id)->assertForbidden();
    }

    public function test_multiple_active_memberships_fail_closed(): void
    {
        $f = $this->fixture();
        $this->member($f['student'], $f['class'], today('Asia/Jakarta')->subDays(5)->toDateString());
        $this->member($f['student'], $f['otherClass'], today('Asia/Jakarta')->subDays(4)->toDateString());
        $this->actingAs($f['user'])->getJson('/api/schedules')->assertStatus(409);
        $this->getJson('/api/schedules/'.$f['ownSchedule']->id)->assertStatus(409);
    }

    public function test_prior_period_membership_does_not_open_active_year_schedule(): void
    {
        $f = $this->fixture();
        $prior = TahunAkademik::create(['tahun_mulai' => 2028, 'tahun_selesai' => 2029, 'semester' => 'GENAP', 'is_active' => false]);
        $priorClass = Kelas::create(['tahun_akademik_id' => $prior->id, 'nama' => 'IPA 1', 'tingkat' => 11]);
        $this->member($f['student'], $priorClass, today('Asia/Jakarta')->subDays(6)->toDateString());
        $this->actingAs($f['user'])->getJson('/api/schedules')
            ->assertOk()->assertJsonPath('meta.student_context.status', 'unassigned')->assertJsonCount(0, 'data');
        $this->getJson('/api/schedules/'.$f['ownSchedule']->id)->assertForbidden();
    }

    public function test_no_single_active_period_prevents_schedule_access(): void
    {
        $f = $this->fixture();
        $this->member($f['student'], $f['class'], today('Asia/Jakarta')->subDay()->toDateString());
        TahunAkademik::create(['tahun_mulai' => 2030, 'tahun_selesai' => 2031, 'semester' => 'GANJIL', 'is_active' => true]);
        $this->actingAs($f['user'])->getJson('/api/schedules')->assertStatus(409);
        $this->getJson('/api/schedules/'.$f['ownSchedule']->id)->assertStatus(409);
    }
}
