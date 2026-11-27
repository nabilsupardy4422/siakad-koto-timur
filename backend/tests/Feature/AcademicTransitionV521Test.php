<?php
namespace Tests\Feature;

use App\Models\{AcademicMembershipEvent,AnggotaKelas,Kelas,Role,Siswa,TahunAkademik,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AcademicTransitionV521Test extends TestCase {
    use RefreshDatabase;
    private function actor(string $role='TU'): User {
        return User::create(['role_id'=>Role::firstOrCreate(['code'=>$role],['name'=>$role])->id,'username'=>uniqid('transition'),'name'=>'Uji','password'=>'test-password','account_status'=>'active']);
    }
    private function period(int $year,string $semester,bool $active=false): TahunAkademik {return TahunAkademik::create(['tahun_mulai'=>$year,'tahun_selesai'=>$year+1,'semester'=>$semester,'is_active'=>$active]);}
    private function klass(TahunAkademik $year,int $level,string $name): Kelas {return Kelas::create(['tahun_akademik_id'=>$year->id,'tingkat'=>$level,'nama'=>$name]);}
    private function member(Kelas $class,string $id,string $start='2026-07-01',?string $end=null): AnggotaKelas {
        $s=Siswa::create(['user_id'=>$this->actor('SISWA')->id,'nisn'=>$id,'nipd'=>'P'.$id,'nama_lengkap'=>'Uji '.$id]);
        return AnggotaKelas::create(['kelas_id'=>$class->id,'siswa_id'=>$s->id,'tanggal_mulai'=>$start,'tanggal_selesai'=>$end]);
    }
    private function payload(Kelas $from,Kelas $to,AnggotaKelas $member,string $date,string $action='continue'):array {return ['source_period_id'=>$from->tahun_akademik_id,'target_period_id'=>$to->tahun_akademik_id,'effective_on'=>$date,'items'=>[['membership_id'=>$member->id,'action'=>$action,'destination_class_id'=>$to->id]]];}
    private function token(array $payload):string {return $this->postJson('/api/academic-transitions/preview',$payload)->assertOk()->assertJsonPath('data.rows.0.valid',true)->json('data.preview_token');}
    public function test_calendar_wib_and_conflict_information_after_utc_midnight_boundary():void {
        $this->travelTo(Carbon::parse('2026-11-26 18:00:00','UTC'));
        Sanctum::actingAs($this->actor());$y=$this->period(2026,'GANJIL',true);$a=$this->klass($y,10,'E1');$b=$this->klass($y,10,'E2');$m=$this->member($a,'00001','2026-11-27');
        $this->getJson("/api/classes/{$a->id}/students")->assertOk()->assertJsonPath('meta.as_of','2026-11-27')->assertJsonPath('meta.total',1);
        $this->getJson("/api/membership-directory?academic_year_id={$y->id}")->assertOk()->assertJsonPath('data.placed_students',1)->assertJsonPath('data.classes.0.effective_count',1);
        $this->postJson("/api/classes/{$b->id}/students/bulk/preview",['siswa_ids'=>[$m->siswa_id],'tanggal_mulai'=>'2026-11-27'])->assertOk()->assertJsonPath('data.rows.0.valid',false)->assertJsonPath('data.rows.0.conflicts.0.nama','E1');
        $this->travelBack();
    }
    public function test_same_period_mutation_is_atomic_and_replay_fails():void {
        $this->travelTo(Carbon::parse('2026-11-27 08:00:00','Asia/Jakarta'));Sanctum::actingAs($this->actor());$y=$this->period(2026,'GANJIL',true);$a=$this->klass($y,10,'E1');$b=$this->klass($y,10,'E2');$m=$this->member($a,'00002');
        $p=$this->payload($a,$b,$m,'2026-11-28','move');$p['preview_token']=$this->token($p);$this->assertNull($m->fresh()->tanggal_selesai);$this->assertDatabaseCount('academic_membership_events',0);
        $this->postJson('/api/academic-transitions',$p)->assertCreated();
        $this->assertSame('2026-11-27',$m->fresh()->tanggal_selesai->toDateString());
        // DATE storage differs between SQLite and MySQL; verify the actual cast date and destination identity.
        $destinations=AnggotaKelas::query()->where('kelas_id',$b->id)->where('siswa_id',$m->siswa_id)->get();
        $this->assertCount(1,$destinations);
        $this->assertSame('2026-11-28',$destinations->first()->tanggal_mulai->toDateString());
        $this->assertNull($destinations->first()->tanggal_selesai);
        $this->getJson("/api/classes/{$a->id}/students")->assertOk()->assertJsonPath('meta.total',1);
        $this->getJson("/api/classes/{$b->id}/students")->assertOk()->assertJsonPath('meta.total',0);
        $this->postJson('/api/academic-transitions',$p)->assertConflict();$this->assertDatabaseCount('academic_membership_events',1);$this->assertDatabaseCount('anggota_kelas',2);
        $this->travelTo(Carbon::parse('2026-11-28 00:00:00','Asia/Jakarta'));
        $this->getJson("/api/classes/{$a->id}/students")->assertOk()->assertJsonPath('meta.total',0);
        $this->getJson("/api/classes/{$b->id}/students")->assertOk()->assertJsonPath('meta.total',1);
        $this->travelBack();
    }
    public function test_semester_transition_keeps_period_active_and_account_unchanged():void {
        $this->travelTo(Carbon::parse('2026-11-27 08:00:00','Asia/Jakarta'));Sanctum::actingAs($this->actor());$y=$this->period(2026,'GANJIL',true);$z=$this->period(2026,'GENAP');$a=$this->klass($y,10,'E1');$b=$this->klass($z,10,'E1');$m=$this->member($a,'00003');$user=$m->siswa->user;$before=$user->getRawOriginal('password');
        $p=$this->payload($a,$b,$m,'2027-01-04');$p['preview_token']=$this->token($p);$this->postJson('/api/academic-transitions',$p)->assertCreated();
        $this->assertTrue($y->fresh()->is_active);$this->assertFalse($z->fresh()->is_active);$this->assertSame($before,$user->fresh()->getRawOriginal('password'));$this->assertSame('active',$user->fresh()->account_status);
        $this->getJson("/api/membership-history/{$m->siswa_id}")->assertOk()->assertJsonCount(2,'data.memberships')->assertJsonPath('data.events.0.action','continue');$this->travelBack();
    }
    public function test_year_promotion_and_retain_with_per_student_override():void {
        $this->travelTo(Carbon::parse('2027-06-30 08:00:00','Asia/Jakarta'));Sanctum::actingAs($this->actor());$y=$this->period(2026,'GENAP',true);$z=$this->period(2027,'GANJIL');$a=$this->klass($y,10,'E1');$b=$this->klass($z,11,'F1');$c=$this->klass($z,10,'E2');$m=$this->member($a,'00004','2027-01-01');$n=$this->member($a,'00005','2027-01-01');
        $p=$this->payload($a,$b,$m,'2027-07-01','promote');$p['items'][]=['membership_id'=>$n->id,'action'=>'retain','destination_class_id'=>$c->id];$p['preview_token']=$this->token($p);$this->postJson('/api/academic-transitions',$p)->assertCreated()->assertJsonPath('data.total_processed',2);$this->assertDatabaseCount('academic_membership_events',2);$this->assertDatabaseHas('anggota_kelas',['kelas_id'=>$c->id,'siswa_id'=>$n->siswa_id]);$this->travelBack();
    }
    public function test_graduation_keeps_identity_and_prevents_placement():void {
        $this->travelTo(Carbon::parse('2027-07-01 08:00:00','Asia/Jakarta'));Sanctum::actingAs($this->actor());$y=$this->period(2026,'GENAP',true);$z=$this->period(2027,'GANJIL');$a=$this->klass($y,12,'F1');$b=$this->klass($z,12,'F1');$m=$this->member($a,'00006','2027-01-01');$p=$this->payload($a,$b,$m,'2027-07-01','graduate');$p['items'][0]['destination_class_id']=null;$p['preview_token']=$this->token($p);
        $this->postJson('/api/academic-transitions',$p)->assertCreated();$this->assertDatabaseCount('anggota_kelas',1);$this->assertDatabaseCount('siswa',1);$this->assertSame('active',$m->siswa->user->fresh()->account_status);
        $this->getJson("/api/membership-history/{$m->siswa_id}")->assertOk()->assertJsonPath('data.academic_status','graduate');$this->postJson("/api/classes/{$b->id}/students",['siswa_id'=>$m->siswa_id,'tanggal_mulai'=>'2027-07-01'])->assertUnprocessable();$this->travelBack();
    }
    public function test_conflicting_batch_never_partially_changes_memberships():void {
        $this->travelTo(Carbon::parse('2026-11-27 08:00:00','Asia/Jakarta'));Sanctum::actingAs($this->actor());$y=$this->period(2026,'GANJIL',true);$a=$this->klass($y,10,'E1');$b=$this->klass($y,10,'E2');$m=$this->member($a,'00007');$n=$this->member($a,'00008');$p=$this->payload($a,$b,$m,'2026-11-28','move');$p['items'][]=['membership_id'=>$n->id,'action'=>'move','destination_class_id'=>$b->id];
        $p['preview_token']=$this->token($p);AnggotaKelas::create(['kelas_id'=>$b->id,'siswa_id'=>$n->siswa_id,'tanggal_mulai'=>'2026-12-01']);$this->postJson('/api/academic-transitions',$p)->assertConflict();$this->assertNull($m->fresh()->tanggal_selesai);$this->assertNull($n->fresh()->tanggal_selesai);$this->assertDatabaseCount('academic_membership_events',0);$this->travelBack();
    }
    public function test_non_tu_cannot_access_transition_endpoints():void {
        Sanctum::actingAs($this->actor('GURU'));$this->getJson('/api/membership-directory?academic_year_id=1')->assertForbidden();$this->postJson('/api/academic-transitions/preview',[])->assertForbidden();$this->postJson('/api/academic-transitions',[])->assertForbidden();
    }
    public function test_invalid_level_and_skip_period_are_rejected():void {
        $this->travelTo(Carbon::parse('2026-11-27 08:00:00','Asia/Jakarta'));Sanctum::actingAs($this->actor());$y=$this->period(2026,'GANJIL',true);$z=$this->period(2026,'GENAP');$next=$this->period(2027,'GANJIL');$a=$this->klass($y,10,'E1');$b=$this->klass($z,11,'F1');$c=$this->klass($next,11,'F1');$m=$this->member($a,'00009');$p=$this->payload($a,$b,$m,'2027-01-04','continue');$this->postJson('/api/academic-transitions/preview',$p)->assertOk()->assertJsonPath('data.rows.0.valid',false);$this->postJson('/api/academic-transitions/preview',$this->payload($a,$c,$m,'2027-07-01','promote'))->assertUnprocessable();$this->travelBack();
    }
}
