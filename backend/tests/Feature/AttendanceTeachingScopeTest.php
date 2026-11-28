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
use App\Models\User;
use App\Models\WaliKelas;
use App\Models\TahunAkademik;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;


class AttendanceTeachingScopeTest extends TestCase
{
    use RefreshDatabase;

    private function createRole(string $code, string $name): Role
    {
        return Role::create([
            'code' => $code,
            'name' => $name,
        ]);
    }

    private function createUser(string $roleCode): User
    {
        $role = Role::where('code', $roleCode)->first();

        if ($role === null) {
            $role = $this->createRole(
                $roleCode,
                match ($roleCode) {
                    'TU' => 'Tata Usaha',
                    'KEPALA_SEKOLAH' => 'Kepala Sekolah',
                    'GURU' => 'Guru',
                    'SISWA' => 'Siswa',
                    default => $roleCode,
                }
            );
        }

        return User::create([
            'role_id' => $role->id,
            'username' => strtolower($roleCode) . '_' . uniqid(),
            'name' => 'User ' . $roleCode,
            'email' => strtolower($roleCode) . '_' . uniqid() . '@example.test',
            'password' => Hash::make('password'),
            'account_status' => 'active',
        ]);
    }

    private function createAcademicYear(
        array $overrides = []
    ): TahunAkademik {
        return TahunAkademik::create(array_merge([
            'tahun_mulai' => 2026,
            'tahun_selesai' => 2027,
            'semester' => 'GANJIL',
            'is_active' => true,
        ], $overrides));
    }

    private function createClass(
        TahunAkademik $academicYear,
        array $overrides = []
    ): Kelas {
        return Kelas::create(array_merge([
            'tahun_akademik_id' => $academicYear->id,
            'nama' => 'X IPA ' . uniqid(),
            'tingkat' => 10,
        ], $overrides));
    }

    private function createTeacher(
        array $overrides = [],
        ?User $user = null
    ): Guru {
        $user ??= $this->createUser('GURU');

        return Guru::create(array_merge([
            'user_id' => $user->id,
            'nip' => 'NIP-' . uniqid(),
            'nama_lengkap' => 'Guru Test',
            'jenis_kelamin' => 'L',
            'no_telepon' => '08123456789',
            'alamat' => 'Alamat Guru',
        ], $overrides));
    }

    private function createStudent(
        array $overrides = [],
        ?User $user = null
    ): Siswa {
        $user ??= $this->createUser('SISWA');

        return Siswa::create(array_merge([
            'user_id' => $user->id,
            'nisn' => 'NISN-' . uniqid(),
            'nis' => 'NIS-' . uniqid(),
            'nama_lengkap' => 'Siswa Test',
            'jenis_kelamin' => 'L',
            'tanggal_lahir' => '2010-01-01',
            'tempat_lahir' => 'Padang',
            'no_telepon' => '08123456789',
            'alamat' => 'Alamat Siswa',
        ], $overrides));
    }

    private function addStudentToClass(
        Siswa $student,
        Kelas $class,
        string $tanggalMulai = '2026-07-01',
        ?string $tanggalSelesai = null
    ): AnggotaKelas {
        return AnggotaKelas::create([
            'siswa_id' => $student->id,
            'kelas_id' => $class->id,
            'tanggal_mulai' => $tanggalMulai,
            'tanggal_selesai' => $tanggalSelesai,
        ]);
    }

    private function createSchedule(
        Guru $teacher,
        Kelas $class,
        TahunAkademik $academicYear
    ): JadwalPelajaran {
        $mapel = Mapel::create([
            'nama' => 'Mapel Test ' . uniqid(),
            'kode' => 'MAPEL-' . uniqid(),
        ]);

        return JadwalPelajaran::create([
            'tahun_akademik_id' => $academicYear->id,
            'kelas_id' => $class->id,
            'mapel_id' => $mapel->id,
            'guru_id' => $teacher->id,
            'hari' => 'Senin',
            'jam_mulai' => '07:00',
            'jam_selesai' => '08:30',
        ]);
    }

    private function createTeachingContext(): array
    {
        $teacher = $this->createTeacher();

        $academicYear = $this->createAcademicYear();

        $class = $this->createClass($academicYear);

        $student = $this->createStudent();

        $this->addStudentToClass(
            $student,
            $class
        );

        $schedule = $this->createSchedule(
            $teacher,
            $class,
            $academicYear
        );

        return [
            'teacher' => $teacher,
            'user' => $teacher->user,
            'academicYear' => $academicYear,
            'class' => $class,
            'student' => $student,
            'schedule' => $schedule,
        ];
    }

