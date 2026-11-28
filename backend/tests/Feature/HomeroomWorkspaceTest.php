<?php

namespace Tests\Feature;

use App\Models\AnggotaKelas;
use App\Models\Presensi;
use App\Models\Guru;
use App\Models\JadwalPelajaran;
use App\Models\Kelas;
use App\Models\KomponenNilai;
use App\Models\Mapel;
use App\Models\Role;
use App\Models\Siswa;
use App\Models\TahunAkademik;
use App\Models\User;
use App\Models\WaliKelas;
use App\Models\Nilai;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HomeroomWorkspaceTest extends TestCase
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
            'tanggal_mulai' => today('Asia/Jakarta')->subDay()->toDateString(),
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

    public function test_monitor_only_assigned_class_in_active_period(): void
    {
        // UTC is still Nov 26 while the school's calendar is already Nov 27.
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-26 18:00:00', 'UTC'));
        $this->assertSame('2026-11-26', today('UTC')->toDateString());
        $this->assertSame('2026-11-27', today('Asia/Jakarta')->toDateString());
        [$user, $guru] = $this->createTeacher();
        [, $peer] = $this->createTeacher();
        [, $student] = $this->createStudent();
        $year = $this->createAcademicYear();
        $class = $this->createClass($year);
        $outside = $this->createClass($year, 'Other');
        $this->createMembership($student, $class);
        $otherSchedule = $this->createSchedule($year, $outside, $guru);
        $schedule = $this->createSchedule($year, $class, $peer);
        $component = $this->createComponent($schedule);
        Nilai::create(['komponen_nilai_id'=>$component->id,'siswa_id'=>$student->id,'nilai'=>85]);
        Presensi::create(['jadwal_pelajaran_id'=>$schedule->id,'siswa_id'=>$student->id,'tanggal'=>today('Asia/Jakarta')->toDateString(),'status'=>'HADIR']);
        $assignment = WaliKelas::create(['guru_id'=>$guru->id,'kelas_id'=>$class->id,'tahun_akademik_id'=>$year->id,'tanggal_mulai'=>today('Asia/Jakarta')->subDay()->toDateString(),'tanggal_selesai'=>null]);
        Sanctum::actingAs($user);
        $suffix = '?scope=homeroom&academic_year_id='.$year->id;
        $this->getJson('/api/homeroom-assignments'.$suffix)->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$assignment->id);
        $this->getJson('/api/classes/'.$class->id.'/students'.$suffix)->assertOk()->assertJsonPath('data.0.siswa.nis',$student->nis);
        $this->getJson('/api/attendance/recap'.$suffix.'&class_id='.$class->id)->assertOk()->assertJsonPath('data.hadir',1);
        $this->getJson('/api/grades/recap'.$suffix.'&class_id='.$class->id)->assertOk()->assertJsonPath('data.total',1)->assertJsonPath('data.rata_rata',85);
        $this->getJson('/api/classes/'.$outside->id.'/students'.$suffix)->assertForbidden();
        $this->getJson('/api/attendance/recap'.$suffix.'&class_id='.$outside->id)->assertForbidden();
        $this->getJson('/api/grades/recap'.$suffix.'&class_id='.$outside->id)->assertForbidden();
        // Teaching access is independent of the explicit homeroom scope.
        $this->getJson('/api/classes/'.$outside->id.'/students')->assertOk();
        foreach ([['tanggal_mulai'=>today('Asia/Jakarta')->addDay()->toDateString()],['tanggal_mulai'=>today('Asia/Jakarta')->subDay()->toDateString(),'tanggal_selesai'=>today('Asia/Jakarta')->toDateString()]] as $change) {
            $assignment->update($change);
            $this->getJson('/api/homeroom-assignments'.$suffix)->assertOk()->assertJsonCount(0,'data');
            $this->getJson('/api/classes/'.$class->id.'/students'.$suffix)->assertForbidden();
            $this->getJson('/api/attendance/recap'.$suffix.'&class_id='.$class->id)->assertForbidden();
            $this->getJson('/api/grades/recap'.$suffix.'&class_id='.$class->id)->assertForbidden();
            $this->getJson('/api/grades?scope=homeroom')->assertOk()->assertJsonCount(0,'data');
        }
    }

    public function test_inactive_year_and_other_roles_cannot_claim_homeroom_scope(): void
    {
        [$user,$guru]=$this->createTeacher();
        $year=$this->createAcademicYear(2025,2026,'GANJIL',false);
        $active=$this->createAcademicYear();
        $class=$this->createClass($year);
        WaliKelas::create(['guru_id'=>$guru->id,'kelas_id'=>$class->id,'tahun_akademik_id'=>$year->id,'tanggal_mulai'=>today('Asia/Jakarta')->subDay()->toDateString(),'tanggal_selesai'=>null]);
        Sanctum::actingAs($user);
        $suffix='?scope=homeroom&academic_year_id='.$year->id;
        $this->getJson('/api/homeroom-assignments'.$suffix)->assertOk()->assertJsonCount(0,'data');
        $this->getJson('/api/classes/'.$class->id.'/students'.$suffix)->assertForbidden();
        $this->getJson('/api/grades/recap'.$suffix.'&class_id='.$class->id)->assertForbidden();
        $this->getJson('/api/attendance/recap'.$suffix.'&class_id='.$class->id)->assertForbidden();
        Sanctum::actingAs($this->createUser('TU'));
        $this->getJson('/api/classes/'.$class->id.'/students'.$suffix)->assertForbidden();
        $this->getJson('/api/homeroom-assignments'.$suffix)->assertForbidden();
        $this->getJson('/api/attendance/recap'.$suffix)->assertForbidden();
        $this->getJson('/api/classes/'.$class->id.'/students')->assertOk();
    }
    public function test_teacher_dashboard_and_homeroom_recap_exclude_future_and_foreign_class(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-26 18:00:00','UTC'));
        [$user,$guru]=$this->createTeacher();[, $peer]=$this->createTeacher();[, $student]=$this->createStudent();
        $year=$this->createAcademicYear();$class=$this->createClass($year);$outside=$this->createClass($year,'Other');
        $this->createMembership($student,$class);$this->createMembership($student,$outside);
        $own=$this->createSchedule($year,$class,$peer);$foreign=$this->createSchedule($year,$outside,$guru);
        foreach([[$own,'2026-11-27'],[$own,'2026-11-28'],[$foreign,'2026-11-27']] as [$schedule,$date]) Presensi::create(['jadwal_pelajaran_id'=>$schedule->id,'siswa_id'=>$student->id,'tanggal'=>$date,'status'=>'HADIR']);
        $c=$this->createComponent($own);$other=$this->createComponent($foreign);
        Nilai::create(['komponen_nilai_id'=>$c->id,'siswa_id'=>$student->id,'nilai'=>0]);
        Nilai::create(['komponen_nilai_id'=>$other->id,'siswa_id'=>$student->id,'nilai'=>90]);
        WaliKelas::create(['guru_id'=>$guru->id,'kelas_id'=>$class->id,'tahun_akademik_id'=>$year->id,'tanggal_mulai'=>'2026-11-26','tanggal_selesai'=>null]);
        Sanctum::actingAs($user);$suffix='?scope=homeroom&academic_year_id='.$year->id.'&class_id='.$class->id;
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.wali_kelas.total_students',1)->assertJsonPath('data.wali_kelas.attendance_records',1)->assertJsonPath('data.wali_kelas.graded_records',1);
        $this->getJson('/api/attendance/recap'.$suffix)->assertOk()->assertJsonPath('data.total',1)->assertJsonPath('data.hadir',1);
        $this->getJson('/api/grades/recap'.$suffix)->assertOk()->assertJsonPath('data.total',1)->assertJsonPath('data.rata_rata',0);
        $this->assertDatabaseCount('presensi',3);$this->assertDatabaseCount('nilai',2);
    }
}
