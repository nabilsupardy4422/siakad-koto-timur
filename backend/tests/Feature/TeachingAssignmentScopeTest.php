<?php

namespace Tests\Feature;

use App\Models\Guru;
use App\Models\JadwalPelajaran;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\Role;
use App\Models\Siswa;
use App\Models\AnggotaKelas;
use App\Models\TahunAkademik;
use App\Models\User;
use App\Models\WaliKelas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeachingAssignmentScopeTest extends TestCase
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
        Mapel $subject,
        Guru $guru,
        string $day = 'SENIN',
        string $start = '07:00',
        string $end = '08:00'
    ): JadwalPelajaran {
        return JadwalPelajaran::create([
            'tahun_akademik_id' => $academicYear->id,
            'kelas_id' => $class->id,
            'mapel_id' => $subject->id,
            'guru_id' => $guru->id,
            'hari' => $day,
            'jam_mulai' => $start,
            'jam_selesai' => $end,
        ]);
    }

    private function createWaliAssignment(
        Guru $guru,
        Kelas $class,
        TahunAkademik $academicYear
    ): WaliKelas {
        return WaliKelas::create([
            'guru_id' => $guru->id,
            'kelas_id' => $class->id,
            'tahun_akademik_id' => $academicYear->id,
            'tanggal_mulai' => '2026-07-01',
            'tanggal_selesai' => null,
        ]);
    }

    private function addStudentToClass(
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

    private function scenario(): array
    {
        [$user, $guru] = $this->createTeacher();
        [, $otherGuru] = $this->createTeacher();
        $year = $this->createAcademicYear(2029, 2030, 'GENAP');
        $oldYear = $this->createAcademicYear(2028, 2029, 'GENAP', false);
        $class = $this->createClass($year, 'IPA 1', 12);
        $otherClass = $this->createClass($year, 'IPA 1', 10);
        $oldClass = $this->createClass($oldYear, 'IPA 1', 12);
        $subject = $this->createSubject();
        $assignment = $this->createWaliAssignment($guru, $class, $year);
        $own1 = $this->createSchedule($year, $class, $subject, $guru, 'Senin');
        $own2 = $this->createSchedule($year, $otherClass, $subject, $guru, 'Selasa');
        $peer = $this->createSchedule($year, $class, $subject, $otherGuru, 'Rabu');
        $outside = $this->createSchedule($year, $otherClass, $subject, $otherGuru, 'Kamis');
        $old = $this->createSchedule($oldYear, $oldClass, $subject, $guru, 'Jumat');
        return compact('user', 'guru', 'otherGuru', 'year', 'oldYear', 'class', 'otherClass', 'assignment', 'own1', 'own2', 'peer', 'outside', 'old');
    }

    public function test_teaching_list_and_detail_only_allow_own_schedules_in_active_period(): void
    {
        $s = $this->scenario();
        Sanctum::actingAs($s['user']);
        $response = $this->getJson('/api/schedules?scope=teaching&academic_year_id='.$s['oldYear']->id)
            ->assertOk()->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing([$s['own1']->id, $s['own2']->id], array_column($response->json('data'), 'id'));
        $this->getJson('/api/schedules/'.$s['own1']->id.'?scope=teaching')->assertOk();
        foreach (['peer', 'outside', 'old'] as $key) {
            $this->getJson('/api/schedules/'.$s[$key]->id.'?scope=teaching')->assertForbidden();
        }
        $this->getJson('/api/schedules?scope=teaching&teacher_id='.$s['otherGuru']->id)
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_homeroom_scope_is_read_only_and_limited_to_assigned_class(): void
    {
        $s = $this->scenario();
        Sanctum::actingAs($s['user']);
        $response = $this->getJson('/api/schedules?scope=homeroom')->assertOk()->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing([$s['own1']->id, $s['peer']->id], array_column($response->json('data'), 'id'));
        $this->getJson('/api/schedules/'.$s['peer']->id.'?scope=homeroom')->assertOk();
        $this->getJson('/api/schedules/'.$s['own2']->id.'?scope=homeroom')->assertForbidden();
        $this->getJson('/api/schedules/'.$s['outside']->id.'?scope=homeroom')->assertForbidden();
        $this->putJson('/api/schedules/'.$s['peer']->id.'?scope=homeroom', [])->assertForbidden();
        $s['assignment']->update(['tanggal_selesai' => '2026-08-01']);
        $this->getJson('/api/schedules?scope=homeroom')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/schedules/'.$s['peer']->id.'?scope=homeroom')->assertForbidden();
    }

    public function test_legacy_read_and_pagination_remain_compatible(): void
    {
        $s = $this->scenario();
        Sanctum::actingAs($s['user']);
        $this->getJson('/api/schedules')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/schedules/'.$s['peer']->id)->assertOk();
        $this->getJson('/api/schedules?scope=teaching&per_page=1&page=2')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)->assertJsonPath('meta.current_page', 2);
        $this->getJson('/api/schedules?scope=invalid')->assertUnprocessable();
        $this->getJson('/api/schedules/'.$s['own1']->id.'?scope=invalid')->assertUnprocessable();
    }

    public function test_other_roles_keep_existing_context(): void
    {
        $s = $this->scenario();
        Sanctum::actingAs($this->createUser('TU'));
        $this->getJson('/api/schedules?academic_year_id='.$s['year']->id)->assertOk()->assertJsonCount(4, 'data');
        Sanctum::actingAs($this->createUser('KEPALA_SEKOLAH'));
        $this->getJson('/api/schedules?academic_year_id='.$s['year']->id)->assertOk()->assertJsonCount(4, 'data');
        [$studentUser, $student] = $this->createStudent();
        $this->addStudentToClass($student, $s['class']);
        Sanctum::actingAs($studentUser);
        $this->getJson('/api/schedules')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/schedules?scope=teaching')->assertForbidden();
    }
}
