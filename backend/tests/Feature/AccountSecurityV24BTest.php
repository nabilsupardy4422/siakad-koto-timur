<?php

namespace Tests\Feature;

use App\Models\Guru;
use App\Models\Role;
use App\Models\User;
use App\Services\ActivationQr;
use App\Services\UserActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountSecurityV24BTest extends TestCase
{
    use RefreshDatabase;
    private function account(string $name, bool $admin=false): User
    {
        $role=Role::firstOrCreate(['code'=>$admin?'TU':'GURU'],['name'=>$admin?'Tata Usaha':'Guru']);
        $user=User::create(['role_id'=>$role->id,'username'=>$name,'name'=>$name,'password'=>'ExamplePassword123!','account_status'=>'active']);
        $user->forceFill(['requires_activation'=>!$admin])->save();
        if (!$admin) Guru::create(['user_id'=>$user->id,'nip'=>'NIP-'.$name,'nama_lengkap'=>$name]);
        return $user;
    }
    private function admin(): User
    {
        config(['activation.public_url'=>'https://school.example.test/aktivasi-akun']);
        $admin=$this->account('tu',true);Sanctum::actingAs($admin);return $admin;
    }
    private function payload(array $ids, bool $replace=false): array
    {
        $p=$this->postJson('/api/users/bulk/preview',['action'=>'issue','user_ids'=>$ids])->assertOk()->json('data');
        return ['user_ids'=>$ids,'expires'=>$p['expires'],'preview_token'=>$p['preview_token'],'preview_nonce'=>$p['preview_nonce'],'confirm'=>true,'confirm_reissue'=>$replace];
    }
    public function test_monitor_and_filter_distinguish_all_states_without_hash(): void
    {
        $admin=$this->admin();$targets=[];
        foreach (['not_issued','valid','expired','locked','inconsistent'] as $state) {
            $u=$this->account($state);$targets[$state]=$u;
            if($state==='not_issued')continue;
            app(UserActivationService::class)->issue($u,$admin->id);
            $values=match($state){'expired'=>['expires_at'=>now()->subMinute()],'locked'=>['failed_attempts'=>5],'inconsistent'=>['used_at'=>now()],default=>[]};
            if($values)DB::table('user_activation_tokens')->where('user_id',$u->id)->update($values);
        }
        $r=$this->getJson('/api/users/activation-summary')->assertOk();
        foreach($targets as $state=>$u){
            $r->assertJsonPath('data.code_monitor.'.$state,1);
            $row=$this->getJson('/api/users?code_state='.$state)->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$u->id)->assertJsonPath('data.0.activation_status.state',$state)->json('data.0');
            $this->assertArrayNotHasKey('token_hash',$row);$this->assertArrayNotHasKey('activation_code',$row);
        }
        $this->getJson('/api/users/'.$targets['valid']->id)->assertOk()->assertJsonPath('data.activation_status.state','valid');
    }
    public function test_single_issue_requires_preview_reissue_and_rejects_replay_after_cancel(): void
    {
        $this->admin();$u=$this->account('target');$url='/api/users/'.$u->id.'/activation-code';
        $this->postJson($url)->assertUnprocessable();
        $p=$this->payload([$u->id]);$this->postJson($url,$p)->assertOk()->assertJsonPath('data.user_id',$u->id);
        $this->postJson($url,$p)->assertConflict();
        $this->postJson($url,$this->payload([$u->id]))->assertUnprocessable();
        $version=$this->getJson($url.'/status')->assertOk()->json('data.code_version');
        $this->deleteJson($url,['code_version'=>$version])->assertOk();
        $this->postJson($url,$p)->assertConflict();$this->assertDatabaseCount('user_activation_tokens',0);
    }
    public function test_stale_cancel_cannot_revoke_replacement_and_old_code_cannot_activate(): void
    {
        $this->admin();$u=$this->account('target');$url='/api/users/'.$u->id.'/activation-code';
        $old=$this->postJson($url,$this->payload([$u->id]))->assertOk()->json('data.activation_code');
        $version=$this->getJson($url.'/status')->assertOk()->json('data.code_version');
        $new=$this->postJson($url,$this->payload([$u->id],true))->assertOk()->json('data.activation_code');
        $this->assertNotSame($old,$new);
        $this->deleteJson($url,['code_version'=>$version])->assertConflict();
        $password='UatExamplePassword123!';$body=['username'=>$u->username,'password'=>$password,'password_confirmation'=>$password];
        $this->postJson('/api/account/activate',[...$body,'activation_code'=>$old])->assertUnprocessable();
        $this->postJson('/api/account/activate',[...$body,'activation_code'=>$new])->assertOk();
        $this->postJson('/api/account/activate',[...$body,'activation_code'=>$new])->assertUnprocessable();
        $this->assertFalse($u->fresh()->requires_activation);
    }
    public function test_monitor_and_cancellation_not_accessible_to_teacher(): void
    {
        $u=$this->account('teacher');$u->forceFill(['requires_activation'=>false])->save();Sanctum::actingAs($u);
        $this->getJson('/api/users/activation-summary')->assertForbidden();
        $this->getJson('/api/users/'.$u->id.'/activation-code/status')->assertForbidden();
        $this->deleteJson('/api/users/'.$u->id.'/activation-code',['code_version'=>str_repeat('a',64)])->assertForbidden();
    }
    public function test_receipt_prune_preserves_recent_and_future(): void
    {
        foreach(['a'=>now()->subDays(2),'b'=>now()->subMinute(),'c'=>now()->addMinute()] as $key=>$expiry)DB::table('account_bulk_receipts')->insert(['preview_hash'=>str_repeat($key,64),'expires_at'=>$expiry,'created_at'=>now()->subDays(3)]);
        $this->artisan('accounts:prune-receipts')->assertExitCode(0);
        $this->assertDatabaseMissing('account_bulk_receipts',['preview_hash'=>str_repeat('a',64)]);$this->assertDatabaseCount('account_bulk_receipts',2);
    }
    public function test_qr_png_is_generated_without_secret_and_reference_matrix(): void
    {
        $url='http://localhost:5173/aktivasi-akun';$png=ActivationQr::pngDataUri($url);
        $this->assertStringStartsWith('data:image/png;base64,',$png);
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n",base64_decode(substr($png,22),true));
        $matrix=ActivationQr::matrix($url);
        $this->assertSame(29,count($matrix));
        $this->assertSame('3cf03b812053f27d3f3a8082cb50a31b898912d593262e60ee9c0c2885014347',hash('sha256',json_encode($matrix)));
    }
    public function test_pdf_100_accounts_fit_25_pages_and_commit_as_one_batch(): void
    {
        $this->admin();$ids=[];
        for($i=0;$i<100;$i++) $ids[]=$this->account('UAT24B-'.str_pad((string)$i,3,'0',STR_PAD_LEFT))->id;
        $start=microtime(true);
        $r=$this->postJson('/api/users/bulk/activation-codes',$this->payload($ids))->assertOk()->assertHeader('Content-Type','application/pdf');
        $bytes=$r->getContent();$this->assertStringStartsWith('%PDF-',$bytes);
        $this->assertSame(25,preg_match_all('/\/Type\s*\/Page\b/',$bytes),'Expected four slips per A4 page');
        $this->assertDatabaseCount('user_activation_tokens',100);$this->assertDatabaseCount('account_bulk_receipts',1);
        fwrite(STDERR,sprintf("\n100-account PDF: %.2f sec, %d bytes\n",microtime(true)-$start,strlen($bytes)));
    }
    public function test_configured_private_lan_can_issue_pdf_in_testing(): void
    {
        $this->admin(); $u=$this->account('lan-target');
        config(['activation.public_url'=>'http://192.168.1.20:5173/aktivasi-akun','activation.lan_host'=>'192.168.1.20']);
        $r=$this->postJson('/api/users/bulk/activation-codes',$this->payload([$u->id]))->assertOk()->assertHeader('Content-Type','application/pdf');
        $this->assertStringStartsWith('%PDF-', $r->getContent());
        $this->assertDatabaseCount('user_activation_tokens',1);
        $this->assertDatabaseCount('account_bulk_receipts',1);
    }

    public function test_unapproved_lan_or_production_http_does_not_issue_codes(): void
    {
        $this->admin(); $u=$this->account('lan-denied');
        $p=$this->payload([$u->id]);
        config(['activation.public_url'=>'http://192.168.1.20:5173/aktivasi-akun','activation.lan_host'=>null]);
        $this->postJson('/api/users/bulk/activation-codes',$p)->assertUnprocessable();
        config(['activation.lan_host'=>'192.168.1.21']);
        $this->postJson('/api/users/bulk/activation-codes',$p)->assertUnprocessable();
        $this->assertDatabaseCount('user_activation_tokens',0);
        $this->assertDatabaseCount('account_bulk_receipts',0);
        $this->assertFalse(\App\Services\ActivationUrlPolicy::allows('http://192.168.1.20:5173/aktivasi-akun','production','192.168.1.20'));
    }
}
