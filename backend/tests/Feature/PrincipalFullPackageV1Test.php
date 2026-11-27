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

class PrincipalFullPackageV1Test extends TestCase
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

    private function scenario(): array
    {
        $actor=$this->createUser('KEPALA_SEKOLAH'); Sanctum::actingAs($actor);
        $year=$this->createAcademicYear(2026,2027,'GANJIL',true);
        $class=$this->createClass($year); $subject=$this->createSubject(); [$u,$g]=$this->createTeacher();
        $s=$this->createSchedule($year,$class,$subject,$g,'JUMAT','07:00','08:00');
        [, $a]=$this->createStudent(); [, $b]=$this->createStudent(); [, $future]=$this->createStudent();
        foreach([[$a,'2026-11-27','2026-11-27'],[$b,'2026-11-01',null],[$future,'2026-11-28',null]] as [$student,$start,$end]) AnggotaKelas::create(['kelas_id'=>$class->id,'siswa_id'=>$student->id,'tanggal_mulai'=>$start,'tanggal_selesai'=>$end]);
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-11-27 10:00:00','Asia/Jakarta'));
        return compact('actor','year','class','subject','u','g','s','a','b','future');
    }
    protected function tearDown(): void { \Illuminate\Support\Carbon::setTestNow(); parent::tearDown(); }
    public function test_dashboard_distinct_and_no_active_fallback(): void {
        extract($this->scenario());$other=$this->createClass($year,'IPA 2',11);
        AnggotaKelas::create(['kelas_id'=>$other->id,'siswa_id'=>$a->id,'tanggal_mulai'=>'2026-11-27']);
        $url='/api/principal/dashboard-workspace';
        $this->getJson($url)->assertOk()->assertJsonPath('data.role','KEPALA_SEKOLAH')->assertJsonPath('data.summary.effective_students',2)->assertJsonPath('data.summary.total_kelas',2)->assertJsonCount(2,'data.charts.students_by_class');
        $year->update(['is_active'=>false]);$this->getJson($url)->assertOk()->assertJsonPath('data.active_state','none')->assertJsonPath('data.summary.total_kelas',null);
        $year->update(['is_active'=>true]);$this->createAcademicYear(2026,2027,'GENAP',true);$this->getJson($url)->assertOk()->assertJsonPath('data.active_state','conflict')->assertJsonPath('data.summary.effective_students',null);
    }
    public function test_daily_priority_and_future_exclusion(): void {
        extract($this->scenario());$other=$this->createSchedule($year,$class,$subject,$g,'JUMAT','08:00','09:00');
        foreach([[$s,$a,'2026-11-27','hadir'],[$other,$a,'2026-11-27','ALPA'],[$s,$b,'2026-11-28','HADIR']] as [$schedule,$student,$date,$status]) \App\Models\Presensi::create(['jadwal_pelajaran_id'=>$schedule->id,'siswa_id'=>$student->id,'tanggal'=>$date,'status'=>$status]);
        $q='academic_year_id='.$year->id;$url='/api/principal/attendance-daily?'.$q.'&semester=GANJIL';
        $this->getJson($url.'&date=2026-11-27')->assertOk()->assertJsonPath('data.0.alpa',1)->assertJsonPath('data.0.belum_ada_data',1);
        $this->getJson($url.'&date=2026-11-28')->assertUnprocessable();
        $this->getJson('/api/principal/attendance-summary?'.$q.'&date_from=2026-11-01&date_to=2026-11-30')->assertOk()->assertJsonPath('data.total',2)->assertJsonPath('data.hadir',1);
        $this->assertDatabaseCount('presensi',3);
    }
    public function test_grade_zero_former_and_future_membership(): void {
        extract($this->scenario());$comp=\App\Models\KomponenNilai::create(['jadwal_pelajaran_id'=>$s->id,'nama'=>'Tugas','bobot'=>20]);
        AnggotaKelas::where('siswa_id',$a->id)->update(['tanggal_mulai'=>'2026-11-01','tanggal_selesai'=>'2026-11-26']);
        foreach([[$a,0],[$b,80],[$future,100]] as [$student,$score]) \App\Models\Nilai::create(['komponen_nilai_id'=>$comp->id,'siswa_id'=>$student->id,'nilai'=>$score]);
        $q='academic_year_id='.$year->id;$url='/api/principal/grade-monitoring?'.$q;
        $this->getJson('/api/principal/grade-summary?'.$q)->assertOk()->assertJsonPath('data.total',2)->assertJsonPath('data.rata_rata',40)->assertJsonPath('data.terendah',0);
        $this->getJson($url.'&view=coverage')->assertUnprocessable();
        $detail=$this->getJson($url.'&view=detail&class_id='.$class->id)->assertOk()->json('data.rows');
        $this->assertTrue(collect($detail)->contains(fn($v)=>$v['student_id']===$a->id && (float)$v['score']===0.0 && $v['state']==='Riwayat tercatat'));
        $this->assertTrue(collect($detail)->contains(fn($v)=>$v['student_id']===$future->id && $v['score']===null && $v['state']==='Anomali'));
        $year->update(['is_active'=>false]);$this->getJson($url.'&view=coverage&class_id='.$class->id)->assertOk()->assertJsonPath('data.summary.Slot saat ini',null);
    }
    public function test_seven_report_types_and_fingerprint(): void {
        extract($this->scenario());$q='academic_year_id='.$year->id;
        foreach(['students','teachers','classes','schedules','attendance','grades','semester-recap'] as $type){$params=$type==='teachers'?'':$q;$this->getJson('/api/principal/reports/'.$type.'/preview?'.$params)->assertOk()->assertJsonStructure(['data'=>['preview_hash','rows','headings','metadata','effective_filters']]);}
        $preview='/api/principal/reports/classes/preview?'.$q;$export='/api/principal/reports/classes/export?'.$q;
        $hash=$this->getJson($preview)->assertOk()->json('data.preview_hash');$this->getJson($export.'&format=excel')->assertUnprocessable();
        foreach(['excel','pdf'] as $format)$this->get($export.'&format='.$format.'&preview_hash='.$hash)->assertOk();
        $this->createClass($year,'New');$this->getJson($export.'&format=excel&preview_hash='.$hash)->assertConflict();
        $this->getJson('/api/principal/reports/teachers/preview?status=active')->assertUnprocessable();
        $this->getJson('/api/principal/reports/grade-coverage/preview?'.$q)->assertUnprocessable();
    }
    public function test_empty_report_confirmation_and_role_separation(): void {
        $this->getJson('/api/principal/dashboard-workspace')->assertUnauthorized();extract($this->scenario());
        $q='academic_year_id='.$year->id;$hash=$this->getJson('/api/principal/reports/attendance/preview?'.$q)->assertOk()->assertJsonPath('data.total',0)->json('data.preview_hash');
        $url='/api/principal/reports/attendance/export?'.$q.'&format=excel&preview_hash='.$hash;$this->getJson($url)->assertUnprocessable();$this->get($url.'&confirm_empty=1')->assertOk();
        $this->getJson('/api/tu/dashboard-workspace')->assertForbidden();
        foreach(['TU','GURU','SISWA'] as $role){Sanctum::actingAs($this->createUser($role));$this->getJson('/api/principal/dashboard-workspace')->assertForbidden();$this->getJson('/api/principal/grade-monitoring?'.$q.'&view=catalog')->assertForbidden();$this->getJson('/api/principal/reports/classes/preview?'.$q)->assertForbidden();}
    }
}