    public function test_roster_is_date_specific_and_paginated(): void
    {
        $c = $this->createTeachingContext();
        $ended = $this->createStudent();
        $this->addStudentToClass($ended, $c['class'], '2026-07-01', '2026-10-19');
        $future = $this->createStudent();
        $this->addStudentToClass($future, $c['class'], '2026-10-21');
        $boundary = $this->createStudent();
        $this->addStudentToClass($boundary, $c['class'], '2026-10-20', '2026-10-20');
        $outside = $this->createStudent();
        $this->addStudentToClass($outside, $this->createClass($c['academicYear']));
        Sanctum::actingAs($c['user']);
        $url = '/api/attendance/roster?schedule_id='.$c['schedule']->id.'&date=2026-10-20&per_page=1';
        $first = $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.class_id', $c['class']->id)->assertJsonPath('meta.academic_year_id', $c['academicYear']->id);
        $second = $this->getJson($url.'&page=2')->assertOk()->assertJsonCount(1, 'data');
        $this->assertEqualsCanonicalizing([$c['student']->id, $boundary->id], [$first->json('data.0.id'), $second->json('data.0.id')]);
        $this->getJson('/api/attendance/roster?schedule_id='.$c['schedule']->id.'&date=2026-02-30')->assertUnprocessable();
    }

