<?php

namespace Tests\Feature;

use App\Models\{AcademicMembershipEvent,AnggotaKelas,Kelas,Role,Siswa,TahunAkademik,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MembershipCandidatesV523Test extends TestCase
{
    use RefreshDatabase;

    private function actor(string $role): User
    {
        return User::create(['role_id'=>Role::firstOrCreate(['code'=>$role],['name'=>$role])->id,
            'username'=>uniqid('candidate'),'name'=>'UAT','password'=>'test-password','account_status'=>'active']);
    }
    private function student(string $name, string $nisn): Siswa
    {
        return Siswa::create(['user_id'=>$this->actor('SISWA')->id,'nama_lengkap'=>$name,'nisn'=>$nisn,'nipd'=>'P'.$nisn]);
    }
    private function klass(int $year, string $name, string $semester='GANJIL'): Kelas
    {
        $period=TahunAkademik::firstOrCreate(['tahun_mulai'=>$year,'tahun_selesai'=>$year+1,'semester'=>$semester],['is_active'=>false]);
        return Kelas::create(['tahun_akademik_id'=>$period->id,'tingkat'=>10,'nama'=>$name]);
    }
    private function member(Siswa $s, Kelas $c, string $start, ?string $end=null): AnggotaKelas
    {
        return AnggotaKelas::create(['kelas_id'=>$c->id,'siswa_id'=>$s->id,'tanggal_mulai'=>$start,'tanggal_selesai'=>$end]);
    }
    private function path(Kelas $c, string $query=''): string
    {
        return "/api/classes/{$c->id}/students/candidates?tanggal_mulai=2026-11-27".$query;
    }
    public function test_candidates_filter_same_period_and_inclusive_dates_before_pagination(): void
    {
        $tu=$this->actor('TU'); Sanctum::actingAs($tu);
        $a=$this->klass(2026,'E1');$b=$this->klass(2026,'E2');$old=$this->klass(2025,'E1');
        $occupied=$this->student('A occupied','001');$this->member($occupied,$a,'2026-07-01');
        $other=$this->student('B other','002');$this->member($other,$b,'2026-07-01');
        $future=$this->student('C future','003');$this->member($future,$b,'2026-12-01');
        $inclusive=$this->student('D inclusive','004');$this->member($inclusive,$b,'2026-07-01','2026-11-27');
        $finished=$this->student('E finished','005');$this->member($finished,$b,'2026-07-01','2026-11-26');
        $previous=$this->student('F previous','006');$this->member($previous,$old,'2025-07-01');
        $free=$this->student('G free','007');
        $terminal=$this->student('H terminal','008');$origin=$this->member($terminal,$old,'2025-07-01','2026-01-01');
        AcademicMembershipEvent::create(['siswa_id'=>$terminal->id,'source_membership_id'=>$origin->id,'actor_id'=>$tu->id,'action'=>'withdraw','effective_on'=>'2026-01-02']);
        $this->getJson($this->path($a,'&per_page=1'))->assertOk()->assertJsonPath('meta.total',3)->assertJsonPath('meta.last_page',3)->assertJsonPath('data.0.id',$finished->id);
        $this->getJson($this->path($a,'&per_page=1&page=2'))->assertOk()->assertJsonPath('data.0.id',$previous->id);
        $this->getJson($this->path($a,'&search=P007'))->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$free->id)->assertJsonPath('data.0.nisn','007');
        $this->getJson($this->path($a,'&search=001'))->assertOk()->assertJsonPath('meta.total',0);
        $this->getJson("/api/classes/{$a->id}/students/candidates?tanggal_mulai=2026-11-28&search=004")->assertOk()->assertJsonPath('data.0.id',$inclusive->id);
        $this->getJson($this->path($a))->assertOk()->assertJsonCount(3,'data')->assertJsonMissingPath('data.0.user_id')->assertJsonMissingPath('data.0.alamat');
        $this->assertDatabaseCount('anggota_kelas',7);
    }
    public function test_candidate_list_is_advisory_and_fresh_preview_still_blocks_new_conflict(): void
    {
        Sanctum::actingAs($this->actor('TU'));$a=$this->klass(2026,'E1');$b=$this->klass(2026,'E2');$s=$this->student('Free','001');
        $this->getJson($this->path($a))->assertOk()->assertJsonPath('meta.total',1);
        $this->member($s,$b,'2026-11-27');
        $this->getJson($this->path($a))->assertOk()->assertJsonPath('meta.total',0);
        $this->postJson("/api/classes/{$a->id}/students/bulk/preview",['siswa_ids'=>[$s->id],'tanggal_mulai'=>'2026-11-27'])->assertOk()->assertJsonPath('data.rows.0.valid',false);
        $this->assertDatabaseCount('anggota_kelas',1);
    }
    public function test_candidate_route_requires_tu_and_valid_input(): void
    {
        $a=$this->klass(2026,'E1');$this->getJson($this->path($a))->assertUnauthorized();
        foreach(['GURU','SISWA','KEPALA_SEKOLAH'] as $role){Sanctum::actingAs($this->actor($role));$this->getJson($this->path($a))->assertForbidden();}
        Sanctum::actingAs($this->actor('TU'));
        $this->getJson("/api/classes/{$a->id}/students/candidates")->assertUnprocessable();
        $this->getJson($this->path($a,'&per_page=101'))->assertUnprocessable();
    }
}
