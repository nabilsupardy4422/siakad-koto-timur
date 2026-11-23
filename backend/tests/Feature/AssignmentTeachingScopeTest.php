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
use App\Models\SubmissionTugas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssignmentTeachingScopeTest extends TestCase
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
            'tanggal_mulai' => '2026-07-01',
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

    public function test_teacher_list_detail_and_attachments_are_isolated_even_in_homeroom(): void
    {
        [$user, $guru] = $this->createTeacher();
        [$peerUser, $peer] = $this->createTeacher();
        $year = $this->createAcademicYear();
        $class = $this->createClass($year);
        $own = $this->createSchedule($year, $class, $guru);
        $other = $this->createSchedule($year, $class, $peer);
        $other->update(['mapel_id'=>$own->mapel_id, 'hari'=>'SELASA']);
        WaliKelas::create(['guru_id'=>$guru->id,'kelas_id'=>$class->id,'tahun_akademik_id'=>$year->id,'tanggal_mulai'=>'2026-07-01','tanggal_selesai'=>null]);
        $a = $this->createAssignment($own,$user);
        $b = $this->createAssignment($other,$peerUser);
        $attachment = $b->lampiran()->create(['file_path'=>'assignments/peer.pdf','file_name'=>'peer.pdf','file_mime_type'=>'application/pdf','file_size'=>1]);
        Sanctum::actingAs($user);
        $this->getJson('/api/assignments')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$a->id)->assertJsonPath('data.0.jadwal_pelajaran.guru_id',$guru->id);
        $this->getJson("/api/assignments/{$b->id}")->assertForbidden();
        $this->getJson("/api/assignments/{$b->id}/download")->assertForbidden();
        $this->getJson("/api/assignment-attachments/{$attachment->id}/download")->assertForbidden();
        $this->getJson("/api/assignments/{$b->id}/submissions")->assertForbidden();
    }

    public function test_inactive_period_denies_teacher_direct_access_including_submission_files(): void
    {
        [$user,$guru]=$this->createTeacher();
        [, $student]=$this->createStudent();
        $this->createAcademicYear();
        $old=$this->createAcademicYear(2025,2026,'GENAP',false);
        $schedule=$this->createSchedule($old,$this->createClass($old),$guru);
        $a=$this->createAssignment($schedule,$user);
        $submission=SubmissionTugas::create(['assignment_id'=>$a->id,'siswa_id'=>$student->id,'jawaban'=>'Test','submitted_at'=>now(),'status'=>'dikumpulkan']);
        $file=$submission->lampiran()->create(['file_path'=>'submissions/old.pdf','file_name'=>'old.pdf','file_mime_type'=>'application/pdf','file_size'=>1]);
        Sanctum::actingAs($user);
        $this->getJson('/api/assignments')->assertOk()->assertJsonCount(0,'data');
        $this->getJson("/api/assignments/{$a->id}")->assertForbidden();
        $this->getJson("/api/assignments/{$a->id}/submissions")->assertForbidden();
        $this->getJson("/api/submissions/{$submission->id}")->assertForbidden();
        $this->getJson("/api/submission-attachments/{$file->id}/view")->assertForbidden();
        $this->getJson("/api/submission-attachments/{$file->id}/download")->assertForbidden();
        $this->putJson("/api/assignments/{$a->id}",['judul'=>'No'])->assertForbidden();
    }

    public function test_partial_update_and_schedule_change_validate_effective_component(): void
    {
        [$user,$guru]=$this->createTeacher();
        $year=$this->createAcademicYear();
        $class=$this->createClass($year);
        $one=$this->createSchedule($year,$class,$guru);
        $two=$this->createSchedule($year,$class,$guru);
        $c1=$this->createComponent($one);
        $c2=$this->createComponent($two);
        $a=$this->createAssignment($one,$user,['komponen_nilai_id'=>$c1->id]);
        Sanctum::actingAs($user);
        $this->putJson("/api/assignments/{$a->id}",['komponen_nilai_id'=>$c2->id])->assertUnprocessable()->assertJsonValidationErrors('komponen_nilai_id');
        $this->putJson("/api/assignments/{$a->id}",['jadwal_pelajaran_id'=>$two->id])->assertUnprocessable()->assertJsonValidationErrors('komponen_nilai_id');
        $this->putJson("/api/assignments/{$a->id}",['jadwal_pelajaran_id'=>$two->id,'komponen_nilai_id'=>$c2->id])->assertOk();
        $this->assertDatabaseHas('assignments',['id'=>$a->id,'jadwal_pelajaran_id'=>$two->id,'komponen_nilai_id'=>$c2->id]);
    }

    public function test_schedule_cannot_move_after_student_submits(): void
    {
        [$user,$guru]=$this->createTeacher();
        [, $student]=$this->createStudent();
        $year=$this->createAcademicYear();
        $one=$this->createSchedule($year,$this->createClass($year),$guru);
        $two=$this->createSchedule($year,$this->createClass($year,'X IPA 2'),$guru);
        $a=$this->createAssignment($one,$user);
        SubmissionTugas::create(['assignment_id'=>$a->id,'siswa_id'=>$student->id,'jawaban'=>'Test','submitted_at'=>now(),'status'=>'dikumpulkan']);
        Sanctum::actingAs($user);
        $this->putJson("/api/assignments/{$a->id}",['jadwal_pelajaran_id'=>$two->id])->assertUnprocessable();
        $this->assertDatabaseHas('assignments',['id'=>$a->id,'jadwal_pelajaran_id'=>$one->id]);
    }

    public function test_student_future_membership_is_denied_then_late_submission_remains_allowed(): void
    {
        [$user,$guru]=$this->createTeacher();
        [$studentUser,$student]=$this->createStudent();
        $year=$this->createAcademicYear();
        $class=$this->createClass($year);
        $membership=$this->createMembership($student,$class);
        $membership->update(['tanggal_mulai'=>now('Asia/Jakarta')->addDay()->toDateString()]);
        $a=$this->createAssignment($this->createSchedule($year,$class,$guru),$user,['deadline'=>now()->subHour()]);
        Sanctum::actingAs($studentUser);
        $this->getJson('/api/assignments')->assertOk()->assertJsonCount(0,'data');
        $this->getJson("/api/assignments/{$a->id}")->assertForbidden();
        $this->postJson("/api/assignments/{$a->id}/submissions",['jawaban'=>'Test'])->assertForbidden();
        $membership->update(['tanggal_mulai'=>now('Asia/Jakarta')->subDay()->toDateString()]);
        $this->postJson("/api/assignments/{$a->id}/submissions",['jawaban'=>'Test'])->assertCreated()->assertJsonPath('data.status','terlambat');
    }

    public function test_upload_extension_and_foreign_attachment_id_are_rejected(): void
    {
        [$user,$guru]=$this->createTeacher();
        $year=$this->createAcademicYear();
        $schedule=$this->createSchedule($year,$this->createClass($year),$guru);
        $a=$this->createAssignment($schedule,$user);
        $other=$this->createAssignment($schedule,$user);
        $attachment=$other->lampiran()->create(['file_path'=>'assignments/other.pdf','file_name'=>'other.pdf','file_mime_type'=>'application/pdf','file_size'=>1]);
        Sanctum::actingAs($user);
        $this->putJson("/api/assignments/{$a->id}",['remove_attachment_ids'=>[$attachment->id]])->assertUnprocessable();
        $this->post('/api/assignments',['jadwal_pelajaran_id'=>$schedule->id,'judul'=>'Test','deadline'=>'2027-01-01 00:00:00','files'=>[UploadedFile::fake()->create('wrong.exe',1,'application/pdf')]],['Accept'=>'application/json'])->assertUnprocessable();
        $this->assertDatabaseHas('assignment_lampiran',['id'=>$attachment->id]);
    }
}
