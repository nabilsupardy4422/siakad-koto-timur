<?php

namespace Tests\Feature;

use App\Models\Guru;
use App\Models\Siswa;
use App\Models\User;
use App\Models\Role;
use App\Models\Kelas;
use App\Models\TahunAkademik;
use App\Models\AnggotaKelas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IdentityDirectoryV22Test extends TestCase
{
    use RefreshDatabase;

    private function account(string $code, string $username): User
    {
        $role = Role::firstOrCreate(['code'=>$code], ['name'=>$code]);
        return User::create(['role_id'=>$role->id,'username'=>$username,'name'=>$username,'password'=>'ExamplePassword123!', 'account_status'=>'active']);
    }

    public function test_academic_account_only_creation_is_rejected_and_linked_via_master(): void
    {
        Sanctum::actingAs($this->account('TU','tu'));
        $legacy = $this->account('GURU','Guru.Saintek');
        $this->postJson('/api/users',['username'=>'orphan','name'=>'Orphan','role_id'=>$legacy->role_id,'password'=>'ExamplePassword123!'])->assertUnprocessable();
        $this->assertDatabaseMissing('users',['username'=>'orphan']);
        $this->getJson('/api/users?user_id='.$legacy->id)->assertOk()->assertJsonPath('data.0.academic_profile.status','unlinked');
        $this->getJson('/api/teachers?search=Guru.Saintek')->assertOk()->assertJsonCount(0,'data');
        $id = $this->postJson('/api/teachers',['registration_mode'=>'existing_account','user_id'=>$legacy->id,'nip'=>'NIP-TEST-01','nama_lengkap'=>'Guru Sains Teknologi'])->assertCreated()->json('data.id');
        $this->getJson('/api/teachers?search=Guru.Saintek')->assertOk()->assertJsonPath('data.0.id',$id);
        $this->getJson('/api/users/'.$legacy->id)->assertOk()->assertJsonPath('data.academic_profile.id',$id)->assertJsonPath('data.academic_profile.status','linked');
        $this->assertSame(1,User::where('username','Guru.Saintek')->count());
        $this->assertFalse((bool) $legacy->fresh()->requires_activation);
    }

    public function test_student_link_preserves_identity_and_blocks_duplicate(): void
    {
        Sanctum::actingAs($this->account('TU','tu'));
        $legacy = $this->account('SISWA','old-student');
        $payload = ['registration_mode'=>'existing_account','user_id'=>$legacy->id,'nisn'=>'0000000001','nipd'=>'00001','nama_lengkap'=>'Student'];
        $this->postJson('/api/students',$payload)->assertCreated();
        $this->postJson('/api/students',$payload)->assertUnprocessable();
        $this->getJson('/api/students?search=old-student&directory_context=1')->assertOk()->assertJsonPath('data.0.nipd','00001');
        $this->getJson('/api/users/'.$legacy->id)->assertOk()->assertJsonPath('data.academic_profile.status','linked');
    }

    public function test_pending_orphan_cannot_receive_activation_code(): void
    {
        Sanctum::actingAs($this->account('TU','tu'));
        $user = $this->account('GURU','orphan');
        $user->forceFill(['requires_activation'=>true])->save();
        $this->postJson('/api/users/'.$user->id.'/activation-code')->assertUnprocessable();
        $this->assertDatabaseMissing('user_activation_tokens',['user_id'=>$user->id]);
    }

    public function test_tu_filters_status_and_completeness(): void
    {
        Sanctum::actingAs($this->account('TU','tu'));
        $a=$this->account('GURU','alpha');
        $b=$this->account('GURU','beta');
        $b->forceFill(['requires_activation'=>true])->save();
        Guru::create(['user_id'=>$a->id,'nip'=>'NIP-A','nama_lengkap'=>'Alpha','jenis_kelamin'=>'L','alamat'=>'A','no_telepon'=>'081']);
        Guru::create(['user_id'=>$b->id,'nip'=>'NIP-B','nama_lengkap'=>'Beta']);
        $this->getJson('/api/teachers?account_state=pending&completeness=incomplete')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.nip','NIP-B');
        $this->getJson('/api/teachers?completeness=complete')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.completeness.filled',5);
    }

    public function test_non_owner_cannot_read_private_teacher_fields(): void
    {
        $a=$this->account('GURU','alpha');
        $teacher=Guru::create(['user_id'=>$a->id,'nip'=>'NIP-A','nama_lengkap'=>'Alpha','alamat'=>'private','no_telepon'=>'081']);
        Sanctum::actingAs($this->account('KEPALA_SEKOLAH','principal'));
        $this->getJson('/api/teachers/'.$teacher->id)->assertOk()->assertJsonPath('data.nama_lengkap','Alpha')->assertJsonMissingPath('data.alamat')->assertJsonMissingPath('data.user.username')->assertJsonMissingPath('data.user.email');
        $this->getJson('/api/teachers?search=alpha')->assertOk();
    }

    public function test_role_conversion_to_academic_rejected_and_legacy_import_cannot_write(): void
    {
        Sanctum::actingAs($this->account('TU','tu'));
        $principal=$this->account('KEPALA_SEKOLAH','principal');
        $academic=$this->account('SISWA','existing');
        $this->putJson('/api/users/'.$principal->id,['role_id'=>$academic->role_id])->assertUnprocessable();
        $this->assertSame($principal->role_id,$principal->fresh()->role_id);
        $this->postJson('/api/users/import',[])->assertUnprocessable();
    }

    public function test_directory_placement_is_scoped_to_period_and_effective_dates(): void
    {
        Sanctum::actingAs($this->account('TU','tu'));
        $user=$this->account('SISWA','student');
        $student=Siswa::create(['user_id'=>$user->id,'nisn'=>'0000000001','nama_lengkap'=>'Student']);
        $period=TahunAkademik::create(['tahun_mulai'=>2026,'tahun_selesai'=>2027,'semester'=>'GANJIL','is_active'=>true]);
        $other=TahunAkademik::create(['tahun_mulai'=>2026,'tahun_selesai'=>2027,'semester'=>'GENAP','is_active'=>false]);
        $class=Kelas::create(['tahun_akademik_id'=>$period->id,'nama'=>'X A','tingkat'=>10]);
        $oldClass=Kelas::create(['tahun_akademik_id'=>$other->id,'nama'=>'XI A','tingkat'=>11]);
        AnggotaKelas::create(['siswa_id'=>$student->id,'kelas_id'=>$class->id,'tanggal_mulai'=>now()->subDay()->toDateString(),'tanggal_selesai'=>null]);
        $this->getJson('/api/students?directory_context=1')->assertOk()->assertJsonPath('data.0.placement.class_name','X A');
        $this->getJson('/api/students?directory_context=1&academic_year_id='.$period->id.'&class_id='.$oldClass->id)->assertUnprocessable();
        $this->getJson('/api/students?directory_context=1&academic_year_id='.$other->id)->assertOk()->assertJsonPath('data.0.placement.status','unassigned');
    }

    private function workbook(array $headers, array $rows): \Illuminate\Http\UploadedFile
    {
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $book->getActiveSheet();
        foreach ([$headers, ...$rows] as $r => $row) {
            foreach ($row as $c => $value) {
                $sheet->setCellValueExplicit(chr(65+$c).($r+1), (string) $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'siakad-xlsx-');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        $contents = file_get_contents($path);
        unlink($path);
        return \Illuminate\Http\UploadedFile::fake()->createWithContent('identity.xlsx', $contents);
    }

    public function test_student_import_exclusions_are_authoritative_and_blank_fields_preserved(): void
    {
        Sanctum::actingAs($this->account('TU','tu'));
        $user = $this->account('SISWA','existing-student');
        $student = Siswa::create(['user_id'=>$user->id,'nisn'=>'0000000001','nipd'=>'00001','nama_lengkap'=>'Before','alamat'=>'Keep this']);
        $headers = ['Nama','NIPD','JK','NISN','Tempat Lahir','Tanggal Lahir','Alamat','HP','Rombel Saat Ini'];
        $rows = [['After','00001','L','0000000001','','','','','X A'],['Duplicate','00001','L','0000000002','','','','','X A']];
        $this->post('/api/students/import/preview',['file'=>$this->workbook($headers,$rows)],['Accept'=>'application/json'])->assertOk()->assertJsonPath('data.summary.invalid_rows',2);
        $preview = $this->post('/api/students/import/preview',['file'=>$this->workbook($headers,$rows),'excluded_rows'=>[3]],['Accept'=>'application/json'])->assertOk()->assertJsonPath('data.summary.valid_rows',1);
        $this->assertSame('Before',$student->fresh()->nama_lengkap);
        $this->post('/api/students/import',['file'=>$this->workbook($headers,$rows),'excluded_rows'=>[3],'preview_token'=>$preview->json('data.preview_token')],['Accept'=>'application/json'])->assertOk()->assertJsonPath('data.total_imported',1);
        $this->assertSame('Keep this',$student->fresh()->alamat);
        $this->assertSame('After',$user->fresh()->name);
        $this->assertDatabaseMissing('siswa',['nisn'=>'0000000002']);
    }

    public function test_teacher_import_creates_account_and_profile_only_after_preview(): void
    {
        Sanctum::actingAs($this->account('TU','tu'));
        Role::firstOrCreate(['code'=>'GURU'],['name'=>'Guru']);
        $headers=['username','nip','nama_lengkap','jenis_kelamin','no_telepon','alamat'];
        $rows=[['','000000000000000001','Teacher','L','','']];
        $preview=$this->post('/api/teachers/import/preview',['file'=>$this->workbook($headers,$rows),'registration_mode'=>'new_account'],['Accept'=>'application/json'])->assertOk()->assertJsonPath('data.summary.valid_rows',1);
        $this->assertDatabaseMissing('guru',['nip'=>'000000000000000001']);
        $this->post('/api/teachers/import',['file'=>$this->workbook($headers,$rows),'registration_mode'=>'new_account','preview_token'=>$preview->json('data.preview_token')],['Accept'=>'application/json'])->assertOk();
        $this->assertDatabaseHas('guru',['nip'=>'000000000000000001']);
        $this->assertDatabaseHas('users',['username'=>'000000000000000001','requires_activation'=>true]);
    }
}
