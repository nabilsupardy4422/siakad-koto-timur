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
use App\Models\WaliKelas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClassDirectoryV4Test extends TestCase
{
    use RefreshDatabase;

    private function account(string $code): User
    {
        $role = Role::firstOrCreate(['code' => $code], ['name' => $code]);
        return User::create(['role_id' => $role->id, 'name' => $code, 'username' => uniqid('v4'), 'password' => 'test-password', 'account_status' => 'active']);
    }
    private function tu(): void { Sanctum::actingAs($this->account('TU')); }
    private function year(bool $active = true, string $semester = 'GANJIL'): TahunAkademik
    {
        return TahunAkademik::create(['tahun_mulai' => 2026, 'tahun_selesai' => 2027, 'semester' => $semester, 'is_active' => $active]);
    }
    private function rombel(TahunAkademik $period, string $name, int $level = 11): Kelas
    {
        return Kelas::create(['tahun_akademik_id' => $period->id, 'nama' => $name, 'tingkat' => $level]);
    }
    private function student(): Siswa { return Siswa::create(['user_id' => $this->account('SISWA')->id, 'nisn' => uniqid('N'), 'nama_lengkap' => 'Siswa Uji']); }
    private function teacher(): Guru { return Guru::create(['user_id' => $this->account('GURU')->id, 'nip' => uniqid('N'), 'nama_lengkap' => 'Guru Uji']); }
    private function member(Kelas $class, Siswa $student, string $start, ?string $end = null): void
    {
        AnggotaKelas::create(['kelas_id' => $class->id, 'siswa_id' => $student->id, 'tanggal_mulai' => $start, 'tanggal_selesai' => $end]);
    }
    private function wali(Kelas $class, Guru $guru, string $start, ?string $end = null): void
    {
        WaliKelas::create(['kelas_id' => $class->id, 'guru_id' => $guru->id, 'tahun_akademik_id' => $class->tahun_akademik_id, 'tanggal_mulai' => $start, 'tanggal_selesai' => $end]);
    }
    private function schedule(Kelas $class, Guru $guru): void
    {
        $mapel = Mapel::create(['kode' => uniqid('M'), 'nama' => 'Mapel Uji']);
        JadwalPelajaran::create(['tahun_akademik_id' => $class->tahun_akademik_id, 'kelas_id' => $class->id, 'guru_id' => $guru->id, 'mapel_id' => $mapel->id, 'hari' => 'SENIN', 'jam_mulai' => '07:00', 'jam_selesai' => '08:00']);
    }
    public function test_flexible_names_and_same_name_across_levels_and_periods(): void
    {
        $this->tu(); $a=$this->year(); $b=$this->year(false,'GENAP');
        foreach ([[$a,11,'F1'],[$a,12,'F1'],[$b,11,'F1'],[$a,10,'X. Merdeka 01'],[$b,10,'IPA 1']] as [$period,$level,$name]) {
            $this->postJson('/api/classes',['tahun_akademik_id'=>$period->id,'tingkat'=>$level,'nama'=>$name])->assertCreated()->assertJsonPath('data.nama',$name);
        }
        $this->postJson('/api/classes',['tahun_akademik_id'=>$a->id,'tingkat'=>11,'nama'=>' f1 '])->assertUnprocessable()->assertJsonValidationErrors('nama');
        $this->assertDatabaseCount('kelas',5);
    }
    public function test_default_active_and_explicit_period_without_valid_active(): void
    {
        $this->tu(); $a=$this->year(); $b=$this->year(false,'GENAP'); $this->rombel($a,'E1',10); $this->rombel($b,'E2',10);
        $this->getJson('/api/classes?directory=1')->assertOk()->assertJsonPath('meta.context.id',$a->id)->assertJsonPath('meta.context.mode','effective')->assertJsonPath('meta.total',1);
        $a->update(['is_active'=>false]);
        $this->getJson('/api/classes?directory=1')->assertConflict();
        $this->getJson('/api/classes?directory=1&academic_year_id='.$b->id)->assertOk()->assertJsonPath('meta.context.mode','history')->assertJsonPath('meta.context.active_state','none');
    }
    public function test_effective_membership_inclusive_and_summary_independent_of_filter(): void
    {
        // UTC is still Nov 26 while the school's calendar is already Nov 27.
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-26 18:00:00', 'UTC'));
        $this->assertSame('2026-11-26', today('UTC')->toDateString());
        $this->assertSame('2026-11-27', today('Asia/Jakarta')->toDateString());
        $this->tu(); $a=$this->year(); $c=$this->rombel($a,'F1'); $d=$this->rombel($a,'F1',12);
        $today=today('Asia/Jakarta')->toDateString(); $past=today('Asia/Jakarta')->subDays(5)->toDateString(); $old=today('Asia/Jakarta')->subDay()->toDateString(); $future=today('Asia/Jakarta')->addDay()->toDateString();
        $s=$this->student(); $this->member($c,$s,$past,$today); $this->member($d,$s,$today); // deliberate overlapping legacy records, summary counts unique students
        $this->member($c,$this->student(),$past,$old); $this->member($c,$this->student(),$future);
        $g=$this->teacher(); $this->wali($c,$g,$past); $this->wali($d,$g,$past,$old); $this->schedule($c,$g);
        $this->getJson('/api/classes?directory=1&grade_level=11&homeroom=assigned&schedule=used')->assertOk()->assertJsonPath('meta.total',1)->assertJsonPath('data.0.student_count',1)->assertJsonPath('data.0.homeroom_names.0','Guru Uji')->assertJsonPath('data.0.schedule_count',1)->assertJsonPath('data.0.history.members',3)->assertJsonPath('meta.summary.total',2)->assertJsonPath('meta.summary.students',1)->assertJsonPath('meta.summary.without_homeroom',1)->assertJsonPath('meta.summary.without_schedule',1);
        $this->getJson('/api/classes?directory=1&homeroom=unassigned&schedule=unused')->assertOk()->assertJsonPath('data.0.id',$d->id);
    }
    public function test_history_uses_distinct_students_and_no_current_wali_claim(): void
    {
        $this->tu(); $this->year(); $b=$this->year(false,'GENAP'); $c=$this->rombel($b,'F1'); $s=$this->student();
        $this->member($c,$s,'2025-01-01','2025-02-01'); $this->member($c,$s,'2025-03-01','2025-04-01'); $this->wali($c,$this->teacher(),'2025-01-01','2025-04-01');
        $this->getJson('/api/classes?directory=1&academic_year_id='.$b->id)->assertOk()->assertJsonPath('meta.context.mode','history')->assertJsonPath('data.0.student_count',1)->assertJsonPath('data.0.history.members',2)->assertJsonPath('data.0.homeroom_count',1)->assertJsonPath('data.0.locked',true);
    }
    public function test_search_sort_and_pagination_before_filtering(): void
    {
        $this->tu(); $a=$this->year(); $this->rombel($a,'Z'); $this->rombel($a,'F_1'); $c=$this->rombel($a,'F2');
        $this->getJson('/api/classes?directory=1&search=F&sort_order=desc&page_size=1')->assertOk()->assertJsonPath('meta.total',2);
        $this->getJson('/api/classes?directory=1&search=_')->assertOk()->assertJsonPath('meta.total',1)->assertJsonPath('data.0.nama','F_1');
        $this->getJson('/api/classes?directory=1&grade_level=11&page_size=1&sort_order=asc&page=1')->assertOk()->assertJsonPath('meta.total',3);
        $this->getJson('/api/classes?directory=1&page_size=101')->assertUnprocessable();
    }
    public function test_all_relations_lock_identity_and_period_is_immutable_even_empty(): void
    {
        $this->tu(); $a=$this->year(); $b=$this->year(false,'GENAP'); $c=$this->rombel($a,'F1'); $d=$this->rombel($a,'F2'); $e=$this->rombel($a,'F3'); $empty=$this->rombel($a,'F4');
        $this->member($c,$this->student(),'2025-01-01','2025-02-01'); $g=$this->teacher(); $this->wali($d,$g,'2025-01-01','2025-02-01'); $this->schedule($e,$g);
        foreach ([$c,$d,$e] as $item) {
            $url='/api/classes/'.$item->id; $v=$this->getJson($url.'?directory=1')->assertOk()->assertJsonPath('data.locked',true)->json('data.version');
            $this->putJson($url,['nama'=>'Changed','tingkat'=>11,'tahun_akademik_id'=>$a->id,'version'=>$v])->assertConflict(); $this->deleteJson($url,['version'=>$v])->assertConflict();
        }
        $this->putJson('/api/classes/'.$empty->id,['nama'=>'F4','tingkat'=>11,'tahun_akademik_id'=>$b->id])->assertConflict();
        $this->assertDatabaseCount('kelas',4);
    }
    public function test_stale_version_and_scope_are_enforced(): void
    {
        $this->tu(); $a=$this->year(); $c=$this->rombel($a,'F1'); $url='/api/classes/'.$c->id; $v=$this->getJson($url.'?directory=1')->assertOk()->json('data.version');
        $this->putJson($url,['nama'=>'F1 Baru','tingkat'=>11,'tahun_akademik_id'=>$a->id,'version'=>$v])->assertOk();
        $this->deleteJson($url,['version'=>$v])->assertConflict();
        Sanctum::actingAs($this->account('KEPALA_SEKOLAH')); $this->getJson($url.'?directory=1')->assertOk()->assertJsonMissingPath('data.version');
        Sanctum::actingAs($this->account('GURU')); $this->getJson('/api/classes?directory=1')->assertForbidden(); $this->getJson('/api/classes')->assertOk()->assertJsonMissingPath('meta.summary'); $this->getJson($url)->assertForbidden();
    }
    public function test_directory_detail_contract_matches_list_for_empty_locked_and_historical_classes(): void
    {
        $this->tu(); $a=$this->year(); $b=$this->year(false,'GENAP');
        $empty=$this->rombel($a,'E1',10); $locked=$this->rombel($a,'F1'); $historical=$this->rombel($b,'F1');
        $this->member($locked,$this->student(),today('Asia/Jakarta')->subDay()->toDateString());
        $g=$this->teacher(); $this->wali($locked,$g,today('Asia/Jakarta')->subDay()->toDateString()); $this->schedule($locked,$g);
        foreach ([$empty,$locked,$historical] as $item) {
            $period=$item->tahun_akademik_id;
            $list=$this->getJson('/api/classes?directory=1&academic_year_id='.$period)->assertOk();
            $expected=collect($list->json('data'))->firstWhere('id',$item->id);
            $detail=$this->getJson('/api/classes/'.$item->id.'?directory=1&academic_year_id='.$period)->assertOk()
                ->assertJsonPath('data.context.id',$period)->assertJsonPath('data.context.mode',(int)$period===(int)$a->id?'effective':'history')
                ->assertJsonStructure(['data'=>['id','tahun_akademik_id','nama','tingkat','student_count','homeroom_count','homeroom_names','schedule_count','locked','version','history'=>['members','homerooms','schedules'],'context'=>['id','tahun_mulai','tahun_selesai','semester','mode','as_of','active_state']]]);
            $actual=$detail->json('data'); $this->assertSame($list->json('meta.context'),$actual['context']);
            unset($actual['context']); $this->assertSame($expected,$actual);
            $this->assertIsArray($actual['homeroom_names']); $this->assertIsBool($actual['locked']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/',$actual['version']);
        }
        $this->getJson('/api/classes/'.$locked->id.'?directory=1&academic_year_id='.$b->id)->assertForbidden();
        $this->getJson('/api/classes/'.$locked->id)->assertOk()->assertJsonMissingPath('data.context');
        Sanctum::actingAs($this->account('KEPALA_SEKOLAH'));
        $this->getJson('/api/classes/'.$locked->id.'?directory=1&academic_year_id='.$a->id)->assertOk()->assertJsonPath('data.context.id',$a->id)->assertJsonMissingPath('data.version');
    }

    public function test_duplicate_class_validation_uses_rombel_message_for_create_and_update(): void
    {
        $this->tu(); $a=$this->year(); $this->rombel($a,'E1',10); $other=$this->rombel($a,'E2',10);
        $payload=['tahun_akademik_id'=>$a->id,'tingkat'=>10,'nama'=>'E1'];
        foreach ([$this->postJson('/api/classes',$payload),$this->putJson('/api/classes/'.$other->id,$payload)] as $response) {
            $response->assertUnprocessable()->assertJsonPath('errors.nama.0','Nama rombel sudah digunakan pada tingkat dan periode ini.');
        }
        $this->assertDatabaseHas('kelas',['id'=>$other->id,'nama'=>'E2']);
        $this->assertDatabaseCount('kelas',2);
    }
}
