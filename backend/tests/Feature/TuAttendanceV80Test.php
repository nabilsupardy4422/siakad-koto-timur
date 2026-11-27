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

class TuAttendanceV80Test extends TestCase
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
    private function url($year,$view,array $extra=[]): string {return '/api/tu/attendance-monitoring?'.http_build_query(array_merge(['academic_year_id'=>$year->id,'view'=>$view,'date'=>'2026-11-27','page'=>1],$extra));}
    public function test_daily_priority_and_inclusive_roster(): void {
        extract($this->scenario()); $replacement=$this->createSchedule($year,$class,$subject,$g,'SENIN','08:00','09:00');
        foreach([[$s,'HADIR'],[$replacement,'ALPA']] as [$schedule,$status]) \App\Models\Presensi::create(['jadwal_pelajaran_id'=>$schedule->id,'siswa_id'=>$a->id,'tanggal'=>'2026-11-27','status'=>$status]);
        $this->getJson($this->url($year,'daily'))->assertOk()->assertJsonPath('data.rows.0.total',2)->assertJsonPath('data.rows.0.alpa',1)->assertJsonPath('data.rows.0.missing',1);
        $this->getJson($this->url($year,'detail'))->assertOk()->assertJsonPath('meta.total',2);
        $this->assertDatabaseCount('presensi',2);
    }
    public function test_coverage_distinguishes_partial_and_future(): void {
        extract($this->scenario()); \App\Models\Presensi::create(['jadwal_pelajaran_id'=>$s->id,'siswa_id'=>$a->id,'tanggal'=>'2026-11-27','status'=>'HADIR']);
        $this->createSchedule($year,$class,$subject,$g,'JUMAT','12:00','13:00');
        $this->getJson($this->url($year,'coverage'))->assertOk()->assertJsonPath('data.summary.Sebagian',1)->assertJsonPath('data.summary.Belum selesai',1);
    }
    public function test_anomaly_visible_but_not_counted_and_role_rejected(): void {
        extract($this->scenario()); \App\Models\Presensi::create(['jadwal_pelajaran_id'=>$s->id,'siswa_id'=>$future->id,'tanggal'=>'2026-11-27','status'=>'HADIR']);
        $this->getJson($this->url($year,'detail'))->assertOk()->assertJsonPath('data.rows.0.integrity','Anomali');
        $this->getJson($this->url($year,'daily'))->assertOk()->assertJsonPath('data.rows.0.hadir',0)->assertJsonPath('data.rows.0.missing',2);
        $this->getJson($this->url($year,'daily',['teacher_id'=>$g->id]))->assertUnprocessable();
        Sanctum::actingAs($u);$this->getJson($this->url($year,'daily'))->assertForbidden();
    }
    public function test_daily_pagination_summary_covers_all_classes(): void {
        extract($this->scenario());
        for($i=0;$i<20;$i++) $this->createClass($year,'UAT Rombel '.$i,10);
        $this->getJson($this->url($year,'daily'))->assertOk()->assertJsonCount(20,'data.rows')->assertJsonPath('meta.total',21)->assertJsonPath('meta.last_page',2)->assertJsonPath('data.summary.Total siswa per rombel',2);
        $this->getJson($this->url($year,'daily',['page'=>2]))->assertOk()->assertJsonCount(1,'data.rows')->assertJsonPath('data.summary.Total siswa per rombel',2);
    }
    public function test_wrong_period_class_and_unknown_filter_rejected(): void {
        extract($this->scenario()); $other=$this->createAcademicYear(2026,2027,'GENAP',false); $foreign=$this->createClass($other);
        $this->getJson($this->url($year,'daily',['class_id'=>$foreign->id]))->assertUnprocessable();
        $this->getJson($this->url($year,'daily',['unknown'=>1]))->assertUnprocessable();
    }
}