    public function test_homeroom_cannot_manage_peer_but_can_monitor(): void
    {
        $c = $this->createTeachingContext();
        $other = $this->createSchedule($this->createTeacher(), $c['class'], $c['academicYear']);
        WaliKelas::create(['guru_id'=>$c['teacher']->id, 'kelas_id'=>$c['class']->id, 'tahun_akademik_id'=>$c['academicYear']->id, 'tanggal_mulai'=>'2026-07-01']);
        $own = Presensi::create(['jadwal_pelajaran_id'=>$c['schedule']->id, 'siswa_id'=>$c['student']->id, 'tanggal'=>'2026-10-20', 'status'=>'HADIR']);
        $peer = Presensi::create(['jadwal_pelajaran_id'=>$other->id, 'siswa_id'=>$c['student']->id, 'tanggal'=>'2026-10-20', 'status'=>'IZIN']);
        Sanctum::actingAs($c['user']);
        $this->getJson('/api/attendance?scope=teaching')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$own->id);
        $this->getJson('/api/attendance?scope=teaching&schedule_id='.$other->id)->assertOk()->assertJsonCount(0,'data');
        $this->getJson('/api/attendance?scope=homeroom')->assertOk()->assertJsonCount(2,'data');
        $this->getJson('/api/attendance/recap?scope=teaching')->assertOk()->assertJsonPath('data.total',1);
        $this->getJson('/api/attendance/recap?scope=homeroom')->assertOk()->assertJsonPath('data.total',2);
        $this->getJson('/api/attendance/roster?schedule_id='.$other->id.'&date=2026-10-20')->assertForbidden();
        $this->postJson('/api/attendance/bulk',['jadwal_pelajaran_id'=>$other->id,'tanggal'=>'2026-10-20','presensi'=>[['siswa_id'=>$c['student']->id,'status'=>'ALPA']]])->assertForbidden();
        $this->postJson('/api/attendance',['jadwal_pelajaran_id'=>$other->id,'siswa_id'=>$c['student']->id,'tanggal'=>'2026-10-20','status'=>'ALPA'])->assertForbidden();
        $this->putJson('/api/attendance/'.$peer->id,['status'=>'ALPA'])->assertForbidden();
        $this->assertSame('IZIN',$peer->fresh()->status);
        $this->getJson('/api/attendance?scope=unknown')->assertUnprocessable();
    }

    public function test_inactive_period_rejects_all_writes_and_roster(): void
    {
        $c = $this->createTeachingContext();
        $record = Presensi::create(['jadwal_pelajaran_id'=>$c['schedule']->id,'siswa_id'=>$c['student']->id,'tanggal'=>'2026-10-20','status'=>'HADIR']);
        $c['academicYear']->update(['is_active'=>false]);
        $this->createAcademicYear(['tahun_mulai'=>2027,'tahun_selesai'=>2028]);
        Sanctum::actingAs($c['user']);
        $this->getJson('/api/attendance?scope=teaching')->assertOk()->assertJsonCount(0,'data');
        $this->getJson('/api/attendance/roster?schedule_id='.$c['schedule']->id.'&date=2026-10-20')->assertForbidden();
        $this->postJson('/api/attendance',['jadwal_pelajaran_id'=>$c['schedule']->id,'siswa_id'=>$c['student']->id,'tanggal'=>'2026-10-21','status'=>'HADIR'])->assertForbidden();
        $this->postJson('/api/attendance/bulk',['jadwal_pelajaran_id'=>$c['schedule']->id,'tanggal'=>'2026-10-20','presensi'=>[['siswa_id'=>$c['student']->id,'status'=>'ALPA']]])->assertForbidden();
        $this->putJson('/api/attendance/'.$record->id,['status'=>'ALPA'])->assertForbidden();
        $this->assertSame('HADIR',$record->fresh()->status);
    }

    public function test_bulk_save_is_repeatable_and_rejects_ended_membership_atomically(): void
    {
        $c = $this->createTeachingContext();
        $ended = $this->createStudent();
        $this->addStudentToClass($ended,$c['class'],'2026-07-01','2026-10-19');
        Sanctum::actingAs($c['user']);
        $payload = ['jadwal_pelajaran_id'=>$c['schedule']->id,'tanggal'=>'2026-10-20','presensi'=>[['siswa_id'=>$c['student']->id,'status'=>'HADIR']]];
        $invalid = $payload;
        $invalid['presensi'][] = ['siswa_id'=>$ended->id,'status'=>'HADIR'];
        $this->postJson('/api/attendance/bulk',$invalid)->assertUnprocessable();
        $this->assertDatabaseCount('presensi',0);
        $this->postJson('/api/attendance/bulk',$payload)->assertOk()->assertJsonPath('data.created',1);
        $payload['presensi'][0]['status']='SAKIT';
        $this->postJson('/api/attendance/bulk',$payload)->assertOk()->assertJsonPath('data.updated',1);
        $this->assertDatabaseCount('presensi',1);
        $this->assertDatabaseHas('presensi',['siswa_id'=>$c['student']->id,'status'=>'SAKIT']);
    }

    public function test_roster_is_not_available_to_non_teachers(): void
    {
        $c = $this->createTeachingContext();
        $url = '/api/attendance/roster?schedule_id='.$c['schedule']->id.'&date=2026-10-20';
        $this->getJson($url)->assertUnauthorized();
        foreach (['TU','KEPALA_SEKOLAH','SISWA'] as $role) {
            Sanctum::actingAs($this->createUser($role));
            $this->getJson($url)->assertForbidden();
        }
    }
    public function test_future_attendance_is_rejected_in_all_write_paths_and_wib_boundary(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-26 18:00:00','UTC'));
        $c=$this->createTeachingContext(); Sanctum::actingAs($c['user']);
        $payload=['jadwal_pelajaran_id'=>$c['schedule']->id,'siswa_id'=>$c['student']->id,'tanggal'=>'2026-11-28','status'=>'HADIR'];
        $this->postJson('/api/attendance',$payload)->assertUnprocessable();
        $this->postJson('/api/attendance/bulk',['jadwal_pelajaran_id'=>$c['schedule']->id,'tanggal'=>'2026-11-28','presensi'=>[['siswa_id'=>$c['student']->id,'status'=>'HADIR']]])->assertUnprocessable();
        $this->getJson('/api/attendance/roster?schedule_id='.$c['schedule']->id.'&date=2026-11-28')->assertUnprocessable();
        $this->assertDatabaseCount('presensi',0);
        $payload['tanggal']='2026-11-27'; $this->postJson('/api/attendance',$payload)->assertCreated();
        $existing=Presensi::firstOrFail();$this->putJson('/api/attendance/'.$existing->id,['tanggal'=>'2026-11-28'])->assertUnprocessable();
        $payload['tanggal']='2026-11-28';$legacy=Presensi::create($payload);
        $this->putJson('/api/attendance/'.$legacy->id,['status'=>'ALPA'])->assertUnprocessable();
        $this->assertSame('HADIR',$legacy->fresh()->status);
        $this->getJson('/api/attendance/roster?schedule_id='.$c['schedule']->id.'&date=2026-11-27')->assertOk();
        $this->assertDatabaseCount('presensi',2);
    }
    public function test_teacher_dashboard_never_falls_back_to_history(): void
    {
        $c=$this->createTeachingContext(); Sanctum::actingAs($c['user']);
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.context_status','active')->assertJsonPath('data.summary.teaching_schedule_count',1);
        $c['academicYear']->update(['is_active'=>false]);
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.context_status','none')->assertJsonPath('data.summary.teaching_schedule_count',null)->assertJsonCount(0,'data.today_schedule');
        $c['academicYear']->update(['is_active'=>true]);$this->createAcademicYear(['semester'=>'GENAP']);
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.context_status','conflict')->assertJsonPath('data.summary.teaching_schedule_count',null)->assertJsonCount(0,'data.recent.materials');
    }
}
