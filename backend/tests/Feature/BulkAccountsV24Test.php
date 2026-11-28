<?php

namespace Tests\Feature;

use App\Models\Guru;
use App\Models\Siswa;
use App\Models\Role;
use App\Models\User;
use App\Models\TahunAkademik;
use App\Models\Kelas;
use App\Models\AnggotaKelas;
use App\Services\UserActivationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BulkAccountsV24Test extends TestCase
{
    use RefreshDatabase;

    private function account(string $code, string $name, bool $pending = false, bool $linked = false): User
    {
        $role = Role::firstOrCreate(['code'=>$code], ['name'=>$code]);
        $user = User::create(['role_id'=>$role->id,'username'=>$name,'name'=>$name,'password'=>'ExamplePassword123!','account_status'=>'active']);
        $user->forceFill(['requires_activation'=>$pending])->save();
        if ($linked && $code === 'GURU') Guru::create(['user_id'=>$user->id,'nip'=>'NIP-'.$name,'nama_lengkap'=>$name]);
        if ($linked && $code === 'SISWA') Siswa::create(['user_id'=>$user->id,'nisn'=>'NISN-'.$name,'nama_lengkap'=>$name]);
        return $user;
    }

    private function admin(): User
    {
        config(['activation.public_url'=>'https://school.example.test/aktivasi-akun']);
        $actor = $this->account('TU','admin');
        Sanctum::actingAs($actor);
        return $actor;
    }

    private function preview(string $action, array $ids): array
    {
        return $this->postJson('/api/users/bulk/preview',['action'=>$action,'user_ids'=>$ids])->assertOk()->json('data');
    }

    private function confirm(array $preview, array $ids, bool $reissue = false): array
    {
        return ['user_ids'=>$ids,'expires'=>$preview['expires'],'preview_token'=>$preview['preview_token'],'preview_nonce'=>$preview['preview_nonce'],'confirm'=>true,'confirm_reissue'=>$reissue];
    }

    public function test_bulk_requires_tu_and_limits_unique_ids(): void
    {
        Sanctum::actingAs($this->account('GURU','teacher'));
        $this->postJson('/api/users/bulk/preview',['action'=>'issue','user_ids'=>[1]])->assertForbidden();
        $actor=$this->admin();
        $this->postJson('/api/users/bulk/preview',['action'=>'issue','user_ids'=>[$actor->id,$actor->id]])->assertUnprocessable();
        $this->postJson('/api/users/bulk/preview',['action'=>'issue','user_ids'=>range(1,101)])->assertUnprocessable();
    }

    public function test_preview_is_read_only_and_ineligible_batch_is_atomic(): void
    {
        $actor=$this->admin();
        $a=$this->account('GURU','linked',true,true);
        $b=$this->account('SISWA','orphan',true);
        $ids=[$a->id,$b->id,$actor->id];
        $p=$this->preview('issue',$ids);
        $this->assertSame(1,$p['eligible']);
        $this->assertArrayNotHasKey('token_hash',$p['rows'][0]);
        $this->assertDatabaseCount('user_activation_tokens',0);
        $this->postJson('/api/users/bulk/activation-codes',$this->confirm($p,$ids))->assertUnprocessable();
        $this->assertDatabaseCount('user_activation_tokens',0);
    }

    public function test_stale_or_tampered_preview_cannot_write(): void
    {
        $this->admin();$a=$this->account('GURU','a',true,true);$b=$this->account('SISWA','b',true,true);
        $p=$this->preview('deactivate',[$a->id]);
        $this->postJson('/api/users/bulk/deactivate',$this->confirm($p,[$b->id]))->assertConflict();
        $a->update(['name'=>'Changed']);
        $this->postJson('/api/users/bulk/deactivate',$this->confirm($p,[$a->id]))->assertConflict();
        $this->assertSame('active',$a->fresh()->account_status);
        $this->assertSame('active',$b->fresh()->account_status);
    }

    public function test_bulk_deactivation_preserves_profiles_and_revokes_tokens_once(): void
    {
        $actor=$this->admin();$a=$this->account('GURU','a',true,true);$b=$this->account('SISWA','b',false,true);
        $a->createToken('session');$b->createToken('session');
        app(UserActivationService::class)->issue($a,$actor->id);
        $ids=[$a->id,$b->id];$p=$this->preview('deactivate',$ids);$payload=$this->confirm($p,$ids);
        $this->postJson('/api/users/bulk/deactivate',$payload)->assertOk()->assertJsonPath('data.total',2);
        $this->assertSame('inactive',$a->fresh()->account_status);$this->assertTrue($a->fresh()->requires_activation);
        $this->assertSame('inactive',$b->fresh()->account_status);
        $this->assertDatabaseHas('guru',['user_id'=>$a->id]);$this->assertDatabaseHas('siswa',['user_id'=>$b->id]);
        $this->assertDatabaseCount('user_activation_tokens',0);$this->assertDatabaseCount('personal_access_tokens',0);
        $this->postJson('/api/users/bulk/deactivate',$payload)->assertConflict();
    }

    public function test_active_code_needs_explicit_reissue_and_pdf_failure_rolls_back(): void
    {
        $actor=$this->admin();$a=$this->account('GURU','a',true,true);$b=$this->account('SISWA','b',true,true);
        app(UserActivationService::class)->issue($a,$actor->id);
        $hash=DB::table('user_activation_tokens')->where('user_id',$a->id)->value('token_hash');
        $ids=[$a->id,$b->id];$p=$this->preview('issue',$ids);$this->assertSame(1,$p['reissue_count']);
        $this->postJson('/api/users/bulk/activation-codes',$this->confirm($p,$ids))->assertUnprocessable();
        Pdf::shouldReceive('loadView')->once()->andThrow(new \RuntimeException('render failed'));
        $this->postJson('/api/users/bulk/activation-codes',$this->confirm($p,$ids,true))->assertStatus(500);
        $this->assertSame($hash,DB::table('user_activation_tokens')->where('user_id',$a->id)->value('token_hash'));
        $this->assertDatabaseMissing('user_activation_tokens',['user_id'=>$b->id]);
        $this->assertDatabaseCount('account_bulk_receipts',0);
    }

    public function test_pdf_issuance_real_bytes_no_store_and_replay_rejected(): void
    {
        $this->admin();$a=$this->account('GURU','a',true,true);$b=$this->account('SISWA','b',true,true);
        $ids=[$a->id,$b->id];$p=$this->preview('issue',$ids);$payload=$this->confirm($p,$ids);
        $r=$this->postJson('/api/users/bulk/activation-codes',$payload)->assertOk()->assertHeader('Content-Type','application/pdf');
        $this->assertStringStartsWith('%PDF-',$r->getContent());
        $this->assertStringContainsString('no-store',$r->headers->get('Cache-Control'));
        $this->assertDatabaseCount('user_activation_tokens',2);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/',DB::table('user_activation_tokens')->where('user_id',$a->id)->value('token_hash'));
        $this->assertTrue($a->fresh()->requires_activation);
        $this->postJson('/api/users/bulk/activation-codes',$payload)->assertConflict();
        // The underlying snapshot could become identical again after cancellation. Persistent receipt blocks replay.
        DB::table('user_activation_tokens')->whereIn('user_id',$ids)->delete();
        $this->postJson('/api/users/bulk/activation-codes',$payload)->assertConflict();
        $this->assertDatabaseCount('user_activation_tokens',0);
        $this->assertDatabaseCount('account_bulk_receipts',1);
    }

    public function test_global_summary_and_account_filters_distinguish_states(): void
    {
        $this->admin();$a=$this->account('GURU','a',true,true);$b=$this->account('SISWA','b',false,true);$c=$this->account('SISWA','c',true);
        $c->update(['account_status'=>'inactive']);
        $this->getJson('/api/users/activation-summary')->assertOk()->assertJsonPath('data.total',4)->assertJsonPath('data.ready',2)->assertJsonPath('data.pending',1)->assertJsonPath('data.inactive',1)->assertJsonPath('data.unknown',0);
        $this->getJson('/api/users?account_state=pending&profile_state=linked')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$a->id);
        $this->getJson('/api/users?profile_state=unlinked')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$c->id);
    }

    public function test_student_class_filter_rejects_other_period_and_uses_effective_membership(): void
    {
        $this->admin();$a=$this->account('SISWA','a',false,true);$b=$this->account('SISWA','b',true,true);
        $period=TahunAkademik::create(['tahun_mulai'=>2026,'tahun_selesai'=>2027,'semester'=>'GANJIL','is_active'=>true]);
        $other=TahunAkademik::create(['tahun_mulai'=>2026,'tahun_selesai'=>2027,'semester'=>'GENAP','is_active'=>false]);
        $class=Kelas::create(['tahun_akademik_id'=>$period->id,'nama'=>'X A','tingkat'=>10]);
        $wrong=Kelas::create(['tahun_akademik_id'=>$other->id,'nama'=>'X B','tingkat'=>10]);
        AnggotaKelas::create(['siswa_id'=>$a->siswa->id,'kelas_id'=>$class->id,'tanggal_mulai'=>today('Asia/Jakarta')->subDay()->toDateString()]);
        AnggotaKelas::create(['siswa_id'=>$b->siswa->id,'kelas_id'=>$class->id,'tanggal_mulai'=>today('Asia/Jakarta')->addDays(2)->toDateString()]);
        $this->getJson('/api/users?class_id='.$class->id)->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$a->id);
        $this->getJson('/api/users?placement_state=unassigned')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$b->id);
        $this->getJson('/api/users?academic_year_id='.$period->id.'&class_id='.$wrong->id)->assertUnprocessable();
    }

    public function test_expired_preview_and_missing_activation_url_do_not_write(): void
    {
        $this->admin();$a=$this->account('GURU','a',true,true);$p=$this->preview('issue',[$a->id]);
        config(['activation.public_url'=>null]);
        $this->postJson('/api/users/bulk/activation-codes',$this->confirm($p,[$a->id]))->assertUnprocessable();
        config(['activation.public_url'=>'https://school.example.test/aktivasi-akun']);
        $this->travel(6)->minutes();
        $this->postJson('/api/users/bulk/activation-codes',$this->confirm($p,[$a->id]))->assertConflict();
        $this->assertDatabaseCount('user_activation_tokens',0);
    }
}
