<?php

namespace Tests\Feature;

use App\Models\{AnggotaKelas,Kelas,Role,Siswa,TahunAkademik,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ClassMembershipV51Test extends TestCase
{
    use RefreshDatabase;
    private function user(string $role): User {
        return User::create(['role_id'=>Role::firstOrCreate(['code'=>$role],['name'=>$role])->id,'username'=>uniqid('u'),'name'=>'Uji','password'=>'test-password','account_status'=>'active']);
    }
    private function student(string $id): Siswa {
        return Siswa::create(['user_id'=>$this->user('SISWA')->id,'nisn'=>$id,'nipd'=>'P'.$id,'nama_lengkap'=>'Uji '.$id]);
    }
    private function classes(): array {
        $y=TahunAkademik::create(['tahun_mulai'=>2026,'tahun_selesai'=>2027,'semester'=>'GANJIL','is_active'=>true]);
        return [Kelas::create(['tahun_akademik_id'=>$y->id,'nama'=>'E1','tingkat'=>10]),Kelas::create(['tahun_akademik_id'=>$y->id,'nama'=>'E2','tingkat'=>10])];
    }
    private function membership($class,$student,$start,$end=null) {
        return AnggotaKelas::create(['kelas_id'=>$class->id,'siswa_id'=>$student->id,'tanggal_mulai'=>$start,'tanggal_selesai'=>$end]);
    }
    private function file(array $rows): UploadedFile {
        $book=new Spreadsheet();$sheet=$book->getActiveSheet();$sheet->fromArray(['nisn','nipd','nama_lengkap'],null,'A1');
        foreach($rows as $i=>$row) foreach($row as $j=>$value) $sheet->setCellValueExplicit(chr(65+$j).($i+2),$value,DataType::TYPE_STRING);
        $path=tempnam(sys_get_temp_dir(),'membership');(new Xlsx($book))->save($path);$book->disconnectWorksheets();
        return new UploadedFile($path,'anggota.xlsx','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',null,true);
    }
    public function test_future_and_inclusive_intervals_are_rejected_and_next_day_allowed(): void {
        Sanctum::actingAs($this->user('TU'));[$a,$b]=$this->classes();$s=$this->student('000001');
        $this->membership($a,$s,'2027-01-01','2027-01-10');
        foreach(['2026-12-20','2027-01-10'] as $date) $this->postJson("/api/classes/{$b->id}/students",['siswa_id'=>$s->id,'tanggal_mulai'=>$date])->assertUnprocessable();
        $this->postJson("/api/classes/{$b->id}/students",['siswa_id'=>$s->id,'tanggal_mulai'=>'2027-01-11'])->assertCreated();
        $this->assertDatabaseCount('anggota_kelas',2);
    }
    public function test_bulk_preview_is_read_only_and_replay_fails(): void {
        Sanctum::actingAs($this->user('TU'));[$a,$b]=$this->classes();$s=$this->student('000002');$t=$this->student('000003');
        $url="/api/classes/{$a->id}/students/bulk";$p=['siswa_ids'=>[$s->id,$t->id],'tanggal_mulai'=>'2026-12-01'];
        $token=$this->postJson($url.'/preview',$p)->assertOk()->assertJsonPath('data.rows.0.valid',true)->json('data.preview_token');
        $this->assertDatabaseCount('anggota_kelas',0);$p['preview_token']=$token;
        $this->postJson($url,$p)->assertCreated()->assertJsonPath('data.total_imported',2);
        $this->postJson($url,$p)->assertConflict();$this->assertDatabaseCount('anggota_kelas',2);
    }
    public function test_bulk_conflict_and_stale_preview_never_partially_write(): void {
        Sanctum::actingAs($this->user('TU'));[$a,$b]=$this->classes();$s=$this->student('000004');$t=$this->student('000005');
        $url="/api/classes/{$a->id}/students/bulk";$p=['siswa_ids'=>[$s->id,$t->id],'tanggal_mulai'=>'2026-12-01'];
        $p['preview_token']=$this->postJson($url.'/preview',$p)->assertOk()->json('data.preview_token');
        $this->membership($b,$t,'2027-01-01');$this->postJson($url,$p)->assertConflict();
        $p['preview_token']=$this->postJson($url.'/preview',$p)->assertOk()->json('data.preview_token');
        $this->postJson($url,$p)->assertUnprocessable();$this->assertDatabaseCount('anggota_kelas',1);
    }
    public function test_teacher_cannot_preview_or_import_or_read_admin_history(): void {
        Sanctum::actingAs($this->user('GURU'));[$a]=$this->classes();
        $this->postJson("/api/classes/{$a->id}/students/bulk/preview",[])->assertForbidden();
        $this->postJson("/api/classes/{$a->id}/students/import/preview",[])->assertForbidden();
        $this->getJson("/api/classes/{$a->id}/students?membership_status=all")->assertForbidden();
    }
    public function test_excel_all_duplicates_rejected_and_valid_batch_preserves_identity(): void {
        Sanctum::actingAs($this->user('TU'));[$a]=$this->classes();$s=$this->student('000006');$t=$this->student('000007');
        $url="/api/classes/{$a->id}/students/import";$r=[$s->nisn,$s->nipd,$s->nama_lengkap];$q=[$t->nisn,$t->nipd,$t->nama_lengkap];
        $p=['file'=>$this->file([$r,$r,$q]),'tanggal_mulai'=>'2026-12-01'];
        $preview=$this->post($url.'/preview',$p,['Accept'=>'application/json'])->assertOk()->assertJsonPath('data.summary.invalid_rows',2)->assertJsonPath('data.rows.0.status','Duplikat')->assertJsonPath('data.rows.1.status','Duplikat');
        $p['preview_token']=$preview->json('data.preview_token');$this->post($url,$p,['Accept'=>'application/json'])->assertUnprocessable();$this->assertDatabaseCount('anggota_kelas',0);
        $p=['file'=>$this->file([$r,$q]),'tanggal_mulai'=>'2026-12-01'];$p['preview_token']=$this->post($url.'/preview',$p,['Accept'=>'application/json'])->assertOk()->assertJsonPath('data.rows.0.nisn','000006')->json('data.preview_token');
        $this->post($url,$p,['Accept'=>'application/json'])->assertOk()->assertJsonPath('data.summary.imported_rows',2);
        $this->assertDatabaseCount('siswa',2);$this->assertDatabaseCount('anggota_kelas',2);
    }
    public function test_tu_history_pagination_and_stale_end_guard(): void {
        Sanctum::actingAs($this->user('TU'));[$a]=$this->classes();$s=$this->student('000008');$t=$this->student('000009');
        $old=$this->membership($a,$s,'2025-01-01','2025-01-02');$current=$this->membership($a,$s,'2025-01-03');$this->membership($a,$t,'2099-01-01');
        $this->getJson("/api/classes/{$a->id}/students?membership_status=all&per_page=1&page=2")->assertOk()->assertJsonPath('meta.total',3)->assertJsonPath('meta.last_page',3)->assertJsonPath('data.0.id',$current->id);
        $this->deleteJson("/api/classes/{$a->id}/students/{$s->id}",['membership_id'=>$old->id])->assertConflict();
        $this->assertNull($current->fresh()->tanggal_selesai);
    }
}
