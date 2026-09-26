<?php

namespace Tests\Feature;

use App\Models\AnggotaKelas;
use App\Models\Guru;
use App\Models\JadwalPelajaran;
use App\Models\Kelas;
use App\Models\Role;
use App\Models\Siswa;
use App\Models\User;
use App\Models\WaliKelas;
use App\Models\TahunAkademik;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentTest extends TestCase
{
    use RefreshDatabase;

    private function createRole(string $code, string $name): Role
    {
        return Role::create([
            'code' => $code,
            'name' => $name,
        ]);
    }

    private function createUser(string $roleCode): User
    {
        $role = Role::where('code', $roleCode)->first();

        if ($role === null) {
            $role = $this->createRole(
                $roleCode,
                match ($roleCode) {
                    'TU' => 'Tata Usaha',
                    'KEPALA_SEKOLAH' => 'Kepala Sekolah',
                    'GURU' => 'Guru',
                    'SISWA' => 'Siswa',
                    default => $roleCode,
                }
            );
        }

        return User::create([
            'role_id' => $role->id,
            'username' => strtolower($roleCode) . '_' . uniqid(),
            'name' => 'User ' . $roleCode,
            'email' => strtolower($roleCode) . '_' . uniqid() . '@example.test',
            'password' => Hash::make('password'),
            'account_status' => 'active',
        ]);
    }

    private function createAcademicYear(array $overrides = []): TahunAkademik
    {
        return TahunAkademik::create(array_merge([
            'tahun_mulai' => 2026,
            'tahun_selesai' => 2027,
            'semester' => 'GANJIL',
            'is_active' => true,
        ], $overrides));
    }

    private function createClass(
        TahunAkademik $academicYear,
        array $overrides = []
    ): Kelas {
        return Kelas::create(array_merge([
            'tahun_akademik_id' => $academicYear->id,
            'nama' => 'X IPA 1 ' . uniqid(),
            'tingkat' => 10,
        ], $overrides));
    }

    private function createStudent(
        array $overrides = [],
        ?User $user = null
    ): Siswa {
        $user ??= $this->createUser('SISWA');

        return Siswa::create(array_merge([
            'user_id' => $user->id,
            'nisn' => 'NISN-' . uniqid(),
            'nis' => 'NIS-' . uniqid(),
            'nama_lengkap' => 'Siswa Test',
            'jenis_kelamin' => 'L',
            'tanggal_lahir' => '2010-01-01',
            'tempat_lahir' => 'Padang',
            'no_telepon' => '08123456789',
            'alamat' => 'Alamat Siswa',
        ], $overrides));
    }

    private function createTeacher(
        array $overrides = [],
        ?User $user = null
    ): Guru {
        $user ??= $this->createUser('GURU');

        return Guru::create(array_merge([
            'user_id' => $user->id,
            'nip' => 'NIP-' . uniqid(),
            'nama_lengkap' => 'Guru Test',
            'jenis_kelamin' => 'L',
            'no_telepon' => '08123456789',
            'alamat' => 'Alamat Guru',
        ], $overrides));
    }

    private function addStudentToClass(
        Siswa $student,
        Kelas $class
    ): AnggotaKelas {
        return AnggotaKelas::create([
            'siswa_id' => $student->id,
            'kelas_id' => $class->id,
            'tanggal_mulai' => '2026-07-01',
            'tanggal_selesai' => null,
        ]);
    }

    private function createTeachingSchedule(
        Guru $teacher,
        Kelas $class,
        TahunAkademik $academicYear
    ): JadwalPelajaran {
        return JadwalPelajaran::create([
            'tahun_akademik_id' => $academicYear->id,
            'kelas_id' => $class->id,
            'mapel_id' => $this->createMapelId(),
            'guru_id' => $teacher->id,
            'hari' => 'Senin',
            'jam_mulai' => '07:00',
            'jam_selesai' => '08:30',
        ]);
    }

    private function createMapelId(): int
    {
        $mapel = \App\Models\Mapel::create([
            'nama' => 'Mapel Test ' . uniqid(),
            'kode' => 'MAPEL-' . uniqid(),
        ]);

        return $mapel->id;
    }

    private function assignWaliKelas(
        Guru $teacher,
        Kelas $class,
        TahunAkademik $academicYear
    ): WaliKelas {
        return WaliKelas::create([
            'guru_id' => $teacher->id,
            'kelas_id' => $class->id,
            'tahun_akademik_id' => $academicYear->id,
            'tanggal_mulai' => '2026-07-01',
            'tanggal_selesai' => null,
        ]);
    }

    public function test_guest_cannot_access_student_list(): void
    {
        $response = $this->getJson('/api/students');

        $response
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_all_authenticated_roles_can_view_student_list(): void
    {
        foreach (['TU', 'KEPALA_SEKOLAH', 'GURU', 'SISWA'] as $role) {
            $user = $this->createUser($role);

            Sanctum::actingAs($user);

            $this->getJson('/api/students')
                ->assertOk()
                ->assertJsonStructure([
                    'data',
                    'meta',
                ]);
        }
    }

    public function test_tu_can_create_student(): void
    {
        $tu = $this->createUser('TU');
        $studentUser = $this->createUser('SISWA');

        Sanctum::actingAs($tu);

        $response = $this->postJson('/api/students', [
            'user_id' => $studentUser->id,
            'nisn' => '1234567890',
            'nis' => 'NIS-001',
            'nama_lengkap' => 'Siswa Baru',
            'jenis_kelamin' => 'P',
            'tanggal_lahir' => '2010-05-10',
            'tempat_lahir' => 'Padang',
            'no_telepon' => '081234567890',
            'alamat' => 'Padang',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.user_id', $studentUser->id)
            ->assertJsonPath('data.nisn', '1234567890')
            ->assertJsonPath('data.nama_lengkap', 'Siswa Baru');

        $this->assertDatabaseHas('siswa', [
            'user_id' => $studentUser->id,
            'nisn' => '1234567890',
            'nama_lengkap' => 'Siswa Baru',
        ]);
    }

    public function test_non_tu_cannot_create_student(): void
    {
        $guru = $this->createUser('GURU');
        $studentUser = $this->createUser('SISWA');

        Sanctum::actingAs($guru);

        $this->postJson('/api/students', [
            'user_id' => $studentUser->id,
            'nisn' => '1234567890',
            'nama_lengkap' => 'Siswa Baru',
        ])
            ->assertStatus(403)
            ->assertJsonPath(
                'message',
                'Anda tidak memiliki akses ke resource ini.'
            );
    }

    public function test_tu_can_view_student_detail(): void
    {
        $tu = $this->createUser('TU');
        $student = $this->createStudent();

        Sanctum::actingAs($tu);

        $this->getJson("/api/students/{$student->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $student->id)
            ->assertJsonPath('data.user_id', $student->user_id)
            ->assertJsonPath('data.nisn', $student->nisn);
    }

    public function test_kepala_sekolah_can_view_all_students(): void
    {
        $kepala = $this->createUser('KEPALA_SEKOLAH');

        $studentOne = $this->createStudent();
        $studentTwo = $this->createStudent();

        Sanctum::actingAs($kepala);

        $response = $this->getJson('/api/students');

        $response
            ->assertOk()
            ->assertJsonFragment([
                'id' => $studentOne->id,
            ])
            ->assertJsonFragment([
                'id' => $studentTwo->id,
            ]);
    }

    public function test_siswa_can_view_only_own_record(): void
    {
        $studentUser = $this->createUser('SISWA');
        $ownStudent = $this->createStudent([], $studentUser);
        $otherStudent = $this->createStudent();

        Sanctum::actingAs($studentUser);

        $response = $this->getJson('/api/students');

        $response
            ->assertOk()
            ->assertJsonFragment([
                'id' => $ownStudent->id,
            ])
            ->assertJsonMissing([
                'id' => $otherStudent->id,
            ]);
    }

    public function test_siswa_cannot_view_another_student_detail(): void
    {
        $studentUser = $this->createUser('SISWA');
        $ownStudent = $this->createStudent([], $studentUser);
        $otherStudent = $this->createStudent();

        Sanctum::actingAs($studentUser);

        $this->getJson("/api/students/{$otherStudent->id}")
            ->assertStatus(403);

        $this->getJson("/api/students/{$ownStudent->id}")
            ->assertOk();
    }

    public function test_guru_can_view_students_in_teaching_class(): void
    {
        $teacher = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $student = $this->createStudent();

        $this->addStudentToClass($student, $class);

        $this->createTeachingSchedule(
            $teacher,
            $class,
            $academicYear
        );

        $this->createTeachingSchedule(
            $teacher,
            $class,
            $academicYear
        );

        $this->assertDatabaseHas('anggota_kelas', [
            'siswa_id' => $student->id,
            'kelas_id' => $class->id,
        ]);

        $this->assertDatabaseHas('jadwal_pelajaran', [
            'guru_id' => $teacher->id,
            'kelas_id' => $class->id,
            'tahun_akademik_id' => $academicYear->id,
        ]);

        $this->assertSame($teacher->id, $teacher->user->guru->id);

        $this->assertDatabaseHas('jadwal_pelajaran', [
            'guru_id' => $teacher->user->guru->id,
            'kelas_id' => $class->id,
        ]);

        Sanctum::actingAs($teacher->user);

        $this->getJson('/api/students')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $student->id,
            ]);
    }

    public function test_guru_cannot_view_student_from_unassigned_teaching_class(): void
    {
        $teacher = $this->createTeacher();

        $academicYear = $this->createAcademicYear();

        $assignedClass = $this->createClass($academicYear, [
            'nama' => 'X IPA Assigned',
        ]);

        $otherClass = $this->createClass($academicYear, [
            'nama' => 'X IPA Other',
        ]);

        $assignedStudent = $this->createStudent([
            'nama_lengkap' => 'Siswa Assigned',
        ]);

        $otherStudent = $this->createStudent([
            'nama_lengkap' => 'Siswa Other',
        ]);

        $this->addStudentToClass($assignedStudent, $assignedClass);
        $this->addStudentToClass($otherStudent, $otherClass);

        $this->createTeachingSchedule(
            $teacher,
            $assignedClass,
            $academicYear
        );

        Sanctum::actingAs($teacher->user);

        $response = $this->getJson('/api/students');

        $response
            ->assertOk()
            ->assertJsonFragment([
                'id' => $assignedStudent->id,
            ])
            ->assertJsonPath(
                'data.0.id',
                $assignedStudent->id
            )
            ->assertJsonCount(1, 'data');
    }

    public function test_guru_can_view_students_in_wali_kelas_assignment(): void
    {
        $teacher = $this->createTeacher();

        $academicYear = $this->createAcademicYear();

        $class = $this->createClass($academicYear);

        $student = $this->createStudent();

        $this->addStudentToClass($student, $class);

        $this->assignWaliKelas(
            $teacher,
            $class,
            $academicYear
        );

        Sanctum::actingAs($teacher->user);

        $this->getJson("/api/students?academic_year_id={$academicYear->id}")
            ->assertOk()
            ->assertJsonFragment([
                'id' => $student->id,
            ]);
    }

    public function test_wali_kelas_assignment_does_not_grant_access_to_another_academic_year(): void
    {
        $teacher = $this->createTeacher();

        $currentYear = $this->createAcademicYear([
            'tahun_mulai' => 2026,
            'tahun_selesai' => 2027,
        ]);

        $otherYear = $this->createAcademicYear([
            'tahun_mulai' => 2027,
            'tahun_selesai' => 2028,
            'semester' => 'GENAP',
        ]);

        $currentClass = $this->createClass($currentYear, [
            'nama' => 'X IPA Current',
        ]);

        $otherClass = $this->createClass($otherYear, [
            'nama' => 'X IPA Other Year',
        ]);

        $currentStudent = $this->createStudent([
            'nama_lengkap' => 'Siswa Tahun Aktif',
        ]);

        $otherStudent = $this->createStudent([
            'nama_lengkap' => 'Siswa Tahun Lain',
        ]);

        $this->addStudentToClass($currentStudent, $currentClass);
        $this->addStudentToClass($otherStudent, $otherClass);

        $this->assignWaliKelas(
            $teacher,
            $currentClass,
            $currentYear
        );

        Sanctum::actingAs($teacher->user);

        $response = $this->getJson(
            "/api/students?academic_year_id={$otherYear->id}"
        );

        $response
            ->assertOk()
            ->assertJsonMissing([
                'id' => $currentStudent->id,
            ])
            ->assertJsonMissing([
                'id' => $otherStudent->id,
            ]);
    }

    public function test_guru_can_view_student_detail_in_teaching_scope(): void
    {
        $teacher = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $student = $this->createStudent();

        $this->addStudentToClass($student, $class);

        $this->createTeachingSchedule(
            $teacher,
            $class,
            $academicYear
        );

        Sanctum::actingAs($teacher->user);

        $this->getJson(
            "/api/students/{$student->id}?academic_year_id={$academicYear->id}"
        )
            ->assertOk()
            ->assertJsonPath('data.id', $student->id);
    }

    public function test_guru_cannot_view_student_detail_outside_scope(): void
    {
        $teacher = $this->createTeacher();

        $academicYear = $this->createAcademicYear();

        $class = $this->createClass($academicYear);

        $student = $this->createStudent();

        $this->addStudentToClass($student, $class);

        Sanctum::actingAs($teacher->user);

        $this->getJson("/api/students/{$student->id}")
            ->assertStatus(403);
    }

    public function test_create_student_requires_valid_fields(): void
    {
        $tu = $this->createUser('TU');

        Sanctum::actingAs($tu);

        $this->postJson('/api/students', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'user_id',
                'nisn',
                'nama_lengkap',
            ]);
    }

    public function test_create_student_rejects_non_siswa_user(): void
    {
        $tu = $this->createUser('TU');
        $guru = $this->createUser('GURU');

        Sanctum::actingAs($tu);

        $this->postJson('/api/students', [
            'user_id' => $guru->id,
            'nisn' => 'NISN-INVALID',
            'nama_lengkap' => 'Invalid Siswa',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'user_id',
            ]);
    }

    public function test_create_student_rejects_duplicate_user(): void
    {
        $tu = $this->createUser('TU');
        $student = $this->createStudent();

        Sanctum::actingAs($tu);

        $this->postJson('/api/students', [
            'user_id' => $student->user_id,
            'nisn' => 'NISN-SECOND',
            'nama_lengkap' => 'Siswa Kedua',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'user_id',
            ]);
    }

    public function test_create_student_rejects_duplicate_nisn(): void
    {
        $tu = $this->createUser('TU');
        $student = $this->createStudent();

        Sanctum::actingAs($tu);

        $this->postJson('/api/students', [
            'user_id' => $this->createUser('SISWA')->id,
            'nisn' => $student->nisn,
            'nama_lengkap' => 'Siswa Duplicate NISN',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'nisn',
            ]);
    }

    public function test_tu_can_update_student(): void
    {
        $tu = $this->createUser('TU');
        $student = $this->createStudent();

        Sanctum::actingAs($tu);

        $response = $this->putJson("/api/students/{$student->id}", [
            'user_id' => $student->user_id,
            'nisn' => 'NISN-UPDATED',
            'nis' => 'NIS-UPDATED',
            'nama_lengkap' => 'Siswa Updated',
            'jenis_kelamin' => 'P',
            'tanggal_lahir' => '2010-06-10',
            'tempat_lahir' => 'Bukittinggi',
            'no_telepon' => '08999999999',
            'alamat' => 'Alamat Updated',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.nisn', 'NISN-UPDATED')
            ->assertJsonPath('data.nama_lengkap', 'Siswa Updated');

        $this->assertDatabaseHas('siswa', [
            'id' => $student->id,
            'nisn' => 'NISN-UPDATED',
            'nama_lengkap' => 'Siswa Updated',
        ]);
    }

    public function test_non_tu_cannot_update_student(): void
    {
        $guru = $this->createUser('GURU');
        $student = $this->createStudent();

        Sanctum::actingAs($guru);

        $this->putJson("/api/students/{$student->id}", [
            'user_id' => $student->user_id,
            'nisn' => 'NISN-UPDATED',
            'nama_lengkap' => 'Siswa Updated',
        ])
            ->assertStatus(403)
            ->assertJsonPath(
                'message',
                'Anda tidak memiliki akses ke resource ini.'
            );
    }

    public function test_tu_can_delete_student(): void
    {
        $tu = $this->createUser('TU');
        $student = $this->createStudent();

        Sanctum::actingAs($tu);

        $this->deleteJson("/api/students/{$student->id}")
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Data siswa berhasil dihapus.'
            );

        $this->assertDatabaseMissing('siswa', [
            'id' => $student->id,
        ]);
    }

    public function test_non_tu_cannot_delete_student(): void
    {
        $guru = $this->createUser('GURU');
        $student = $this->createStudent();

        Sanctum::actingAs($guru);

        $this->deleteJson("/api/students/{$student->id}")
            ->assertStatus(403)
            ->assertJsonPath(
                'message',
                'Anda tidak memiliki akses ke resource ini.'
            );
    }

    public function test_student_list_supports_search(): void
    {
        $tu = $this->createUser('TU');

        $student = $this->createStudent([
            'nama_lengkap' => 'Ahmad Fauzan',
            'nisn' => 'NISN-AHMAD',
            'nis' => 'NIS-AHMAD',
        ]);

        $this->createStudent([
            'nama_lengkap' => 'Budi Santoso',
            'nisn' => 'NISN-BUDI',
            'nis' => 'NIS-BUDI',
        ]);

        Sanctum::actingAs($tu);

        $response = $this->getJson('/api/students?search=Ahmad');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $student->id);
    }

    public function test_student_list_supports_class_filter(): void
    {
        $tu = $this->createUser('TU');

        $academicYear = $this->createAcademicYear();

        $classOne = $this->createClass($academicYear, [
            'nama' => 'X IPA 1',
        ]);

        $classTwo = $this->createClass($academicYear, [
            'nama' => 'X IPA 2',
        ]);

        $studentOne = $this->createStudent([
            'nama_lengkap' => 'Siswa Kelas 1',
        ]);

        $studentTwo = $this->createStudent([
            'nama_lengkap' => 'Siswa Kelas 2',
        ]);

        $this->addStudentToClass($studentOne, $classOne);
        $this->addStudentToClass($studentTwo, $classTwo);

        Sanctum::actingAs($tu);

        $response = $this->getJson(
            "/api/students?class_id={$classOne->id}"
        );

        $response
        ->assertOk()
        ->assertJsonPath('data.0.id', $studentOne->id)
        ->assertJsonCount(1, 'data');
    }

    public function test_student_list_supports_academic_year_filter(): void
    {
        $tu = $this->createUser('TU');

        $yearOne = $this->createAcademicYear([
            'tahun_mulai' => 2026,
            'tahun_selesai' => 2027,
        ]);

        $yearTwo = $this->createAcademicYear([
            'tahun_mulai' => 2027,
            'tahun_selesai' => 2028,
            'semester' => 'GENAP',
        ]);

        $classOne = $this->createClass($yearOne, [
            'nama' => 'X IPA Year One',
        ]);

        $classTwo = $this->createClass($yearTwo, [
            'nama' => 'X IPA Year Two',
        ]);

        $studentOne = $this->createStudent([
            'nama_lengkap' => 'Siswa Tahun Satu',
        ]);

        $studentTwo = $this->createStudent([
            'nama_lengkap' => 'Siswa Tahun Dua',
        ]);

        $this->addStudentToClass($studentOne, $classOne);
        $this->addStudentToClass($studentTwo, $classTwo);

        Sanctum::actingAs($tu);

        $response = $this->getJson(
            "/api/students?academic_year_id={$yearOne->id}"
        );

        $response
        ->assertOk()
        ->assertJsonPath('data.0.id', $studentOne->id)
        ->assertJsonCount(1, 'data');
    }

    public function test_student_list_supports_pagination(): void
    {
        $tu = $this->createUser('TU');

        for ($i = 1; $i <= 3; $i++) {
            $this->createStudent([
                'nama_lengkap' => "Siswa {$i}",
            ]);
        }

        Sanctum::actingAs($tu);

        $response = $this->getJson('/api/students?page_size=2');

        $response
            ->assertOk()
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.total', 3);
    }

    public function test_student_show_returns_404_when_not_found(): void
    {
        $tu = $this->createUser('TU');

        Sanctum::actingAs($tu);

        $this->getJson('/api/students/999999')
            ->assertNotFound();
    }

    public function test_student_update_returns_404_when_not_found(): void
    {
        $tu = $this->createUser('TU');
        $studentUser = $this->createUser('SISWA');

        Sanctum::actingAs($tu);

        $this->putJson('/api/students/999999', [
            'user_id' => $studentUser->id,
            'nisn' => 'NISN-NOTFOUND',
            'nama_lengkap' => 'Siswa Not Found',
        ])
            ->assertNotFound();
    }
}