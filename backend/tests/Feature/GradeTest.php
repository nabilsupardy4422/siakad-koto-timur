<?php

namespace Tests\Feature;

use App\Models\AnggotaKelas;
use App\Models\Guru;
use App\Models\JadwalPelajaran;
use App\Models\Kelas;
use App\Models\KomponenNilai;
use App\Models\Mapel;
use App\Models\Nilai;
use App\Models\Role;
use App\Models\Siswa;
use App\Models\TahunAkademik;
use App\Models\User;
use App\Models\WaliKelas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GradeTest extends TestCase
{
    use RefreshDatabase;

    private function createRole(string $code): Role
    {
        return Role::firstOrCreate(
            ['code' => $code],
            [
                'name' => match ($code) {
                    'TU' => 'Tata Usaha',
                    'KEPALA_SEKOLAH' => 'Kepala Sekolah',
                    'GURU' => 'Guru',
                    'SISWA' => 'Siswa',
                    default => $code,
                },
            ]
        );
    }

    private function createUser(
        string $roleCode,
        string $suffix
    ): User {
        $role = $this->createRole($roleCode);

        return User::create([
            'role_id' => $role->id,
            'username' => strtolower(
                $roleCode . '.' . $suffix
            ),
            'name' => $roleCode . ' ' . $suffix,
            'email' => strtolower(
                $roleCode . '.' . $suffix . '@siakad.test'
            ),
            'password' => 'password-test',
            'account_status' => 'active',
        ]);
    }

    private function createAcademicYear(
        string $semester = 'Ganjil'
    ): TahunAkademik {
        return TahunAkademik::create([
            'tahun_mulai' => 2026,
            'tahun_selesai' => 2027,
            'semester' => $semester,
            'aktif' => true,
        ]);
    }

    private function createTeacher(
        string $suffix
    ): array {
        $user = $this->createUser(
            'GURU',
            $suffix
        );

        $guru = Guru::create([
            'user_id' => $user->id,
            'nip' => '19800101' . str_pad(
                (string) $user->id,
                4,
                '0',
                STR_PAD_LEFT
            ),
            'nama_lengkap' => 'Guru ' . $suffix,
            'jenis_kelamin' => 'L',
        ]);

        return [$user, $guru];
    }

    private function createStudent(
        string $suffix
    ): array {
        $user = $this->createUser(
            'SISWA',
            $suffix
        );

        $siswa = Siswa::create([
            'user_id' => $user->id,
            'nisn' => 'NISN' . str_pad(
                (string) $user->id,
                6,
                '0',
                STR_PAD_LEFT
            ),
            'nis' => 'NIS' . str_pad(
                (string) $user->id,
                4,
                '0',
                STR_PAD_LEFT
            ),
            'nama_lengkap' => 'Siswa ' . $suffix,
            'jenis_kelamin' => 'L',
        ]);

        return [$user, $siswa];
    }

    private function createSubject(
        string $suffix = 'MTK'
    ): Mapel {
        return Mapel::create([
            'kode' => $suffix,
            'nama' => 'Matematika ' . $suffix,
        ]);
    }

    private function createClass(
        TahunAkademik $academicYear,
        string $name
    ): Kelas {
        return Kelas::create([
            'tahun_akademik_id' => $academicYear->id,
            'nama' => $name,
            'tingkat' => 10,
        ]);
    }

    private function createSchedule(
        TahunAkademik $academicYear,
        Kelas $class,
        Mapel $subject,
        Guru $teacher
    ): JadwalPelajaran {
        return JadwalPelajaran::create([
            'tahun_akademik_id' => $academicYear->id,
            'kelas_id' => $class->id,
            'mapel_id' => $subject->id,
            'guru_id' => $teacher->id,
            'hari' => 'Senin',
            'jam_mulai' => '08:00',
            'jam_selesai' => '09:00',
        ]);
    }

    private function createComponent(
        JadwalPelajaran $schedule,
        string $name = 'Tugas 1'
    ): KomponenNilai {
        return KomponenNilai::create([
            'jadwal_pelajaran_id' => $schedule->id,
            'nama' => $name,
            'bobot' => 25,
        ]);
    }

    private function addStudentToClass(
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

    private function createGrade(
        KomponenNilai $component,
        Siswa $student,
        float $value = 85
    ): Nilai {
        return Nilai::create([
            'komponen_nilai_id' => $component->id,
            'siswa_id' => $student->id,
            'nilai' => $value,
            'catatan' => null,
        ]);
    }

    public function test_guest_cannot_access_grades(): void
    {
        $response = $this->getJson('/api/grades');

        $response->assertUnauthorized();
    }

    public function test_teacher_can_create_grade_in_own_teaching_context(): void
    {
        [$teacherUser, $teacher] = $this->createTeacher('create');

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass(
            $academicYear,
            'X IPA 1'
        );
        $subject = $this->createSubject();

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $subject,
            $teacher
        );

        [, $student] = $this->createStudent('create');
        $this->addStudentToClass(
            $student,
            $class
        );

        $component = $this->createComponent(
            $schedule
        );

        $response = $this
            ->actingAs($teacherUser)
            ->postJson('/api/grades', [
                'komponen_nilai_id' => $component->id,
                'siswa_id' => $student->id,
                'nilai' => 85,
                'catatan' => 'Baik',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.nilai',
                '85.00'
            );

        $this->assertDatabaseHas('nilai', [
            'komponen_nilai_id' => $component->id,
            'siswa_id' => $student->id,
            'nilai' => 85,
            'catatan' => 'Baik',
        ]);
    }

    public function test_teacher_cannot_create_grade_for_another_teacher_schedule(): void
    {
        [$teacherUser] = $this->createTeacher('owner');
        [, $otherTeacher] = $this->createTeacher('other');

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass(
            $academicYear,
            'X IPA 1'
        );
        $subject = $this->createSubject();

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $subject,
            $otherTeacher
        );

        [, $student] = $this->createStudent('other-schedule');

        $this->addStudentToClass(
            $student,
            $class
        );

        $component = $this->createComponent(
            $schedule
        );

        $response = $this
            ->actingAs($teacherUser)
            ->postJson('/api/grades', [
                'komponen_nilai_id' => $component->id,
                'siswa_id' => $student->id,
                'nilai' => 80,
            ]);

        $response->assertForbidden();
    }

    public function test_teacher_cannot_create_grade_for_student_outside_schedule_class(): void
    {
        [$teacherUser, $teacher] = $this->createTeacher(
            'student-scope'
        );

        $academicYear = $this->createAcademicYear();

        $classOne = $this->createClass(
            $academicYear,
            'X IPA 1'
        );

        $classTwo = $this->createClass(
            $academicYear,
            'X IPA 2'
        );

        $subject = $this->createSubject();

        $schedule = $this->createSchedule(
            $academicYear,
            $classOne,
            $subject,
            $teacher
        );

        [, $student] = $this->createStudent(
            'outside-class'
        );

        $this->addStudentToClass(
            $student,
            $classTwo
        );

        $component = $this->createComponent(
            $schedule
        );

        $response = $this
            ->actingAs($teacherUser)
            ->postJson('/api/grades', [
                'komponen_nilai_id' => $component->id,
                'siswa_id' => $student->id,
                'nilai' => 80,
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(
                'siswa_id'
            );
    }

    public function test_non_teacher_cannot_create_grade(): void
    {
        [$teacherUser, $teacher] = $this->createTeacher(
            'non-teacher-context'
        );

        $studentUser = $this->createUser(
            'SISWA',
            'cannot-create'
        );

        $academicYear = $this->createAcademicYear();

        $class = $this->createClass(
            $academicYear,
            'X IPA 1'
        );

        $subject = $this->createSubject();

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $subject,
            $teacher
        );

        [, $student] = $this->createStudent(
            'target'
        );

        $this->addStudentToClass(
            $student,
            $class
        );

        $component = $this->createComponent(
            $schedule
        );

        $response = $this
            ->actingAs($studentUser)
            ->postJson('/api/grades', [
                'komponen_nilai_id' => $component->id,
                'siswa_id' => $student->id,
                'nilai' => 80,
            ]);

        $response->assertForbidden();
    }

    public function test_teacher_can_view_own_grade(): void
    {
        [$teacherUser, $teacher] = $this->createTeacher(
            'view-own'
        );

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass(
            $academicYear,
            'X IPA 1'
        );
        $subject = $this->createSubject();

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $subject,
            $teacher
        );

        [, $student] = $this->createStudent(
            'view-own'
        );

        $this->addStudentToClass(
            $student,
            $class
        );

        $component = $this->createComponent(
            $schedule
        );

        $grade = $this->createGrade(
            $component,
            $student
        );

        $response = $this
            ->actingAs($teacherUser)
            ->getJson('/api/grades');

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.0.id',
                $grade->id
            );
    }

    public function test_teacher_cannot_view_grade_from_another_teacher_schedule(): void
    {
        [$teacherUser] = $this->createTeacher(
            'viewer'
        );

        [, $otherTeacher] = $this->createTeacher(
            'owner'
        );

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass(
            $academicYear,
            'X IPA 1'
        );
        $subject = $this->createSubject();

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $subject,
            $otherTeacher
        );

        [, $student] = $this->createStudent(
            'other-teacher'
        );

        $this->addStudentToClass(
            $student,
            $class
        );

        $component = $this->createComponent(
            $schedule
        );

        $grade = $this->createGrade(
            $component,
            $student
        );

        $response = $this
            ->actingAs($teacherUser)
            ->getJson('/api/grades');

        $response
            ->assertOk()
            ->assertJsonMissing([
                'id' => $grade->id,
            ]);
    }

    public function test_wali_kelas_can_view_grade_from_assigned_class(): void
    {
        [$waliUser, $waliGuru] = $this->createTeacher(
            'wali'
        );

        [, $subjectTeacher] = $this->createTeacher(
            'subject'
        );

        $academicYear = $this->createAcademicYear();

        $class = $this->createClass(
            $academicYear,
            'X IPA 1'
        );

        $subject = $this->createSubject();

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $subject,
            $subjectTeacher
        );

        WaliKelas::create([
            'guru_id' => $waliGuru->id,
            'kelas_id' => $class->id,
            'tahun_akademik_id' => $academicYear->id,
            'tanggal_mulai' => '2026-07-01',
            'tanggal_selesai' => null,
        ]);

        [, $student] = $this->createStudent(
            'wali-class'
        );

        $this->addStudentToClass(
            $student,
            $class
        );

        $component = $this->createComponent(
            $schedule
        );

        $grade = $this->createGrade(
            $component,
            $student
        );

        $response = $this
            ->actingAs($waliUser)
            ->getJson('/api/grades');

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.0.id',
                $grade->id
            );
    }

    public function test_student_can_view_only_own_grade(): void
    {
        [$teacherUser, $teacher] = $this->createTeacher(
            'student-view'
        );

        [$studentUser, $student] = $this->createStudent(
            'own'
        );

        [, $otherStudent] = $this->createStudent(
            'other'
        );

        $academicYear = $this->createAcademicYear();

        $class = $this->createClass(
            $academicYear,
            'X IPA 1'
        );

        $subject = $this->createSubject();

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $subject,
            $teacher
        );

        $this->addStudentToClass(
            $student,
            $class
        );

        $this->addStudentToClass(
            $otherStudent,
            $class
        );

        $component = $this->createComponent(
            $schedule
        );

        $ownGrade = $this->createGrade(
            $component,
            $student,
            90
        );

        $otherGrade = $this->createGrade(
            $component,
            $otherStudent,
            70
        );

        $response = $this
            ->actingAs($studentUser)
            ->getJson('/api/grades');

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.0.id',
                $ownGrade->id
            )
            ->assertJsonMissing([
                'id' => $otherGrade->id,
            ]);
    }

    public function test_tu_can_view_grades(): void
    {
        $tuUser = $this->createUser(
            'TU',
            'view-grades'
        );

        [, $teacher] = $this->createTeacher(
            'tu-grade'
        );

        $academicYear = $this->createAcademicYear();

        $class = $this->createClass(
            $academicYear,
            'X IPA 1'
        );

        $subject = $this->createSubject();

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $subject,
            $teacher
        );

        [, $student] = $this->createStudent(
            'tu-grade'
        );

        $this->addStudentToClass(
            $student,
            $class
        );

        $component = $this->createComponent(
            $schedule
        );

        $grade = $this->createGrade(
            $component,
            $student
        );

        $response = $this
            ->actingAs($tuUser)
            ->getJson('/api/grades');

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.0.id',
                $grade->id
            );
    }

    public function test_kepala_sekolah_can_view_grades(): void
    {
        $principalUser = $this->createUser(
            'KEPALA_SEKOLAH',
            'view-grades'
        );

        [, $teacher] = $this->createTeacher(
            'principal-grade'
        );

        $academicYear = $this->createAcademicYear();

        $class = $this->createClass(
            $academicYear,
            'X IPA 1'
        );

        $subject = $this->createSubject();

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $subject,
            $teacher
        );

        [, $student] = $this->createStudent(
            'principal-grade'
        );

        $this->addStudentToClass(
            $student,
            $class
        );

        $component = $this->createComponent(
            $schedule
        );

        $grade = $this->createGrade(
            $component,
            $student
        );

        $response = $this
            ->actingAs($principalUser)
            ->getJson('/api/grades');

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.0.id',
                $grade->id
            );
    }

    public function test_teacher_can_update_own_grade(): void
    {
        [$teacherUser, $teacher] = $this->createTeacher(
            'update-own'
        );

        $academicYear = $this->createAcademicYear();

        $class = $this->createClass(
            $academicYear,
            'X IPA 1'
        );

        $subject = $this->createSubject();

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $subject,
            $teacher
        );

        [, $student] = $this->createStudent(
            'update-own'
        );

        $this->addStudentToClass(
            $student,
            $class
        );

        $component = $this->createComponent(
            $schedule
        );

        $grade = $this->createGrade(
            $component,
            $student,
            70
        );

        $response = $this
            ->actingAs($teacherUser)
            ->putJson(
                '/api/grades/' . $grade->id,
                [
                    'nilai' => 90,
                    'catatan' => 'Diperbarui',
                ]
            );

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.nilai',
                '90.00'
            );

        $this->assertDatabaseHas('nilai', [
            'id' => $grade->id,
            'nilai' => 90,
            'catatan' => 'Diperbarui',
        ]);
    }

    public function test_teacher_cannot_update_another_teacher_grade(): void
    {
        [$teacherUser] = $this->createTeacher(
            'update-viewer'
        );

        [, $otherTeacher] = $this->createTeacher(
            'update-owner'
        );

        $academicYear = $this->createAcademicYear();

        $class = $this->createClass(
            $academicYear,
            'X IPA 1'
        );

        $subject = $this->createSubject();

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $subject,
            $otherTeacher
        );

        [, $student] = $this->createStudent(
            'update-other'
        );

        $this->addStudentToClass(
            $student,
            $class
        );

        $component = $this->createComponent(
            $schedule
        );

        $grade = $this->createGrade(
            $component,
            $student
        );

        $response = $this
            ->actingAs($teacherUser)
            ->putJson(
                '/api/grades/' . $grade->id,
                [
                    'nilai' => 95,
                ]
            );

        $response->assertForbidden();
    }

    public function test_duplicate_grade_is_rejected(): void
    {
        [$teacherUser, $teacher] = $this->createTeacher(
            'duplicate'
        );

        $academicYear = $this->createAcademicYear();

        $class = $this->createClass(
            $academicYear,
            'X IPA 1'
        );

        $subject = $this->createSubject();

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $subject,
            $teacher
        );

        [, $student] = $this->createStudent(
            'duplicate'
        );

        $this->addStudentToClass(
            $student,
            $class
        );

        $component = $this->createComponent(
            $schedule
        );

        $this->createGrade(
            $component,
            $student
        );

        $response = $this
            ->actingAs($teacherUser)
            ->postJson('/api/grades', [
                'komponen_nilai_id' => $component->id,
                'siswa_id' => $student->id,
                'nilai' => 90,
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(
                'siswa_id'
            );
    }

    public function test_grade_requires_required_fields(): void
    {
        [$teacherUser] = $this->createTeacher(
            'required'
        );

        $response = $this
            ->actingAs($teacherUser)
            ->postJson('/api/grades', []);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'komponen_nilai_id',
                'siswa_id',
                'nilai',
            ]);
    }

    public function test_grade_rejects_non_numeric_value(): void
    {
        [$teacherUser] = $this->createTeacher(
            'numeric'
        );

        $response = $this
            ->actingAs($teacherUser)
            ->postJson('/api/grades', [
                'komponen_nilai_id' => 1,
                'siswa_id' => 1,
                'nilai' => 'abc',
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(
                'nilai'
            );
    }

    public function test_grade_list_supports_filters_and_pagination(): void
    {
        [$teacherUser, $teacher] = $this->createTeacher(
            'filters'
        );

        $academicYear = $this->createAcademicYear(
            'Ganjil'
        );

        $classOne = $this->createClass(
            $academicYear,
            'X IPA 1'
        );

        $classTwo = $this->createClass(
            $academicYear,
            'X IPA 2'
        );

        $subject = $this->createSubject();

        $scheduleOne = $this->createSchedule(
            $academicYear,
            $classOne,
            $subject,
            $teacher
        );

        $scheduleTwo = $this->createSchedule(
            $academicYear,
            $classTwo,
            $subject,
            $teacher
        );

        [, $studentOne] = $this->createStudent(
            'filter-one'
        );

        [, $studentTwo] = $this->createStudent(
            'filter-two'
        );

        $this->addStudentToClass(
            $studentOne,
            $classOne
        );

        $this->addStudentToClass(
            $studentTwo,
            $classTwo
        );

        $componentOne = $this->createComponent(
            $scheduleOne,
            'Tugas 1'
        );

        $componentTwo = $this->createComponent(
            $scheduleTwo,
            'Tugas 1'
        );

        $gradeOne = $this->createGrade(
            $componentOne,
            $studentOne
        );

        $this->createGrade(
            $componentTwo,
            $studentTwo
        );

        $response = $this
            ->actingAs($teacherUser)
            ->getJson(
                '/api/grades?' .
                http_build_query([
                    'academic_year_id' => $academicYear->id,
                    'semester' => 'Ganjil',
                    'schedule_id' => $scheduleOne->id,
                    'class_id' => $classOne->id,
                    'subject_id' => $subject->id,
                    'student_id' => $studentOne->id,
                    'component_id' => $componentOne->id,
                    'per_page' => 1,
                ])
            );

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.0.id',
                $gradeOne->id
            )
            ->assertJsonPath(
                'meta.total',
                1
            )
            ->assertJsonPath(
                'meta.per_page',
                1
            );
    }
}