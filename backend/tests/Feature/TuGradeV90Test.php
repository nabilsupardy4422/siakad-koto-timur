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

class TuGradeV90Test extends TestCase
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
    private function url($year,$class,$view,array $extra=[]): string {return '/api/tu/grade-monitoring?'.http_build_query(array_merge(['academic_year_id'=>$year->id,'class_id'=>$class->id,'view'=>$view,'page'=>1],$extra));}
    public function test_zero_and_missing_slots_are_distinct(): void {
        extract($this->scenario()); $comp=\App\Models\KomponenNilai::create(['jadwal_pelajaran_id'=>$s->id,'nama'=>'Tugas','bobot'=>40]);
        \App\Models\Nilai::create(['komponen_nilai_id'=>$comp->id,'siswa_id'=>$a->id,'nilai'=>0]);
        $this->getJson($this->url($year,$class,'coverage'))->assertOk()->assertJsonPath('data.rows.0.slots',2)->assertJsonPath('data.rows.0.filled',1)->assertJsonPath('data.rows.0.missing',1);
        $r=$this->getJson($this->url($year,$class,'detail'))->assertOk()->assertJsonPath('meta.total',2);
        $this->assertContains(0,array_column($r->json('data.rows'),'score'));$this->assertContains(null,array_column($r->json('data.rows'),'score'));
        $this->assertDatabaseCount('nilai',1);
    }
    public function test_historical_records_do_not_manufacture_slots(): void {
        extract($this->scenario()); $comp=\App\Models\KomponenNilai::create(['jadwal_pelajaran_id'=>$s->id,'nama'=>'Tugas','bobot'=>40]);
        \App\Models\Nilai::create(['komponen_nilai_id'=>$comp->id,'siswa_id'=>$a->id,'nilai'=>85]);
        $year->update(['is_active'=>false]);
        $this->getJson($this->url($year,$class,'coverage'))->assertOk()->assertJsonPath('data.rows.0.slots',null)->assertJsonPath('data.rows.0.recorded',1);
        $this->getJson($this->url($year,$class,'detail'))->assertOk()->assertJsonPath('meta.total',1)->assertJsonPath('data.rows.0.state','Riwayat tercatat');
    }
    public function test_no_component_and_wrong_scope_and_role(): void {
        extract($this->scenario());
        $this->getJson($this->url($year,$class,'coverage'))->assertOk()->assertJsonPath('data.rows.0.state','Belum ada komponen');
        $this->getJson($this->url($year,$class,'detail',['component_id'=>999]))->assertUnprocessable();
        Sanctum::actingAs($u);$this->getJson($this->url($year,$class,'coverage'))->assertForbidden();
    }
    public function test_active_former_member_keeps_saved_grade(): void {
        extract($this->scenario());
        AnggotaKelas::where('siswa_id',$a->id)->update(['tanggal_mulai'=>'2026-11-01','tanggal_selesai'=>'2026-11-26']);
        $comp=\App\Models\KomponenNilai::create(['jadwal_pelajaran_id'=>$s->id,'nama'=>'Tugas','bobot'=>30]);
        \App\Models\Nilai::create(['komponen_nilai_id'=>$comp->id,'siswa_id'=>$a->id,'nilai'=>85]);
        $r=$this->getJson($this->url($year,$class,'detail'))->assertOk()->assertJsonPath('meta.total',2);
        $this->assertContains('Riwayat tercatat',array_column($r->json('data.rows'),'state'));
        $this->getJson($this->url($year,$class,'coverage'))->assertOk()->assertJsonPath('data.rows.0.slots',1)->assertJsonPath('data.rows.0.filled',0)->assertJsonPath('data.rows.0.recorded',1);
    }
    public function test_detail_pagination_and_complete_summary(): void {
        extract($this->scenario());
        for($i=0;$i<11;$i++) \App\Models\KomponenNilai::create(['jadwal_pelajaran_id'=>$s->id,'nama'=>'Tugas '.$i,'bobot'=>5]);
        $this->getJson($this->url($year,$class,'detail'))->assertOk()->assertJsonCount(20,'data.rows')->assertJsonPath('meta.total',22)->assertJsonPath('meta.last_page',2)->assertJsonPath('data.summary.Belum dinilai',22);
        $this->getJson($this->url($year,$class,'detail',['page'=>2]))->assertOk()->assertJsonCount(2,'data.rows')->assertJsonPath('data.summary.Belum dinilai',22);
    }
}
