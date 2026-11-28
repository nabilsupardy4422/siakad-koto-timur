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

class TuDashboardV120Test extends TestCase
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
        $actor=$this->createUser('TU'); Sanctum::actingAs($actor);
        $year=$this->createAcademicYear(2026,2027,'GANJIL',true);
        $class=$this->createClass($year); $subject=$this->createSubject(); [$u,$g]=$this->createTeacher();
        $s=$this->createSchedule($year,$class,$subject,$g,'JUMAT','07:00','08:00');
        [, $a]=$this->createStudent(); [, $b]=$this->createStudent(); [, $future]=$this->createStudent();
        foreach([[$a,'2026-11-27','2026-11-27'],[$b,'2026-11-01',null],[$future,'2026-11-28',null]] as [$student,$start,$end]) AnggotaKelas::create(['kelas_id'=>$class->id,'siswa_id'=>$student->id,'tanggal_mulai'=>$start,'tanggal_selesai'=>$end]);
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-11-27 10:00:00','Asia/Jakarta'));
        return compact('actor','year','class','subject','u','g','s','a','b','future');
    }
    protected function tearDown(): void { \Illuminate\Support\Carbon::setTestNow(); parent::tearDown(); }
    private function dashboardUrl(string $section='context', array $filters=[]): string {
        return '/api/tu/dashboard-workspace?'.http_build_query(['section'=>$section]+$filters);
    }
    public function test_global_context_and_effective_distribution(): void {
        extract($this->scenario());
        $empty=$this->createClass($year,'Rombel kosong',11);
        $historical=$this->createAcademicYear(2026,2027,'GENAP',false);$this->createClass($historical);
        $this->getJson($this->dashboardUrl())->assertOk()->assertJsonPath('data.role','TU')->assertJsonPath('data.active_state','active')->assertJsonPath('data.period.id',$year->id)->assertJsonPath('data.date','2026-11-27')->assertJsonPath('data.master.students',3)->assertJsonPath('data.master.teachers',1);
        $this->getJson($this->dashboardUrl('distribution',['academic_year_id'=>$year->id,'date'=>'2026-11-27']))->assertOk()->assertJsonPath('data.unique_students',2)->assertJsonPath('data.memberships',2)->assertJsonCount(2,'data.classes')->assertJsonPath('data.classes.0.students',2)->assertJsonPath('data.classes.1.students',0);
        $this->assertDatabaseCount('anggota_kelas',3);
    }
    public function test_no_active_and_conflicting_active_periods_fail_closed(): void {
        extract($this->scenario());$year->update(['is_active'=>false]);
        $this->getJson($this->dashboardUrl())->assertOk()->assertJsonPath('data.active_state','none')->assertJsonPath('data.period',null)->assertJsonPath('data.master.students',3);
        $this->getJson($this->dashboardUrl('distribution',['academic_year_id'=>$year->id,'date'=>'2026-11-27']))->assertConflict();
        $year->update(['is_active'=>true]);$this->createAcademicYear(2026,2027,'GENAP',true);
        $this->getJson($this->dashboardUrl())->assertOk()->assertJsonPath('data.active_state','conflict')->assertJsonPath('data.period',null);
        $this->getJson($this->dashboardUrl('homeroom',['academic_year_id'=>$year->id,'date'=>'2026-11-27']))->assertConflict();
    }
    public function test_duplicates_preserve_unique_and_per_class_units(): void {
        extract($this->scenario());$second=$this->createClass($year,'IPA 2',10);
        AnggotaKelas::create(['kelas_id'=>$second->id,'siswa_id'=>$a->id,'tanggal_mulai'=>'2026-11-27','tanggal_selesai'=>null]);
        $this->getJson($this->dashboardUrl('distribution',['academic_year_id'=>$year->id,'date'=>'2026-11-27']))->assertOk()->assertJsonPath('data.unique_students',2)->assertJsonPath('data.memberships',3)->assertJsonPath('data.cross_class',1)->assertJsonPath('data.duplicate_effective',1);
        $this->assertDatabaseCount('anggota_kelas',4);
    }
    public function test_homeroom_summary_only_and_wib_boundary(): void {
        extract($this->scenario());
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-11-27 17:00:00','UTC'));
        $this->getJson($this->dashboardUrl())->assertOk()->assertJsonPath('data.date','2026-11-28');
        $this->getJson($this->dashboardUrl('homeroom',['academic_year_id'=>$year->id,'date'=>'2026-11-27']))->assertConflict();
        $this->getJson($this->dashboardUrl('homeroom',['academic_year_id'=>$year->id,'date'=>'2026-11-28']))->assertOk()->assertJsonPath('data.summary.total',1)->assertJsonPath('data.summary.unassigned',1)->assertJsonMissingPath('data.assignments');
    }
    public function test_role_and_filter_guards(): void {
        $this->getJson($this->dashboardUrl())->assertUnauthorized();
        extract($this->scenario());
        $this->getJson($this->dashboardUrl('context',['academic_year_id'=>$year->id]))->assertUnprocessable();
        $this->getJson($this->dashboardUrl('distribution',['academic_year_id'=>$year->id,'date'=>'2026-11-27','class_id'=>$class->id]))->assertUnprocessable();
        Sanctum::actingAs($u);$this->getJson($this->dashboardUrl())->assertForbidden();
    }
}
