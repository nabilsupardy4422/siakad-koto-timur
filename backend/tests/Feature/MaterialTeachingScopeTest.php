<?php

namespace Tests\Feature;

use App\Models\AnggotaKelas;
use App\Models\BahanAjar;
use App\Models\BahanAjarLampiran;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MaterialTeachingScopeTest extends TestCase
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

    private function createAssignment(
        Guru $guru,
        Kelas $class,
        TahunAkademik $academicYear
    ): WaliKelas {
        return WaliKelas::create([
            'guru_id' => $guru->id,
            'kelas_id' => $class->id,
            'tahun_akademik_id' => $academicYear->id,
            'tanggal_mulai' => '2026-07-01',
            'tanggal_selesai' => null,
        ]);
    }

    private function createMaterial(
        JadwalPelajaran $schedule,
        User $creator,
        array $attributes = []
    ): BahanAjar {
        return BahanAjar::create(array_merge([
            'jadwal_pelajaran_id' => $schedule->id,
            'judul' => 'Materi Test',
            'deskripsi' => 'Deskripsi materi test',
            'created_by' => $creator->id,
        ], $attributes));
    }

    public function test_teaching_scope_isolates_same_subject_and_class_in_list_detail_and_downloads(): void
    {
        [$user, $guru] = $this->createTeacher();
        [$peerUser, $peer] = $this->createTeacher();
        $year = $this->createAcademicYear();
        $class = $this->createClass($year);
        $this->createAssignment($guru, $class, $year);
        $mine = $this->createSchedule($year, $class, $guru);
        $other = $this->createSchedule($year, $class, $peer);
        $other->update(['mapel_id' => $mine->mapel_id, 'hari' => 'SELASA']);
        $myMaterial = $this->createMaterial($mine, $user);
        $peerMaterial = $this->createMaterial($other, $peerUser, ['file_path' => 'materials/peer.pdf', 'file_name' => 'peer.pdf']);
        Storage::fake('local');
        Storage::disk('local')->put('materials/peer.pdf', 'sample');
        $attachment = $peerMaterial->lampiran()->create(['file_path'=>'materials/peer.pdf','file_name'=>'peer.pdf','file_mime_type'=>'application/pdf','file_size'=>6,'uploaded_by'=>$peerUser->id]);
        Sanctum::actingAs($user);
        $this->getJson('/api/materials?scope=teaching&per_page=1')->assertOk()->assertJsonPath('meta.total',1)->assertJsonPath('data.0.id',$myMaterial->id);
        $this->getJson('/api/materials?scope=teaching&schedule_id='.$other->id)->assertOk()->assertJsonPath('meta.total',0);
        $this->getJson("/api/materials/{$peerMaterial->id}?scope=teaching")->assertForbidden();
        $this->getJson("/api/materials/{$peerMaterial->id}/download?scope=teaching")->assertForbidden();
        $this->getJson("/api/materials/{$peerMaterial->id}/attachments/{$attachment->id}/download?scope=teaching")->assertForbidden();
        $this->getJson('/api/materials?scope=homeroom')->assertOk()->assertJsonPath('meta.total',2);
        $this->getJson("/api/materials/{$peerMaterial->id}?scope=homeroom")->assertOk();
        $this->putJson("/api/materials/{$peerMaterial->id}",['judul'=>'Unauthorized'])->assertForbidden();
        $this->deleteJson("/api/materials/{$peerMaterial->id}/attachments/{$attachment->id}")->assertForbidden();
        $this->deleteJson("/api/materials/{$peerMaterial->id}")->assertForbidden();
        $this->putJson("/api/materials/{$myMaterial->id}",['jadwal_pelajaran_id'=>$other->id])->assertForbidden();
        $this->assertDatabaseHas('bahan_ajar',['id'=>$peerMaterial->id]);
        Storage::disk('local')->assertExists('materials/peer.pdf');
    }

    public function test_ended_homeroom_assignment_no_longer_grants_read_or_download(): void
    {
        [$user, $guru] = $this->createTeacher();
        [$peerUser, $peer] = $this->createTeacher();
        $year = $this->createAcademicYear();
        $class = $this->createClass($year);
        $assignment = $this->createAssignment($guru, $class, $year);
        $schedule = $this->createSchedule($year, $class, $peer);
        $material = $this->createMaterial($schedule, $peerUser);
        $assignment->update(['tanggal_selesai'=>'2026-07-31']);
        Sanctum::actingAs($user);
        $this->getJson('/api/materials?scope=homeroom')->assertOk()->assertJsonPath('meta.total',0);
        $this->getJson('/api/materials')->assertOk()->assertJsonPath('meta.total',0);
        $this->getJson("/api/materials/{$material->id}")->assertForbidden();
        $this->getJson("/api/materials/{$material->id}/download")->assertForbidden();
    }

    public function test_history_opt_in_matches_only_own_schedules_in_selected_period(): void
    {
        [$user,$guru] = $this->createTeacher();
        [$peerUser,$peer] = $this->createTeacher();
        $active = $this->createAcademicYear();
        $old = $this->createAcademicYear(2025,2026,'GENAP',false);
        $activeSchedule = $this->createSchedule($active,$this->createClass($active),$guru);
        $class = $this->createClass($old);
        $mine = $this->createSchedule($old,$class,$guru);
        $other = $this->createSchedule($old,$class,$peer);
        $this->createAssignment($guru,$class,$old);
        $myMaterial = $this->createMaterial($mine,$user);
        $this->createMaterial($other,$peerUser);
        Sanctum::actingAs($user);
        $this->getJson('/api/schedules?scope=teaching&academic_year_id='.$old->id)->assertOk()->assertJsonPath('data.0.id',$activeSchedule->id);
        $this->getJson('/api/schedules?scope=teaching&history=1&academic_year_id='.$old->id)->assertOk()->assertJsonPath('meta.total',1)->assertJsonPath('data.0.id',$mine->id);
        $this->getJson('/api/schedules?scope=teaching&history=1&academic_year_id='.$old->id.'&teacher_id='.$peer->id)->assertOk()->assertJsonPath('meta.total',0);
        $this->getJson('/api/schedules?scope=homeroom&history=1&academic_year_id='.$old->id)->assertForbidden();
        $this->getJson('/api/schedules?scope=teaching&history=1')->assertUnprocessable();
        $this->getJson('/api/materials?scope=teaching&academic_year_id='.$old->id)->assertOk()->assertJsonPath('meta.total',1)->assertJsonPath('data.0.id',$myMaterial->id);
        $this->getJson('/api/materials?scope=homeroom&academic_year_id='.$old->id)->assertOk()->assertJsonPath('meta.total',0);
        $this->getJson("/api/materials/{$myMaterial->id}?scope=teaching")->assertOk();
    }

    public function test_new_uploads_validate_mime_extension_and_size(): void
    {
        [$user,$guru] = $this->createTeacher();
        $year = $this->createAcademicYear();
        $schedule = $this->createSchedule($year,$this->createClass($year),$guru);
        Sanctum::actingAs($user);
        Storage::fake('local');
        $payload = ['jadwal_pelajaran_id'=>$schedule->id,'judul'=>'Material test'];
        $this->post('/api/materials', $payload+['file'=>UploadedFile::fake()->create('script.php',1,'text/x-php')], ['Accept'=>'application/json'])->assertUnprocessable();
        $this->post('/api/materials', $payload+['file'=>UploadedFile::fake()->create('largeb.pdf',10241,'application/pdf')], ['Accept'=>'application/json'])->assertUnprocessable();
        $this->post('/api/materials', $payload+['file'=>UploadedFile::fake()->create('wrong.pdf',1,'text/html')], ['Accept'=>'application/json'])->assertUnprocessable();
        $this->post('/api/materials', $payload+['files'=>[UploadedFile::fake()->create('document.exe',1,'application/pdf')]], ['Accept'=>'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('bahan_ajar',0);
        $this->post('/api/materials', $payload+['file'=>UploadedFile::fake()->create('document.pdf',1,'application/pdf')], ['Accept'=>'application/json'])->assertCreated();
    }

    public function test_explicit_teacher_context_cannot_be_requested_by_students(): void
    {
        [$studentUser] = $this->createStudent();
        Sanctum::actingAs($studentUser);
        $this->getJson('/api/materials?scope=teaching')->assertForbidden();
        $this->getJson('/api/schedules?scope=teaching&history=1')->assertForbidden();
    }
}
