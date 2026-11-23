<?php

namespace Tests\Feature;

use App\Models\AnggotaKelas;
use App\Models\BahanAjar;
use App\Models\Guru;
use App\Models\JadwalPelajaran;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\Role;
use App\Models\Siswa;
use App\Models\TahunAkademik;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class StudentMaterialWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-22 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role): User
    {
        $r = Role::firstOrCreate(['code' => $role], ['name' => $role]);
        return User::create([
            'role_id' => $r->id,
            'username' => $role.'-'.uniqid(),
            'name' => $role,
            'email' => uniqid().'@example.test',
            'password' => Hash::make('secret'),
            'account_status' => 'active',
        ]);
    }

    private function fixture(): array
    {
        $studentUser = $this->user('SISWA');
        $student = Siswa::create([
            'user_id' => $studentUser->id,
            'nisn' => 'NISN-'.uniqid(),
            'nis' => 'NIS-'.uniqid(),
            'nama_lengkap' => 'Siswa Uji',
        ]);
        $teacherUser = $this->user('GURU');
        $teacher = Guru::create([
            'user_id' => $teacherUser->id,
            'nip' => 'NIP-'.uniqid(),
            'nama_lengkap' => 'Guru Bahasa',
        ]);
        $year = TahunAkademik::create([
            'tahun_mulai' => 2026, 'tahun_selesai' => 2027,
            'semester' => 'GANJIL', 'is_active' => true,
        ]);
        $class = Kelas::create([
            'tahun_akademik_id' => $year->id, 'nama' => 'IPA 1', 'tingkat' => 10,
        ]);
        $subject = Mapel::create(['kode' => 'M-'.uniqid(), 'nama' => 'Bahasa Indonesia']);
        $schedule = JadwalPelajaran::create([
            'tahun_akademik_id' => $year->id,
            'kelas_id' => $class->id, 'mapel_id' => $subject->id,
            'guru_id' => $teacher->id, 'hari' => 'SENIN',
            'jam_mulai' => '07:30', 'jam_selesai' => '10:00',
        ]);
        $member = AnggotaKelas::create([
            'kelas_id' => $class->id, 'siswa_id' => $student->id,
            'tanggal_mulai' => '2026-07-01', 'tanggal_selesai' => null,
        ]);
        return compact('studentUser', 'student', 'teacherUser', 'year', 'class', 'subject', 'schedule', 'member');
    }

    private function material(array $fixture, string $name): BahanAjar
    {
        return BahanAjar::create([
            'jadwal_pelajaran_id' => $fixture['schedule']->id,
            'judul' => $name,
            'deskripsi' => 'Materi kelas',
            'created_by' => $fixture['teacherUser']->id,
        ]);
    }

    public function test_guest_and_other_roles_cannot_open_student_workspace(): void
    {
        $this->getJson('/api/materials/student-workspace')->assertUnauthorized();
        Sanctum::actingAs($this->user('GURU'));
        $this->getJson('/api/materials/student-workspace')->assertForbidden();
    }

    public function test_student_sees_own_subject_and_server_paginated_materials(): void
    {
        $f = $this->fixture();
        $this->material($f, 'Materi A');
        $this->material($f, 'Materi B');
        Sanctum::actingAs($f['studentUser']);
        $this->getJson('/api/materials/student-workspace')
            ->assertOk()->assertJsonPath('data.subjects.0.material_count', 2)
            ->assertJsonPath('data.subjects.0.name', 'Bahasa Indonesia');
        $this->getJson('/api/materials/student-workspace?subject_id='.$f['subject']->id.'&per_page=1&page=2')
            ->assertOk()->assertJsonPath('data.materials.total', 2)
            ->assertJsonPath('data.materials.page', 2)
            ->assertJsonCount(1, 'data.materials.data');
        $this->getJson('/api/materials/student-workspace?subject_id=999999')
            ->assertUnprocessable();
    }

    public function test_future_membership_denies_workspace_list_show_and_download(): void
    {
        $f = $this->fixture();
        $f['member']->update(['tanggal_mulai' => '2027-01-01']);
        $material = $this->material($f, 'No future access');
        Sanctum::actingAs($f['studentUser']);
        $this->getJson('/api/materials/student-workspace')->assertOk()->assertJsonCount(0, 'data.subjects');
        $this->getJson('/api/materials')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/materials/'.$material->id)->assertForbidden();
        $this->getJson('/api/materials/'.$material->id.'/download')->assertForbidden();
    }

    public function test_old_membership_only_allows_documents_created_while_membership_effective(): void
    {
        $f = $this->fixture();
        $f['member']->update(['tanggal_selesai' => '2026-10-31']);
        $old = $this->material($f, 'Historical file');
        $old->created_at = '2026-09-02 12:00:00';
        $old->save();
        $new = $this->material($f, 'After exit');
        Sanctum::actingAs($f['studentUser']);
        $this->getJson('/api/materials')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/materials/'.$old->id)->assertOk();
        $this->getJson('/api/materials/'.$new->id)->assertForbidden();
        $this->getJson('/api/materials/'.$new->id.'/download')->assertForbidden();
    }

    public function test_foreign_class_material_and_attachment_cannot_be_downloaded(): void
    {
        $f = $this->fixture();
        $secondUser = $this->user('SISWA');
        $second = Siswa::create([
            'user_id' => $secondUser->id,
            'nisn' => 'NISN-'.uniqid(), 'nis' => 'NIS-'.uniqid(), 'nama_lengkap' => 'Siswa lain',
        ]);
        $material = $this->material($f, 'Private');
        Storage::fake('local');
        Storage::disk('local')->put('materials/private.pdf', 'test');
        $attachment = $material->lampiran()->create([
            'file_path' => 'materials/private.pdf',
            'file_name' => 'private.pdf',
            'file_mime_type' => 'application/pdf',
            'file_size' => 4,
            'uploaded_by' => $f['teacherUser']->id,
        ]);
        Sanctum::actingAs($secondUser);
        $this->getJson('/api/materials/student-workspace')->assertOk()->assertJsonCount(0, 'data.subjects');
        $this->getJson('/api/materials/'.$material->id)->assertForbidden();
        $this->getJson('/api/materials/'.$material->id.'/attachments/'.$attachment->id.'/download')->assertForbidden();
    }
}
