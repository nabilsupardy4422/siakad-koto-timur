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

class ScheduleV70B2Test extends TestCase
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

    private function setupCopy(): array
    {
        $actor=$this->createUser('TU'); Sanctum::actingAs($actor);
        $source=$this->createAcademicYear(); $target=$this->createAcademicYear(2026,2027,'GENAP',false);
        $a=$this->createClass($source,'IPA 1',10); $b=$this->createClass($target,'IPA 1',10);
        $m=$this->createSubject(); [$u,$g]=$this->createTeacher();
        $s=$this->createSchedule($source,$a,$m,$g);
        $payload=['mode'=>'preview','source_period_id'=>$source->id,'target_period_id'=>$target->id,'mappings'=>[['source_class_id'=>$a->id,'target_class_id'=>$b->id]]];
        return compact('actor','source','target','a','b','m','u','g','s','payload');
    }
    public function test_copy_preview_is_read_only_commit_preserves_history_and_active_period(): void
    {
        extract($this->setupCopy());
        \Illuminate\Support\Facades\DB::table('komponen_nilai')->insert(['jadwal_pelajaran_id'=>$s->id,'nama'=>'Tugas','bobot'=>100]);
        $p=$this->postJson('/api/schedules/copy-semester',$payload)->assertOk()->assertJsonPath('data.count',1);
        $this->assertDatabaseCount('jadwal_pelajaran',1); $this->assertDatabaseCount('schedule_copy_receipts',0);
        $commit=array_replace($payload,['mode'=>'commit','token'=>$p->json('data.token')]);
        $this->postJson('/api/schedules/copy-semester',$commit)->assertOk()->assertJsonPath('data.count',1);
        $this->assertDatabaseCount('jadwal_pelajaran',2); $this->assertDatabaseCount('komponen_nilai',1);
        $this->assertDatabaseHas('jadwal_pelajaran',['id'=>$s->id,'kelas_id'=>$a->id]);
        $this->assertDatabaseHas('jadwal_pelajaran',['tahun_akademik_id'=>$target->id,'kelas_id'=>$b->id,'guru_id'=>$g->id]);
        $this->assertTrue($source->fresh()->is_active); $this->assertFalse($target->fresh()->is_active);
        JadwalPelajaran::where('tahun_akademik_id',$target->id)->delete();
        $this->postJson('/api/schedules/copy-semester',$commit)->assertConflict();
        $this->assertDatabaseCount('jadwal_pelajaran',1);
    }
    public function test_wrong_year_wrong_level_incomplete_and_non_tu_are_rejected(): void
    {
        extract($this->setupCopy());
        $other=$this->createAcademicYear(2027,2028,'GENAP',false);
        $this->postJson('/api/schedules/copy-semester',array_replace($payload,['target_period_id'=>$other->id]))->assertUnprocessable();
        $wrong=$this->createClass($target,'IPA 2',11);
        $this->postJson('/api/schedules/copy-semester',array_replace($payload,['mappings'=>[['source_class_id'=>$a->id,'target_class_id'=>$wrong->id]]]))->assertUnprocessable();
        $a2=$this->createClass($source,'IPA 2',10); $this->createSchedule($source,$a2,$m,$g,'SELASA');
        $this->postJson('/api/schedules/copy-semester',$payload)->assertUnprocessable();
        Sanctum::actingAs($u); $this->postJson('/api/schedules/copy-semester',$payload)->assertForbidden();
        $this->assertDatabaseCount('jadwal_pelajaran',2);
    }
    public function test_destination_change_invalidates_preview_and_conflict_never_partially_writes(): void
    {
        extract($this->setupCopy());
        $p=$this->postJson('/api/schedules/copy-semester',$payload)->assertOk();
        $this->createSchedule($target,$b,$m,$g,'SENIN','07:30','08:30');
        $this->postJson('/api/schedules/copy-semester',array_replace($payload,['mode'=>'commit','token'=>$p->json('data.token')]))->assertConflict();
        $this->postJson('/api/schedules/copy-semester',$payload)->assertUnprocessable();
        $this->assertDatabaseCount('jadwal_pelajaran',2); $this->assertDatabaseCount('schedule_copy_receipts',0);
    }
    public function test_inactive_teacher_rejected_and_pending_teacher_allowed(): void
    {
        extract($this->setupCopy());
        $this->assertFalse($u->fresh()->requires_activation);
        $this->postJson('/api/schedules/copy-semester',$payload)
            ->assertOk()->assertJsonPath('data.rows.0.account_state','ready');
        $u->update(['account_status'=>'inactive']);
        $this->postJson('/api/schedules/copy-semester',$payload)->assertUnprocessable();
        // Activation state is intentionally guarded against mass assignment.
        // Set it explicitly in this test fixture, not in production input.
        $u->forceFill(['account_status'=>'active','requires_activation'=>true])->save();
        $this->assertTrue($u->fresh()->requires_activation);
        $preview=$this->postJson('/api/schedules/copy-semester',$payload)
            ->assertOk()->assertJsonPath('data.rows.0.account_state','pending');
        $this->assertDatabaseCount('jadwal_pelajaran',1);
        $this->postJson('/api/schedules/copy-semester',array_replace($payload,[
            'mode'=>'commit','token'=>$preview->json('data.token'),
        ]))->assertOk()->assertJsonPath('data.count',1);
        $this->assertDatabaseCount('jadwal_pelajaran',2);
        $this->assertDatabaseHas('jadwal_pelajaran',[
            'tahun_akademik_id'=>$target->id,'kelas_id'=>$b->id,'guru_id'=>$g->id,
        ]);
    }
    public function test_token_bound_to_actor_and_source_changes(): void
    {
        extract($this->setupCopy());
        $p=$this->postJson('/api/schedules/copy-semester',$payload)->assertOk();
        $commit=array_replace($payload,['mode'=>'commit','token'=>$p->json('data.token')]);
        Sanctum::actingAs($this->createUser('TU')); $this->postJson('/api/schedules/copy-semester',$commit)->assertConflict();
        Sanctum::actingAs($actor); $s->update(['hari'=>'SELASA']); $this->postJson('/api/schedules/copy-semester',$commit)->assertConflict();
        $this->assertDatabaseCount('jadwal_pelajaran',1);
    }
    public function test_expired_preview_and_legacy_source_conflict_rejected(): void
    {
        extract($this->setupCopy());
        $p=$this->postJson('/api/schedules/copy-semester',$payload)->assertOk();
        $this->travel(6)->minutes();
        $this->postJson('/api/schedules/copy-semester',array_replace($payload,['mode'=>'commit','token'=>$p->json('data.token')]))->assertConflict();
        $this->travelBack();
        $this->createSchedule($source,$a,$m,$g,'SENIN','07:30','08:30');
        $this->postJson('/api/schedules/copy-semester',$payload)->assertUnprocessable();
        $this->assertDatabaseCount('jadwal_pelajaran',2); $this->assertDatabaseCount('schedule_copy_receipts',0);
    }
    public function test_distribution_polish_preserves_cells_and_print_setup(): void
    {
        extract($this->setupCopy());
        $response=$this->get('/api/schedules/export?format=teacher&academic_year_id='.$source->id.'&teacher_id='.$g->id)->assertOk();
        $path=$response->baseResponse->getFile()->getPathname();
        try {
            $book=\PhpOffice\PhpSpreadsheet\IOFactory::load($path); $sheet=$book->getActiveSheet();
            $this->assertSame($g->nama_lengkap,$sheet->getCell('A4')->getValue());
            $this->assertSame('SENIN',$sheet->getCell('A8')->getValue());
            $this->assertFalse($sheet->getShowGridlines());
            $this->assertSame(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4,$sheet->getPageSetup()->getPaperSize());
            $this->assertSame('A1:E8',$sheet->getPageSetup()->getPrintArea());
            $book->disconnectWorksheets();
        } finally { @unlink($path); }
    }
}
