<?php

namespace Tests\Feature;

use App\Models\AnggotaKelas;
use App\Models\Guru;
use App\Models\JadwalPelajaran;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\Role;
use App\Models\Siswa;
use App\Models\TahunAkademik;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(
        string $roleCode,
        string $suffix = ''
    ): User {
        $role = Role::firstOrCreate(
            ['code' => $roleCode],
            ['name' => $roleCode]
        );

        return User::create([
            'role_id' => $role->id,
            'username' => strtolower($roleCode) . $suffix,
            'name' => $roleCode . ' Report Test' . $suffix,
            'email' => strtolower($roleCode) . $suffix . '@example.test',
            'password' => Hash::make('password-test'),
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
        string $suffix = ''
    ): array {
        $user = $this->createUser('GURU', $suffix);

        $guru = Guru::create([
            'user_id' => $user->id,
            'nip' => '19800101' . str_pad(
                (string) $user->id,
                4,
                '0',
                STR_PAD_LEFT
            ),
            'nama_lengkap' => 'Guru Report ' . $suffix,
            'jenis_kelamin' => 'L',
            'no_telepon' => null,
            'alamat' => null,
        ]);

        return [
            'user' => $user,
            'guru' => $guru,
        ];
    }

    private function createStudent(
        string $suffix = ''
    ): array {
        $user = $this->createUser('SISWA', $suffix);

        $siswa = Siswa::create([
            'user_id' => $user->id,
            'nis' => 'NIS' . $user->id,
            'nisn' => 'NISN' . $user->id,
            'nama_lengkap' => 'Siswa Report ' . $suffix,
            'jenis_kelamin' => 'L',
            'tanggal_lahir' => '2010-01-01',
            'tempat_lahir' => 'Padang',
            'no_telepon' => null,
            'alamat' => null,
        ]);

        return [
            'user' => $user,
            'student' => $siswa,
        ];
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

    private function createSubject(
        string $suffix = ''
    ): Mapel {
        return Mapel::create([
            'kode' => 'MTK' . $suffix,
            'nama' => 'Matematika Report ' . $suffix,
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

    private function reportUrl(
        string $reportType,
        array $query = []
    ): string {
        return '/api/reports/' . $reportType
            . '?' . http_build_query($query);
    }

    public function test_guest_cannot_access_reports(): void
    {
        $response = $this->getJson(
            $this->reportUrl('students', [
                'format' => 'excel',
            ])
        );

        $response->assertUnauthorized();
    }

    public function test_tu_can_export_all_baseline_report_types_as_excel(): void
    {
        $tu = $this->createUser('TU');

        $academicYear = $this->createAcademicYear();

        $student = $this->createStudent('TU');
        $class = $this->createClass(
            $academicYear,
            'X IPA Report'
        );

        $this->addStudentToClass(
            $student['student'],
            $class
        );

        $teacher = $this->createTeacher('TU');
        $subject = $this->createSubject('TU');

        $this->createSchedule(
            $academicYear,
            $class,
            $subject,
            $teacher['guru']
        );

        foreach ([
            'students',
            'teachers',
            'classes',
            'schedules',
            'attendance',
            'grades',
            'semester-recap',
        ] as $reportType) {
            $filters = [
                'format' => 'excel',
            ];

            if (in_array(
                $reportType,
                [
                    'students',
                    'classes',
                    'schedules',
                    'attendance',
                    'grades',
                    'semester-recap',
                ],
                true
            )) {
                $filters['academic_year_id'] = $academicYear->id;
            }

            if (in_array(
                $reportType,
                [
                    'schedules',
                    'attendance',
                    'grades',
                    'semester-recap',
                ],
                true
            )) {
                $filters['semester'] = $academicYear->semester;
            }

            $response = $this
                ->actingAs($tu)
                ->get(
                    $this->reportUrl(
                        $reportType,
                        $filters
                    )
                );

            $response->assertOk();
            $response->assertHeader(
                'Content-Disposition'
            );
        }
    }

    public function test_tu_can_export_pdf_report(): void
    {
        $tu = $this->createUser('TU');

        $academicYear = $this->createAcademicYear();

        $student = $this->createStudent('PDF');
        $class = $this->createClass(
            $academicYear,
            'X IPA PDF'
        );

        $this->addStudentToClass(
            $student['student'],
            $class
        );

        $response = $this
            ->actingAs($tu)
            ->get(
                $this->reportUrl(
                    'students',
                    [
                        'format' => 'pdf',
                        'academic_year_id' => $academicYear->id,
                    ]
                )
            );

        $response->assertOk();
        $response->assertHeader(
            'Content-Type',
            'application/pdf'
        );
        $response->assertHeader(
            'Content-Disposition'
        );
    }

    public function test_kepala_sekolah_can_export_school_report(): void
    {
        $user = $this->createUser('KEPALA_SEKOLAH');

        $academicYear = $this->createAcademicYear();

        $class = $this->createClass(
            $academicYear,
            'X IPA Kepala'
        );

        $response = $this
            ->actingAs($user)
            ->get(
                $this->reportUrl(
                    'classes',
                    [
                        'format' => 'excel',
                        'academic_year_id' => $academicYear->id,
                    ]
                )
            );

        $response->assertOk();
        $response->assertHeader(
            'Content-Disposition'
        );
    }

    public function test_student_can_export_only_own_student_report(): void
    {
        $studentOne = $this->createStudent('One');
        $studentTwo = $this->createStudent('Two');

        $academicYear = $this->createAcademicYear();

        $classOne = $this->createClass(
            $academicYear,
            'X IPA 1'
        );

        $classTwo = $this->createClass(
            $academicYear,
            'X IPA 2'
        );

        $this->addStudentToClass(
            $studentOne['student'],
            $classOne
        );

        $this->addStudentToClass(
            $studentTwo['student'],
            $classTwo
        );

        $response = $this
            ->actingAs($studentOne['user'])
            ->get(
                $this->reportUrl(
                    'students',
                    [
                        'format' => 'excel',
                        'academic_year_id' => $academicYear->id,
                    ]
                )
            );

        $response->assertOk();
        $response->assertHeader(
            'Content-Disposition'
        );
    }

    public function test_student_cannot_expand_report_scope_to_another_student(): void
    {
        $studentOne = $this->createStudent('One');
        $studentTwo = $this->createStudent('Two');

        $academicYear = $this->createAcademicYear();

        $classOne = $this->createClass(
            $academicYear,
            'X IPA 1'
        );

        $classTwo = $this->createClass(
            $academicYear,
            'X IPA 2'
        );

        $this->addStudentToClass(
            $studentOne['student'],
            $classOne
        );

        $this->addStudentToClass(
            $studentTwo['student'],
            $classTwo
        );

        $response = $this
            ->actingAs($studentOne['user'])
            ->get(
                $this->reportUrl(
                    'students',
                    [
                        'format' => 'excel',
                        'academic_year_id' => $academicYear->id,
                        'student_id' => $studentTwo['student']->id,
                    ]
                )
            );

        $response->assertStatus(422);
    }

    public function test_teacher_can_export_report_for_own_teaching_class(): void
    {
        $teacher = $this->createTeacher('Own');

        $academicYear = $this->createAcademicYear();

        $class = $this->createClass(
            $academicYear,
            'X IPA Own'
        );

        $subject = $this->createSubject('Own');

        $this->createSchedule(
            $academicYear,
            $class,
            $subject,
            $teacher['guru']
        );

        $response = $this
            ->actingAs($teacher['user'])
            ->get(
                $this->reportUrl(
                    'schedules',
                    [
                        'format' => 'excel',
                        'academic_year_id' => $academicYear->id,
                        'class_id' => $class->id,
                    ]
                )
            );

        $response->assertOk();
        $response->assertHeader(
            'Content-Disposition'
        );
    }

    public function test_teacher_cannot_export_another_teachers_class(): void
    {
        $teacherOne = $this->createTeacher('One');
        $teacherTwo = $this->createTeacher('Two');

        $academicYear = $this->createAcademicYear();

        $classOne = $this->createClass(
            $academicYear,
            'X IPA One'
        );

        $classTwo = $this->createClass(
            $academicYear,
            'X IPA Two'
        );

        $subjectOne = $this->createSubject('One');
        $subjectTwo = $this->createSubject('Two');

        $this->createSchedule(
            $academicYear,
            $classOne,
            $subjectOne,
            $teacherOne['guru']
        );

        $this->createSchedule(
            $academicYear,
            $classTwo,
            $subjectTwo,
            $teacherTwo['guru']
        );

        $response = $this
            ->actingAs($teacherOne['user'])
            ->get(
                $this->reportUrl(
                    'schedules',
                    [
                        'format' => 'excel',
                        'academic_year_id' => $academicYear->id,
                        'class_id' => $classTwo->id,
                    ]
                )
            );

        $response->assertForbidden();
    }

    public function test_teacher_cannot_expand_attendance_report_to_another_class(): void
    {
        $teacherOne = $this->createTeacher('AttendanceOne');
        $teacherTwo = $this->createTeacher('AttendanceTwo');

        $academicYear = $this->createAcademicYear();

        $classOne = $this->createClass(
            $academicYear,
            'X IPA Attendance One'
        );

        $classTwo = $this->createClass(
            $academicYear,
            'X IPA Attendance Two'
        );

        $subjectOne = $this->createSubject('AttendanceOne');
        $subjectTwo = $this->createSubject('AttendanceTwo');

        $this->createSchedule(
            $academicYear,
            $classOne,
            $subjectOne,
            $teacherOne['guru']
        );

        $this->createSchedule(
            $academicYear,
            $classTwo,
            $subjectTwo,
            $teacherTwo['guru']
        );

        $response = $this
            ->actingAs($teacherOne['user'])
            ->get(
                $this->reportUrl(
                    'attendance',
                    [
                        'format' => 'excel',
                        'academic_year_id' => $academicYear->id,
                        'class_id' => $classTwo->id,
                    ]
                )
            );

        $response->assertForbidden();
    }

    public function test_invalid_report_type_is_rejected(): void
    {
        $tu = $this->createUser('TU');

        $response = $this
            ->actingAs($tu)
            ->get(
                $this->reportUrl(
                    'unknown-report',
                    [
                        'format' => 'excel',
                    ]
                )
            );

        $response->assertStatus(422);
    }

    public function test_invalid_format_is_rejected(): void
    {
        $tu = $this->createUser('TU');

        $response = $this
            ->actingAs($tu)
            ->get(
                $this->reportUrl(
                    'students',
                    [
                        'format' => 'csv',
                    ]
                )
            );

        $response->assertStatus(422);
    }

    public function test_report_rejects_filter_not_supported_by_report_type(): void
    {
        $tu = $this->createUser('TU');

        $academicYear = $this->createAcademicYear();

        $response = $this
            ->actingAs($tu)
            ->get(
                $this->reportUrl(
                    'teachers',
                    [
                        'format' => 'excel',
                        'academic_year_id' => $academicYear->id,
                        'class_id' => 999999,
                    ]
                )
            );

        $response->assertStatus(422);
    }

    public function test_report_respects_academic_year_filter(): void
    {
        $tu = $this->createUser('TU');

        $yearOne = $this->createAcademicYear('Ganjil');

        $yearTwo = TahunAkademik::create([
            'tahun_mulai' => 2027,
            'tahun_selesai' => 2028,
            'semester' => 'Genap',
            'aktif' => false,
        ]);

        $classOne = $this->createClass(
            $yearOne,
            'X IPA Year One'
        );

        $classTwo = $this->createClass(
            $yearTwo,
            'X IPA Year Two'
        );

        $response = $this
            ->actingAs($tu)
            ->get(
                $this->reportUrl(
                    'classes',
                    [
                        'format' => 'excel',
                        'academic_year_id' => $yearTwo->id,
                    ]
                )
            );

        $response->assertOk();
        $response->assertHeader(
            'Content-Disposition'
        );

        $this->assertNotSame(
            $classOne->tahun_akademik_id,
            $classTwo->tahun_akademik_id
        );
    }
}