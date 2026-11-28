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

class ScheduleV70ExcelTest extends TestCase
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

    private function payload($period,$class,$subject,$teacher): array
    {
        return ['tahun_akademik_id'=>$period->id,'kelas_id'=>$class->id,'mapel_id'=>$subject->id,'guru_id'=>$teacher->id,'hari'=>'senin','jam_mulai'=>'07:00','jam_selesai'=>'08:00'];
    }
    public function test_invalid_day_and_inactive_teacher_are_rejected(): void
    {
        Sanctum::actingAs($this->createUser('TU'));
        $p=$this->createAcademicYear(); $c=$this->createClass($p); $m=$this->createSubject(); [$u,$g]=$this->createTeacher();
        $data=$this->payload($p,$c,$m,$g);
        $this->postJson('/api/schedules',array_replace($data,['hari'=>'Foo']))->assertUnprocessable()->assertJsonValidationErrors('hari');
        $u->update(['account_status'=>'inactive']);
        $this->postJson('/api/schedules',$data)->assertUnprocessable()->assertJsonValidationErrors('guru_id');
    }
    public function test_used_schedule_cannot_be_updated_or_deleted(): void
    {
        Sanctum::actingAs($this->createUser('TU'));
        $p=$this->createAcademicYear(); $c=$this->createClass($p); $m=$this->createSubject(); [,$g]=$this->createTeacher();
        $s=$this->createSchedule($p,$c,$m,$g);
        \Illuminate\Support\Facades\DB::table('komponen_nilai')->insert(['jadwal_pelajaran_id'=>$s->id,'nama'=>'Tugas','bobot'=>100]);
        $this->putJson('/api/schedules/'.$s->id,['hari'=>'SELASA'])->assertConflict();
        $this->deleteJson('/api/schedules/'.$s->id)->assertConflict();
        $this->assertDatabaseHas('jadwal_pelajaran',['id'=>$s->id,'hari'=>'SENIN']);
    }
    public function test_stale_version_is_rejected(): void
    {
        Sanctum::actingAs($this->createUser('TU'));
        $p=$this->createAcademicYear(); $c=$this->createClass($p); $m=$this->createSubject(); [,$g]=$this->createTeacher();
        $s=$this->createSchedule($p,$c,$m,$g);
        $v=$this->getJson('/api/schedules/'.$s->id)->assertOk()->json('data.version');
        $this->putJson('/api/schedules/'.$s->id,['hari'=>'SELASA','version'=>$v])->assertOk();
        $this->deleteJson('/api/schedules/'.$s->id,['version'=>$v])->assertConflict();
    }
    private function workbook(array $rows): string
    {
        $book=new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet=$book->getActiveSheet();
        foreach ($rows as $i=>$row) foreach ($row as $j=>$value) $sheet->setCellValueExplicit([$j+1,$i+1],(string)$value,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $path=tempnam(sys_get_temp_dir(),'test-jadwal-');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);
        $book->disconnectWorksheets(); return $path;
    }
    public function test_import_preview_is_read_only_and_commit_appends_once(): void
    {
        Sanctum::actingAs($this->createUser('TU'));
        $p=$this->createAcademicYear(); $c=$this->createClass($p); $m=$this->createSubject(); [,$g]=$this->createTeacher();
        $g->update(['nip'=>'000123']);
        $this->createSchedule($p,$c,$m,$g,'SENIN','07:00','08:00');
        $path=$this->workbook([(new \App\Exports\ScheduleExport())->headings(),['2026/2027','GANJIL',$c->tingkat,$c->nama,$m->kode,'000123','SENIN','08:00','09:00']]);
        try {
            $send=function($mode,$token=null) use($path,$p) {
                return $this->post('/api/schedules/import',['academic_year_id'=>$p->id,'mode'=>$mode,'token'=>$token,'file'=>new \Illuminate\Http\UploadedFile($path,'jadwal.xlsx','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',null,true)],['Accept'=>'application/json']);
            };
            $preview=$send('preview')->assertOk()->assertJsonPath('data.count',1);
            $this->assertDatabaseCount('jadwal_pelajaran',1);
            $send('commit',$preview->json('data.token'))->assertOk();
            $this->assertDatabaseCount('jadwal_pelajaran',2);
            $send('commit',$preview->json('data.token'))->assertUnprocessable();
            $this->assertDatabaseCount('jadwal_pelajaran',2);
        } finally { @unlink($path); }
    }
    public function test_distribution_document_contains_only_selected_teacher(): void
    {
        Sanctum::actingAs($this->createUser('TU'));
        $p=$this->createAcademicYear(); $c=$this->createClass($p); $m=$this->createSubject(); [,$g]=$this->createTeacher(); [,$other]=$this->createTeacher();
        $g->update(['nip'=>'000123','nama_lengkap'=>'Guru Pertama']); $other->update(['nama_lengkap'=>'Guru Kedua']);
        $this->createSchedule($p,$c,$m,$g,'SENIN'); $this->createSchedule($p,$c,$m,$other,'SELASA');
        $response=$this->get('/api/schedules/export?format=teacher&academic_year_id='.$p->id.'&teacher_id='.$g->id)->assertOk();
        $path=$response->baseResponse->getFile()->getPathname();
        try {
            $book=\PhpOffice\PhpSpreadsheet\IOFactory::load($path);
            $sheet=$book->getActiveSheet();
            $this->assertSame('Guru Pertama',$sheet->getCell('A4')->getValue());
            $this->assertStringContainsString('000123',$sheet->getCell('A5')->getValue());
            $this->assertSame('SENIN',$sheet->getCell('A8')->getValue());
            $this->assertNull($sheet->getCell('A9')->getValue());
            $book->disconnectWorksheets();
        } finally { @unlink($path); }
    }
    public function test_template_preserves_reference_sheets_and_nip(): void
    {
        Sanctum::actingAs($this->createUser('TU'));
        $p=$this->createAcademicYear(); $this->createClass($p); $this->createSubject(); [,$g]=$this->createTeacher(); $g->update(['nip'=>'000123']);
        $response=$this->get('/api/schedules/template?academic_year_id='.$p->id)->assertOk();
        $path=$response->baseResponse->getFile()->getPathname();
        try {
            $book=\PhpOffice\PhpSpreadsheet\IOFactory::load($path);
            $this->assertSame(['Input Jadwal','Referensi Guru','Referensi Mapel','Referensi Kelas','Petunjuk'],$book->getSheetNames());
            $this->assertSame('nip_guru',$book->getSheet(0)->getCell('F1')->getValue());
            $this->assertSame('000123',$book->getSheet(1)->getCell('A2')->getValue());
            $book->disconnectWorksheets();
        } finally { @unlink($path); }
    }
    public function test_batch_zip_contains_one_workbook_for_each_eligible_teacher(): void
    {
        Sanctum::actingAs($this->createUser('TU'));
        $p=$this->createAcademicYear(); $c=$this->createClass($p); $m=$this->createSubject(); [,$g]=$this->createTeacher(); [,$other]=$this->createTeacher(); [$inactiveUser,$inactive]=$this->createTeacher();
        $inactiveUser->update(['account_status'=>'inactive']);
        $this->createSchedule($p,$c,$m,$g,'SENIN'); $this->createSchedule($p,$c,$m,$other,'SELASA'); $this->createSchedule($p,$c,$m,$inactive,'RABU');
        $response=$this->get('/api/schedules/export?format=batch&academic_year_id='.$p->id)->assertOk();
        $path=$response->baseResponse->getFile()->getPathname();
        $zip=new \ZipArchive();
        try {
            $this->assertTrue($zip->open($path));
            $this->assertSame(3,$zip->numFiles);
            $this->assertNotFalse($zip->locateName('PERHATIAN.txt'));
            $names=[]; for($i=0;$i<$zip->numFiles;$i++) $names[]=$zip->getNameIndex($i);
            $this->assertCount(2,array_filter($names,fn($n)=>str_ends_with($n,'.xlsx')));
        } finally { $zip->close(); @unlink($path); }
    }
    public function test_non_tu_cannot_export_distribution(): void
    {
        Sanctum::actingAs($this->createUser('GURU'));
        $this->getJson('/api/schedules/export?format=batch')->assertForbidden();
    }
}
