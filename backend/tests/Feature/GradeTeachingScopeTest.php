<?php

namespace Tests\Feature;

use App\Models\AnggotaKelas;
use App\Models\Assignment;
use App\Models\Guru;
use App\Models\JadwalPelajaran;
use App\Models\Kelas;
use App\Models\KomponenNilai;
use App\Models\Mapel;
use App\Models\Role;
use App\Models\Siswa;
use App\Models\TahunAkademik;
use App\Models\User;
use App\Models\WaliKelas;
use App\Models\Nilai;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GradeTeachingScopeTest extends TestCase
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
        Guru $guru
    ): JadwalPelajaran {
        return JadwalPelajaran::create([
            'tahun_akademik_id' => $academicYear->id,
            'kelas_id' => $class->id,
            'mapel_id' => $this->createSubject()->id,
            'guru_id' => $guru->id,
            'hari' => 'SENIN',
            'jam_mulai' => '07:00',
            'jam_selesai' => '08:00',
        ]);
    }

    private function createMembership(
        Siswa $student,
        Kelas $class
    ): AnggotaKelas {
        return AnggotaKelas::create([
            'kelas_id' => $class->id,
            'siswa_id' => $student->id,
            'tanggal_mulai' => today('Asia/Jakarta')->subDay()->toDateString(),
            'tanggal_selesai' => null,
        ]);
    }

    private function createComponent(
        JadwalPelajaran $schedule,
        string $name = 'Tugas 1',
        string $weight = '25.00'
    ): KomponenNilai {
        return KomponenNilai::create([
            'jadwal_pelajaran_id' => $schedule->id,
            'nama' => $name,
            'bobot' => $weight,
        ]);
    }

    private function createAssignment(
        JadwalPelajaran $schedule,
        User $creator,
        array $attributes = []
    ): Assignment {
        return Assignment::create(array_merge([
            'jadwal_pelajaran_id' => $schedule->id,
            'judul' => 'Tugas Test',
            'deskripsi' => 'Deskripsi tugas test',
            'deadline' => '2026-10-10 23:59:00',
            'created_by' => $creator->id,
        ], $attributes));
    }

    public function test_explicit_teaching_and_homeroom_reads_are_separate(): void
    {
        [$user,$guru]=$this->createTeacher();
        [, $peer]=$this->createTeacher();
        [, $student]=$this->createStudent();
        $year=$this->createAcademicYear();
        $class=$this->createClass($year);
        $this->createMembership($student,$class);
        $own=$this->createSchedule($year,$class,$guru);
        $other=$this->createSchedule($year,$class,$peer);
        $other->update(['mapel_id'=>$own->mapel_id,'hari'=>'SELASA']);
        $assignment=WaliKelas::create(['guru_id'=>$guru->id,'kelas_id'=>$class->id,'tahun_akademik_id'=>$year->id,'tanggal_mulai'=>today('Asia/Jakarta')->subDay()->toDateString(),'tanggal_selesai'=>null]);
        $a=$this->createComponent($own);$b=$this->createComponent($other);
        $g=Nilai::create(['komponen_nilai_id'=>$a->id,'siswa_id'=>$student->id,'nilai'=>85]);
        $peerGrade=Nilai::create(['komponen_nilai_id'=>$b->id,'siswa_id'=>$student->id,'nilai'=>95]);
        Sanctum::actingAs($user);
        $this->getJson('/api/grade-components?scope=teaching')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$a->id);
        $this->getJson("/api/grade-components/{$b->id}?scope=teaching")->assertForbidden();
        $this->getJson('/api/grades?scope=teaching')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$g->id);
        $this->getJson('/api/grades/recap?scope=teaching')->assertOk()->assertJsonPath('data.total',1)->assertJsonPath('data.rata_rata',85);
        $this->getJson('/api/grades?scope=homeroom')->assertOk()->assertJsonCount(2,'data');
        $this->getJson("/api/grade-components/{$b->id}?scope=homeroom")->assertOk();
        $this->putJson("/api/grades/{$peerGrade->id}",['nilai'=>90])->assertForbidden();
        $assignment->update(['tanggal_selesai'=>today('Asia/Jakarta')->subDay()->toDateString()]);
        $this->getJson('/api/grades?scope=homeroom')->assertOk()->assertJsonCount(0,'data');
        $this->getJson('/api/grades')->assertOk()->assertJsonCount(1,'data');
        $this->getJson("/api/grade-components/{$b->id}")->assertForbidden();
    }

    public function test_inactive_period_cannot_be_managed_but_legacy_history_remains(): void
    {
        [$user,$guru]=$this->createTeacher();
        [, $student]=$this->createStudent();
        $this->createAcademicYear();
        $old=$this->createAcademicYear(2025,2026,'GENAP',false);
        $class=$this->createClass($old);$this->createMembership($student,$class);
        $schedule=$this->createSchedule($old,$class,$guru);
        $a=$this->createComponent($schedule);
        $g=Nilai::create(['komponen_nilai_id'=>$a->id,'siswa_id'=>$student->id,'nilai'=>75]);
        Sanctum::actingAs($user);
        $this->getJson('/api/grade-components?scope=teaching')->assertOk()->assertJsonCount(0,'data');
        $this->getJson("/api/grade-components/{$a->id}?scope=teaching")->assertForbidden();
        $this->getJson('/api/grades?scope=teaching')->assertOk()->assertJsonCount(0,'data');
        $this->getJson('/api/grades?academic_year_id='.$old->id)->assertOk()->assertJsonCount(1,'data');
        $this->postJson('/api/grade-components',['jadwal_pelajaran_id'=>$schedule->id,'nama'=>'New','bobot'=>10])->assertForbidden();
        $this->putJson("/api/grade-components/{$a->id}",['nama'=>'No'])->assertForbidden();
        $this->deleteJson("/api/grade-components/{$a->id}")->assertForbidden();
        $this->postJson('/api/grades',['komponen_nilai_id'=>$a->id,'siswa_id'=>$student->id,'nilai'=>80])->assertForbidden();
        $this->putJson("/api/grades/{$g->id}",['nilai'=>90])->assertForbidden();
    }

    public function test_score_range_membership_and_duplicate_are_validated_for_writes(): void
    {
        // UTC is still Nov 26 while the school's calendar is already Nov 27.
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-26 18:00:00', 'UTC'));
        $this->assertSame('2026-11-26', today('UTC')->toDateString());
        $this->assertSame('2026-11-27', today('Asia/Jakarta')->toDateString());
        [$user,$guru]=$this->createTeacher(); [, $student]=$this->createStudent();
        $year=$this->createAcademicYear();$class=$this->createClass($year);
        $member=$this->createMembership($student,$class);
        $a=$this->createComponent($this->createSchedule($year,$class,$guru));
        Sanctum::actingAs($user);
        $payload=['komponen_nilai_id'=>$a->id,'siswa_id'=>$student->id];
        foreach ([-1,101] as $score) {
            $this->postJson('/api/grades',$payload+['nilai'=>$score])->assertUnprocessable()->assertJsonValidationErrors('nilai');
        }
        $member->update(['tanggal_mulai'=>today('Asia/Jakarta')->addDay()->toDateString()]);
        $this->postJson('/api/grades',$payload+['nilai'=>80])->assertUnprocessable()->assertJsonValidationErrors('siswa_id');
        $member->update(['tanggal_mulai'=>today('Asia/Jakarta')->subDay()->toDateString(),'tanggal_selesai'=>today('Asia/Jakarta')->toDateString()]);
        $id=$this->postJson('/api/grades',$payload+['nilai'=>0])->assertCreated()->assertJsonPath('data.nilai','0.00')->json('data.id');
        $this->postJson('/api/grades',$payload+['nilai'=>85])->assertUnprocessable()->assertJsonValidationErrors('siswa_id');
        $this->putJson("/api/grades/{$id}",['nilai'=>100])->assertOk()->assertJsonPath('data.nilai','100.00');
        $this->putJson("/api/grades/{$id}",['nilai'=>101])->assertUnprocessable()->assertJsonValidationErrors('nilai');
        $member->update(['tanggal_selesai'=>today('Asia/Jakarta')->subDay()->toDateString()]);
        $this->putJson("/api/grades/{$id}",['nilai'=>90])->assertUnprocessable()->assertJsonValidationErrors('siswa_id');
        $this->assertDatabaseHas('nilai',['id'=>$id,'nilai'=>100]);
    }

    public function test_linked_components_cannot_be_deleted_or_moved(): void
    {
        [$user,$guru]=$this->createTeacher(); [, $student]=$this->createStudent();
        $year=$this->createAcademicYear();$class=$this->createClass($year);
        $this->createMembership($student,$class);
        $one=$this->createSchedule($year,$class,$guru);$two=$this->createSchedule($year,$class,$guru);
        $a=$this->createComponent($one,'Tugas 1');$b=$this->createComponent($one,'UTS');
        $task=$this->createAssignment($one,$user,['komponen_nilai_id'=>$a->id]);
        Nilai::create(['komponen_nilai_id'=>$b->id,'siswa_id'=>$student->id,'nilai'=>80]);
        Sanctum::actingAs($user);
        foreach ([$a,$b] as $component) {
            $this->deleteJson("/api/grade-components/{$component->id}")->assertStatus(409);
            $this->putJson("/api/grade-components/{$component->id}",['jadwal_pelajaran_id'=>$two->id])->assertUnprocessable();
            $this->assertDatabaseHas('komponen_nilai',['id'=>$component->id,'jadwal_pelajaran_id'=>$one->id]);
        }
        $this->assertDatabaseHas('assignments',['id'=>$task->id,'komponen_nilai_id'=>$a->id]);
        $this->putJson("/api/grade-components/{$a->id}",['nama'=>'Tugas Harian','bobot'=>25])->assertOk();
    }

    public function test_partial_component_move_checks_name_and_weight_without_forcing_total(): void
    {
        [$user,$guru]=$this->createTeacher();$year=$this->createAcademicYear();$class=$this->createClass($year);
        $one=$this->createSchedule($year,$class,$guru);$two=$this->createSchedule($year,$class,$guru);
        $a=$this->createComponent($one);$this->createComponent($two);
        Sanctum::actingAs($user);
        $this->putJson("/api/grade-components/{$a->id}",['jadwal_pelajaran_id'=>$two->id])->assertUnprocessable()->assertJsonValidationErrors('nama');
        $this->putJson("/api/grade-components/{$a->id}",['bobot'=>-1])->assertUnprocessable()->assertJsonValidationErrors('bobot');
        $this->putJson("/api/grade-components/{$a->id}",['bobot'=>1000])->assertUnprocessable()->assertJsonValidationErrors('bobot');
        $this->putJson("/api/grade-components/{$a->id}",['bobot'=>35])->assertOk();
        $this->putJson("/api/grade-components/{$a->id}",['jadwal_pelajaran_id'=>$two->id,'nama'=>'Unused'])->assertOk();
        $this->deleteJson("/api/grade-components/{$a->id}")->assertOk();
    }

    public function test_non_teacher_cannot_claim_scope_and_invalid_scope_is_rejected(): void
    {
        [$user,$guru]=$this->createTeacher();
        [$studentUser,$student]=$this->createStudent();
        $year=$this->createAcademicYear();$class=$this->createClass($year);$this->createMembership($student,$class);
        $a=$this->createComponent($this->createSchedule($year,$class,$guru));
        Nilai::create(['komponen_nilai_id'=>$a->id,'siswa_id'=>$student->id,'nilai'=>80]);
        Sanctum::actingAs($studentUser);
        $this->getJson('/api/grades?scope=teaching')->assertForbidden();
        $this->getJson('/api/grade-components?scope=homeroom')->assertForbidden();
        $this->getJson('/api/grades')->assertOk()->assertJsonCount(1,'data');
        Sanctum::actingAs($user);
        $this->getJson('/api/grades?scope=other')->assertUnprocessable();
        $this->getJson('/api/grade-components?scope=other')->assertUnprocessable();
        $year->update(['is_active'=>false]);
        $this->getJson('/api/grades?scope=teaching')->assertOk()->assertJsonCount(0,'data');
        $this->putJson("/api/grade-components/{$a->id}",['bobot'=>5])->assertForbidden();
    }
}
