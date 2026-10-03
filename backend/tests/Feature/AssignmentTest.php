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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssignmentTest extends TestCase
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

    public function test_guest_cannot_access_assignments(): void
    {
        $this->getJson('/api/assignments')
            ->assertUnauthorized();
    }

    public function test_authenticated_roles_can_view_assignment_list(): void
    {
        foreach (['TU', 'KEPALA_SEKOLAH', 'GURU', 'SISWA'] as $role) {
            $user = $this->createUser($role);

            Sanctum::actingAs($user);

            $this->getJson('/api/assignments')
                ->assertOk()
                ->assertJsonStructure([
                    'data',
                    'meta',
                ]);
        }
    }

    public function test_teacher_can_create_assignment_in_own_teaching_context(): void
    {
        [$user, $guru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $guru
        );

        $component = $this->createComponent($schedule);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/assignments', [
            'jadwal_pelajaran_id' => $schedule->id,
            'komponen_nilai_id' => $component->id,
            'judul' => 'Tugas Matematika',
            'deskripsi' => 'Kerjakan latihan halaman 10.',
            'deadline' => '2026-10-10 23:59:00',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.judul',
                'Tugas Matematika'
            )
            ->assertJsonPath(
                'data.jadwal_pelajaran_id',
                $schedule->id
            )
            ->assertJsonPath(
                'data.komponen_nilai_id',
                $component->id
            );

        $this->assertDatabaseHas('assignments', [
            'jadwal_pelajaran_id' => $schedule->id,
            'komponen_nilai_id' => $component->id,
            'judul' => 'Tugas Matematika',
            'created_by' => $user->id,
        ]);
    }

    public function test_teacher_cannot_create_assignment_for_another_teacher_schedule(): void
    {
        [$user] = $this->createTeacher();
        [, $otherGuru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $otherSchedule = $this->createSchedule(
            $academicYear,
            $class,
            $otherGuru
        );

        Sanctum::actingAs($user);

        $this->postJson('/api/assignments', [
            'jadwal_pelajaran_id' => $otherSchedule->id,
            'judul' => 'Tugas Tidak Sah',
            'deadline' => '2026-10-10 23:59:00',
        ])->assertForbidden();
    }

    public function test_non_teacher_cannot_create_assignment(): void
    {
        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        [, $guru] = $this->createTeacher();

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $guru
        );

        foreach (['TU', 'KEPALA_SEKOLAH', 'SISWA'] as $role) {
            $user = $this->createUser($role);

            Sanctum::actingAs($user);

            $this->postJson('/api/assignments', [
                'jadwal_pelajaran_id' => $schedule->id,
                'judul' => 'Tugas',
                'deadline' => '2026-10-10 23:59:00',
            ])->assertForbidden();
        }
    }

    public function test_teacher_can_view_own_assignment(): void
    {
        [$user, $guru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $guru
        );

        $assignment = $this->createAssignment(
            $schedule,
            $user
        );

        Sanctum::actingAs($user);

        $this->getJson(
            "/api/assignments/{$assignment->id}"
        )
            ->assertOk()
            ->assertJsonPath(
                'data.id',
                $assignment->id
            )
            ->assertJsonPath(
                'data.judul',
                'Tugas Test'
            );
    }

    public function test_teacher_cannot_view_another_teacher_assignment(): void
    {
        [$user] = $this->createTeacher();
        [, $otherGuru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $otherSchedule = $this->createSchedule(
            $academicYear,
            $class,
            $otherGuru
        );

        $assignment = $this->createAssignment(
            $otherSchedule,
            $otherGuru->user
        );

        Sanctum::actingAs($user);

        $this->getJson(
            "/api/assignments/{$assignment->id}"
        )->assertForbidden();
    }

    public function test_student_can_view_assignment_for_own_class(): void
    {
        [, $guru] = $this->createTeacher();
        [$studentUser, $student] = $this->createStudent();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $this->createMembership(
            $student,
            $class
        );

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $guru
        );

        $assignment = $this->createAssignment(
            $schedule,
            $guru->user
        );

        Sanctum::actingAs($studentUser);

        $this->getJson(
            "/api/assignments/{$assignment->id}"
        )->assertOk();
    }

    public function test_student_cannot_view_assignment_for_another_class(): void
    {
        [, $guru] = $this->createTeacher();
        [$studentUser, $student] = $this->createStudent();

        $academicYear = $this->createAcademicYear();

        $ownClass = $this->createClass(
            $academicYear,
            'X IPA 1'
        );

        $otherClass = $this->createClass(
            $academicYear,
            'X IPA 2'
        );

        $this->createMembership(
            $student,
            $ownClass
        );

        $otherSchedule = $this->createSchedule(
            $academicYear,
            $otherClass,
            $guru
        );

        $assignment = $this->createAssignment(
            $otherSchedule,
            $guru->user
        );

        Sanctum::actingAs($studentUser);

        $this->getJson(
            "/api/assignments/{$assignment->id}"
        )->assertForbidden();
    }

    public function test_teacher_can_update_own_assignment(): void
    {
        [$user, $guru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $guru
        );

        $assignment = $this->createAssignment(
            $schedule,
            $user
        );

        Sanctum::actingAs($user);

        $this->putJson(
            "/api/assignments/{$assignment->id}",
            [
                'judul' => 'Tugas Diperbarui',
                'deskripsi' => 'Deskripsi baru',
                'deadline' => '2026-10-15 23:59:00',
            ]
        )
            ->assertOk()
            ->assertJsonPath(
                'data.judul',
                'Tugas Diperbarui'
            );

        $this->assertDatabaseHas('assignments', [
            'id' => $assignment->id,
            'judul' => 'Tugas Diperbarui',
            'deskripsi' => 'Deskripsi baru',
        ]);
    }

    public function test_teacher_can_edit_assignment_deadline(): void
    {
        [$user, $guru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $guru
        );

        $assignment = $this->createAssignment(
            $schedule,
            $user,
            [
                'deadline' => '2026-10-10 23:59:00',
            ]
        );

        Sanctum::actingAs($user);

        $this->putJson(
            "/api/assignments/{$assignment->id}",
            [
                'deadline' => '2026-10-20 23:59:00',
            ]
        )->assertOk();

        $this->assertDatabaseHas('assignments', [
            'id' => $assignment->id,
            'deadline' => '2026-10-20 23:59:00',
        ]);
    }

    public function test_teacher_cannot_update_another_teacher_assignment(): void
    {
        [$user] = $this->createTeacher();
        [, $otherGuru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $otherGuru
        );

        $assignment = $this->createAssignment(
            $schedule,
            $otherGuru->user
        );

        Sanctum::actingAs($user);

        $this->putJson(
            "/api/assignments/{$assignment->id}",
            [
                'judul' => 'Tidak Boleh',
            ]
        )->assertForbidden();
    }

    public function test_assignment_requires_required_fields(): void
    {
        [$user] = $this->createTeacher();

        Sanctum::actingAs($user);

        $this->postJson('/api/assignments', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'jadwal_pelajaran_id',
                'judul',
                'deadline',
            ]);
    }

    public function test_assignment_rejects_unknown_schedule(): void
    {
        [$user] = $this->createTeacher();

        Sanctum::actingAs($user);

        $this->postJson('/api/assignments', [
            'jadwal_pelajaran_id' => 999999,
            'judul' => 'Tugas',
            'deadline' => '2026-10-10 23:59:00',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'jadwal_pelajaran_id',
            ]);
    }

    public function test_assignment_rejects_component_from_another_schedule(): void
    {
        [$user, $guru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();

        $classOne = $this->createClass(
            $academicYear,
            'X IPA 1'
        );

        $classTwo = $this->createClass(
            $academicYear,
            'X IPA 2'
        );

        $scheduleOne = $this->createSchedule(
            $academicYear,
            $classOne,
            $guru
        );

        $scheduleTwo = $this->createSchedule(
            $academicYear,
            $classTwo,
            $guru
        );

        $component = $this->createComponent(
            $scheduleTwo
        );

        Sanctum::actingAs($user);

        $this->postJson('/api/assignments', [
            'jadwal_pelajaran_id' => $scheduleOne->id,
            'komponen_nilai_id' => $component->id,
            'judul' => 'Tugas',
            'deadline' => '2026-10-10 23:59:00',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'komponen_nilai_id',
            ]);
    }

    public function test_assignment_without_attachment_is_valid(): void
    {
        [$user, $guru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $guru
        );

        Sanctum::actingAs($user);

        $this->postJson('/api/assignments', [
            'jadwal_pelajaran_id' => $schedule->id,
            'judul' => 'Tugas Tanpa Lampiran',
            'deskripsi' => 'Siswa mengerjakan di buku.',
            'deadline' => '2026-10-10 23:59:00',
        ])
            ->assertCreated()
            ->assertJsonPath(
                'data.judul',
                'Tugas Tanpa Lampiran'
            );

        $this->assertDatabaseHas('assignments', [
            'jadwal_pelajaran_id' => $schedule->id,
            'judul' => 'Tugas Tanpa Lampiran',
            'file_path' => null,
        ]);
    }

    public function test_teacher_can_upload_assignment_attachment(): void
    {
        [$user, $guru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $guru
        );

        Storage::fake('local');

        Sanctum::actingAs($user);

        $file = UploadedFile::fake()->create(
            'tugas.pdf',
            100,
            'application/pdf'
        );

        $response = $this->post(
            '/api/assignments',
            [
                'jadwal_pelajaran_id' => $schedule->id,
                'judul' => 'Tugas Dengan Lampiran',
                'deadline' => '2026-10-10 23:59:00',
                'file' => $file,
            ],
            [
                'Accept' => 'application/json',
            ]
        );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.judul',
                'Tugas Dengan Lampiran'
            );

        $assignment = Assignment::query()
            ->latest('id')
            ->first();

        $this->assertNotNull($assignment);
        $this->assertNotNull($assignment->file_path);
        $this->assertSame(
            'tugas.pdf',
            $assignment->file_name
        );

        $this->assertTrue(
            Storage::disk('local')->exists(
                $assignment->file_path
            )
        );
    }

    public function test_assignment_rejects_unsupported_attachment_type(): void
    {
        [$user, $guru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $guru
        );

        Sanctum::actingAs($user);

        $file = UploadedFile::fake()->create(
            'malware.exe',
            100,
            'application/octet-stream'
        );

        $this->post(
            '/api/assignments',
            [
                'jadwal_pelajaran_id' => $schedule->id,
                'judul' => 'Tugas',
                'deadline' => '2026-10-10 23:59:00',
                'file' => $file,
            ],
            [
                'Accept' => 'application/json',
            ]
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'file',
            ]);
    }

    public function test_teacher_can_download_own_assignment_attachment(): void
    {
        [$user, $guru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $guru
        );

        Storage::fake('local');

        Sanctum::actingAs($user);

        $file = UploadedFile::fake()->create(
            'tugas.pdf',
            100,
            'application/pdf'
        );

        $this->post(
            '/api/assignments',
            [
                'jadwal_pelajaran_id' => $schedule->id,
                'judul' => 'Tugas Download',
                'deadline' => '2026-10-10 23:59:00',
                'file' => $file,
            ],
            [
                'Accept' => 'application/json',
            ]
        )->assertCreated();

        $assignment = Assignment::query()
            ->latest('id')
            ->first();

        $this->get(
            "/api/assignments/{$assignment->id}/download"
        )
            ->assertOk()
            ->assertDownload('tugas.pdf');
    }

    public function test_assignment_download_returns_not_found_when_file_is_missing(): void
    {
        [$user, $guru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $guru
        );

        $assignment = $this->createAssignment(
            $schedule,
            $user,
            [
                'file_path' => 'assignments/non-existent-file',
                'file_name' => 'tugas.pdf',
                'file_mime_type' => 'application/pdf',
                'file_size' => 100,
            ]
        );

        Storage::fake('local');

        Sanctum::actingAs($user);

        $this->get(
            "/api/assignments/{$assignment->id}/download"
        )->assertNotFound();
    }

    public function test_teacher_can_delete_assignment_without_submissions(): void
    {
        [$user, $guru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $guru
        );

        $assignment = $this->createAssignment(
            $schedule,
            $user
        );

        Sanctum::actingAs($user);

        $this->deleteJson(
            "/api/assignments/{$assignment->id}"
        )
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Tugas berhasil dihapus.'
            );

        $this->assertDatabaseMissing('assignments', [
            'id' => $assignment->id,
        ]);
    }

    public function test_assignment_show_returns_404_when_not_found(): void
    {
        $user = $this->createUser('TU');

        Sanctum::actingAs($user);

        $this->getJson(
            '/api/assignments/999999'
        )->assertNotFound();
    }
}