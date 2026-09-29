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
use App\Models\WaliKelas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MaterialTest extends TestCase
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

    public function test_guest_cannot_access_materials(): void
    {
        $this->getJson('/api/materials')
            ->assertUnauthorized();
    }

    public function test_authenticated_roles_can_view_material_list(): void
    {
        foreach (['TU', 'KEPALA_SEKOLAH', 'GURU', 'SISWA'] as $role) {
            $user = $this->createUser($role);

            Sanctum::actingAs($user);

            $this->getJson('/api/materials')
                ->assertOk()
                ->assertJsonStructure([
                    'data',
                    'meta',
                ]);
        }
    }

    public function test_teacher_can_create_material_in_own_teaching_context(): void
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

        Storage::fake('local');

        $file = UploadedFile::fake()->create(
            'materi.pdf',
            100,
            'application/pdf'
        );

        $response = $this->post(
            '/api/materials',
            [
                'jadwal_pelajaran_id' => $schedule->id,
                'judul' => 'Materi Matematika',
                'deskripsi' => 'Materi pertemuan pertama',
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
                'Materi Matematika'
            )
            ->assertJsonPath(
                'data.file_name',
                'materi.pdf'
            )
            ->assertJsonPath(
                'data.has_file',
                true
            );

        $this->assertDatabaseHas('bahan_ajar', [
            'jadwal_pelajaran_id' => $schedule->id,
            'judul' => 'Materi Matematika',
            'created_by' => $user->id,
            'file_name' => 'materi.pdf',
        ]);

        $material = BahanAjar::query()->latest('id')->first();

        $this->assertNotNull($material);
        $this->assertNotNull($material->file_path);

        $this->assertTrue(
            Storage::disk('local')->exists($material->file_path)
        );
    }

    public function test_teacher_cannot_create_material_for_another_teacher_schedule(): void
    {
        [$user, $guru] = $this->createTeacher();
        [, $otherGuru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $otherSchedule = $this->createSchedule(
            $academicYear,
            $class,
            $otherGuru
        );

        Sanctum::actingAs($user);

        $this->postJson('/api/materials', [
            'jadwal_pelajaran_id' => $otherSchedule->id,
            'judul' => 'Materi Tidak Sah',
            'deskripsi' => 'Tidak boleh dibuat',
        ])->assertForbidden();
    }

    public function test_non_teacher_cannot_create_material(): void
    {
        $academicYear = $this->createAcademicYear();

        [, $guru] = $this->createTeacher();

        $class = $this->createClass($academicYear);

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $guru
        );

        foreach (['TU', 'KEPALA_SEKOLAH', 'SISWA'] as $role) {
            $user = $this->createUser($role);

            Sanctum::actingAs($user);

            $this->postJson('/api/materials', [
                'jadwal_pelajaran_id' => $schedule->id,
                'judul' => 'Material',
            ])->assertForbidden();
        }
    }

    public function test_teacher_can_view_own_material(): void
    {
        [$user, $guru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);
        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $guru
        );

        $material = $this->createMaterial(
            $schedule,
            $user
        );

        Sanctum::actingAs($user);

        $this->getJson(
            "/api/materials/{$material->id}"
        )
            ->assertOk()
            ->assertJsonPath(
                'data.id',
                $material->id
            )
            ->assertJsonPath(
                'data.judul',
                'Materi Test'
            );
    }

    public function test_teacher_cannot_view_another_teacher_material(): void
    {
        [$user, $guru] = $this->createTeacher();
        [, $otherGuru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $otherSchedule = $this->createSchedule(
            $academicYear,
            $class,
            $otherGuru
        );

        $material = $this->createMaterial(
            $otherSchedule,
            $otherGuru->user
        );

        Sanctum::actingAs($user);

        $this->getJson(
            "/api/materials/{$material->id}"
        )->assertForbidden();
    }

    public function test_wali_kelas_can_view_material_from_assigned_class(): void
    {
        [$user, $guru] = $this->createTeacher();
        [, $otherGuru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $this->createAssignment(
            $guru,
            $class,
            $academicYear
        );

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $otherGuru
        );

        $material = $this->createMaterial(
            $schedule,
            $otherGuru->user
        );

        Sanctum::actingAs($user);

        $this->getJson(
            "/api/materials/{$material->id}"
        )->assertOk();
    }

    public function test_wali_kelas_cannot_view_material_from_another_class(): void
    {
        [$user, $guru] = $this->createTeacher();
        [, $otherGuru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();

        $assignedClass = $this->createClass(
            $academicYear,
            'X IPA 1'
        );

        $otherClass = $this->createClass(
            $academicYear,
            'X IPA 2'
        );

        $this->createAssignment(
            $guru,
            $assignedClass,
            $academicYear
        );

        $otherSchedule = $this->createSchedule(
            $academicYear,
            $otherClass,
            $otherGuru
        );

        $material = $this->createMaterial(
            $otherSchedule,
            $otherGuru->user
        );

        Sanctum::actingAs($user);

        $this->getJson(
            "/api/materials/{$material->id}"
        )->assertForbidden();
    }

    public function test_siswa_can_view_material_for_own_class(): void
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

        $material = $this->createMaterial(
            $schedule,
            $guru->user
        );

        Sanctum::actingAs($studentUser);

        $this->getJson(
            "/api/materials/{$material->id}"
        )->assertOk();
    }

    public function test_siswa_cannot_view_material_for_another_class(): void
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

        $material = $this->createMaterial(
            $otherSchedule,
            $guru->user
        );

        Sanctum::actingAs($studentUser);

        $this->getJson(
            "/api/materials/{$material->id}"
        )->assertForbidden();
    }

    public function test_teacher_can_update_own_material(): void
    {
        [$user, $guru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);
        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $guru
        );

        $material = $this->createMaterial(
            $schedule,
            $user
        );

        Sanctum::actingAs($user);

        $this->putJson(
            "/api/materials/{$material->id}",
            [
                'judul' => 'Materi Diperbarui',
                'deskripsi' => 'Deskripsi baru',
            ]
        )
            ->assertOk()
            ->assertJsonPath(
                'data.judul',
                'Materi Diperbarui'
            );

        $this->assertDatabaseHas('bahan_ajar', [
            'id' => $material->id,
            'judul' => 'Materi Diperbarui',
            'deskripsi' => 'Deskripsi baru',
        ]);
    }

    public function test_teacher_cannot_update_another_teacher_material(): void
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

        $material = $this->createMaterial(
            $schedule,
            $otherGuru->user
        );

        Sanctum::actingAs($user);

        $this->putJson(
            "/api/materials/{$material->id}",
            [
                'judul' => 'Tidak Boleh',
            ]
        )->assertForbidden();
    }

    public function test_teacher_can_replace_material_file(): void
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

        $firstFile = UploadedFile::fake()->create(
            'materi-lama.pdf',
            100,
            'application/pdf'
        );

        $createResponse = $this->post(
            '/api/materials',
            [
                'jadwal_pelajaran_id' => $schedule->id,
                'judul' => 'Materi File',
                'file' => $firstFile,
            ],
            [
                'Accept' => 'application/json',
            ]
        )->assertCreated();

        $material = BahanAjar::query()->latest('id')->first();

        $oldPath = $material->file_path;

        $this->assertTrue(
            Storage::disk('local')->exists($material->file_path)
        );

        $secondFile = UploadedFile::fake()->create(
            'materi-baru.pdf',
            100,
            'application/pdf'
        );

        $this->put(
            "/api/materials/{$material->id}",
            [
                'judul' => 'Materi File',
                'file' => $secondFile,
            ],
            [
                'Accept' => 'application/json',
            ]
        )->assertOk();

        $material->refresh();

        $this->assertNotSame(
            $oldPath,
            $material->file_path
        );

        $this->assertSame(
            'materi-baru.pdf',
            $material->file_name
        );

        $this->assertFalse(
            Storage::disk('local')->exists($oldPath)
        );

        $this->assertTrue(
            Storage::disk('local')->exists($material->file_path)
        );
    }

    public function test_teacher_can_remove_material_file(): void
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
            'materi.pdf',
            100,
            'application/pdf'
        );

        $this->post(
            '/api/materials',
            [
                'jadwal_pelajaran_id' => $schedule->id,
                'judul' => 'Materi',
                'file' => $file,
            ],
            [
                'Accept' => 'application/json',
            ]
        )->assertCreated();

        $material = BahanAjar::query()->latest('id')->first();

        $oldPath = $material->file_path;

        $this->putJson(
            "/api/materials/{$material->id}",
            [
                'remove_file' => true,
            ]
        )
            ->assertOk()
            ->assertJsonPath(
                'data.has_file',
                false
            );

        $material->refresh();

        $this->assertNull($material->file_path);
        $this->assertNull($material->file_name);
        $this->assertNull($material->file_mime_type);
        $this->assertNull($material->file_size);

        $this->assertFalse(
            Storage::disk('local')->exists($oldPath)
        );
    }

    public function test_teacher_can_delete_own_material(): void
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
            'materi.pdf',
            100,
            'application/pdf'
        );

        $this->post(
            '/api/materials',
            [
                'jadwal_pelajaran_id' => $schedule->id,
                'judul' => 'Materi',
                'file' => $file,
            ],
            [
                'Accept' => 'application/json',
            ]
        )->assertCreated();

        $material = BahanAjar::query()->latest('id')->first();

        $path = $material->file_path;

        $this->deleteJson(
            "/api/materials/{$material->id}"
        )
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Bahan ajar berhasil dihapus.'
            );

        $this->assertDatabaseMissing('bahan_ajar', [
            'id' => $material->id,
        ]);

        $this->assertFalse(
            Storage::disk('local')->exists($path)
        );
    }

    public function test_student_can_download_authorized_material(): void
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

        Storage::fake('local');

        Sanctum::actingAs($guru->user);

        $file = UploadedFile::fake()->create(
            'materi.pdf',
            100,
            'application/pdf'
        );

        $this->post(
            '/api/materials',
            [
                'jadwal_pelajaran_id' => $schedule->id,
                'judul' => 'Materi Download',
                'file' => $file,
            ],
            [
                'Accept' => 'application/json',
            ]
        )->assertCreated();

        $material = BahanAjar::query()->latest('id')->first();

        Sanctum::actingAs($studentUser);

        $this->get(
            "/api/materials/{$material->id}/download"
        )
            ->assertOk()
            ->assertDownload('materi.pdf');
    }

    public function test_student_cannot_download_material_from_another_class(): void
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

        $schedule = $this->createSchedule(
            $academicYear,
            $otherClass,
            $guru
        );

        Storage::fake('local');

        Sanctum::actingAs($guru->user);

        $file = UploadedFile::fake()->create(
            'materi.pdf',
            100,
            'application/pdf'
        );

        $this->post(
            '/api/materials',
            [
                'jadwal_pelajaran_id' => $schedule->id,
                'judul' => 'Materi Privat',
                'file' => $file,
            ],
            [
                'Accept' => 'application/json',
            ]
        )->assertCreated();

        $material = BahanAjar::query()->latest('id')->first();

        Sanctum::actingAs($studentUser);

        $this->get(
            "/api/materials/{$material->id}/download"
        )->assertForbidden();
    }

    public function test_material_download_returns_not_found_when_file_is_missing(): void
    {
        [$user, $guru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);
        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $guru
        );

        $material = $this->createMaterial(
            $schedule,
            $user,
            [
                'file_path' => 'materials/non-existent-file',
                'file_name' => 'materi.pdf',
                'file_mime_type' => 'application/pdf',
                'file_size' => 100,
            ]
        );

        Storage::fake('local');

        Sanctum::actingAs($user);

        $this->get(
            "/api/materials/{$material->id}/download"
        )->assertNotFound();
    }

    public function test_material_requires_valid_fields(): void
    {
        [$user] = $this->createTeacher();

        Sanctum::actingAs($user);

        $this->postJson('/api/materials', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'jadwal_pelajaran_id',
                'judul',
            ]);
    }

    public function test_material_rejects_unknown_schedule(): void
    {
        [$user] = $this->createTeacher();

        Sanctum::actingAs($user);

        $this->postJson('/api/materials', [
            'jadwal_pelajaran_id' => 999999,
            'judul' => 'Materi',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'jadwal_pelajaran_id',
            ]);
    }

    public function test_material_list_supports_filters_and_pagination(): void
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

        $materialOne = $this->createMaterial(
            $scheduleOne,
            $user,
            [
                'judul' => 'Materi IPA Satu',
            ]
        );

        $materialTwo = $this->createMaterial(
            $scheduleTwo,
            $user,
            [
                'judul' => 'Materi IPA Dua',
            ]
        );

        Sanctum::actingAs($user);

        $response = $this->getJson(
            '/api/materials'
            . '?academic_year_id='
            . $academicYear->id
            . '&class_id='
            . $classOne->id
            . '&per_page=1'
        );

        $response
            ->assertOk()
            ->assertJsonPath(
                'meta.per_page',
                1
            )
            ->assertJsonPath(
                'meta.total',
                1
            )
            ->assertJsonFragment([
                'id' => $materialOne->id,
            ])
            ->assertJsonMissing([
                'id' => $materialTwo->id,
            ]);
    }

    public function test_material_show_returns_404_when_not_found(): void
    {
        $user = $this->createUser('TU');

        Sanctum::actingAs($user);

        $this->getJson(
            '/api/materials/999999'
        )->assertNotFound();
    }
}