<?php

namespace Tests\Feature;

use App\Models\AnggotaKelas;
use App\Models\WaliKelas;
use App\Models\Presensi;
use App\Models\Nilai;
use App\Models\KomponenNilai;
use App\Services\Reporting\ReportService;
use App\Services\Reporting\ReportDocument;
use App\Exports\ReportExport;
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
            'is_active' => true,
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
            'tanggal_mulai' => today()->subDay()->toDateString(),
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
            'is_active' => false,
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

    public function test_non_administrative_accounts_cannot_export_master_reports_in_either_format(): void
    {
        $teacher = $this->createTeacher('Denied');
        $student = $this->createStudent('Denied');
        foreach ([$teacher['user'], $student['user']] as $user) {
            foreach (['teachers', 'classes'] as $type) {
                foreach (['pdf', 'excel'] as $format) {
                    $this->actingAs($user)->getJson($this->reportUrl($type, ['format' => $format]))->assertForbidden();
                }
            }
        }
    }

    public function test_principal_teacher_report_does_not_expose_account_fields(): void
    {
        $principal = $this->createUser('KEPALA_SEKOLAH', 'Redacted');
        $teacher = $this->createTeacher('Redacted');
        $report = app(ReportService::class)->build('teachers', $principal, []);
        $this->assertSame(['NIP', 'Nama Guru'], $report['headings']);
        $this->assertSame(['nip', 'nama_guru'], array_keys($report['rows']->first()));
        $this->assertSame($teacher['guru']->nama_lengkap, $report['rows']->first()['nama_guru']);
    }

    private function scopedReportingFixture(): array
    {
        $year = $this->createAcademicYear();
        $owner = $this->createTeacher('Owner');
        $peer = $this->createTeacher('Peer');
        $class = $this->createClass($year, 'Binaan');
        $outside = $this->createClass($year, 'Di Luar');
        $subject = $this->createSubject('Own');
        $otherSubject = $this->createSubject('Peer');
        $own = $this->createSchedule($year, $class, $subject, $owner['guru']);
        $other = $this->createSchedule($year, $class, $otherSubject, $peer['guru']);
        $other->update(['jam_mulai' => '09:00', 'jam_selesai' => '10:00']);
        $unrelated = $this->createSchedule($year, $outside, $subject, $peer['guru']);
        WaliKelas::create(['guru_id' => $owner['guru']->id, 'kelas_id' => $class->id,
            'tahun_akademik_id' => $year->id, 'tanggal_mulai' => today()->subDay(), 'tanggal_selesai' => null]);
        $student = $this->createStudent('Inside');
        $outsider = $this->createStudent('Outside');
        $future = $this->createStudent('Future');
        $this->addStudentToClass($student['student'], $class);
        $this->addStudentToClass($outsider['student'], $outside);
        AnggotaKelas::create(['kelas_id' => $class->id, 'siswa_id' => $future['student']->id, 'tanggal_mulai' => today()->addDay()]);
        foreach ([[$own, $student, 80], [$other, $student, 90], [$unrelated, $outsider, 60], [$own, $future, 50]] as [$schedule, $person, $value]) {
            Presensi::create(['jadwal_pelajaran_id' => $schedule->id, 'siswa_id' => $person['student']->id,
                'tanggal' => today(), 'status' => 'hadir']);
            $component = KomponenNilai::firstOrCreate(['jadwal_pelajaran_id' => $schedule->id, 'nama' => 'Tugas'], ['bobot' => 25]);
            Nilai::create(['komponen_nilai_id' => $component->id, 'siswa_id' => $person['student']->id, 'nilai' => $value]);
        }
        return compact('year', 'owner', 'peer', 'class', 'outside', 'subject', 'otherSubject', 'own', 'other', 'unrelated', 'student', 'outsider', 'future');
    }

    public function test_teaching_and_homeroom_datasets_are_separate_even_with_same_class(): void
    {
        $f = $this->scopedReportingFixture();
        $service = app(ReportService::class);
        $user = $f['owner']['user'];
        foreach (['schedules', 'attendance', 'grades'] as $type) {
            $own = $service->build($type, $user, []); // defaults to teaching, never union
            $this->assertCount(1, $own['rows']);
            $this->assertSame($f['subject']->nama, $own['rows']->first()['mata_pelajaran']);
            $class = $service->build($type, $user, ['scope' => 'homeroom']);
            $this->assertCount(2, $class['rows']);
            $this->assertEqualsCanonicalizing([$f['subject']->nama, $f['otherSubject']->nama], $class['rows']->pluck('mata_pelajaran')->all());
        }
        $roster = $service->build('students', $user, ['scope' => 'homeroom']);
        $this->assertSame([$f['student']['student']->nama_lengkap], $roster['rows']->pluck('nama_siswa')->all());
        $recap = $service->build('semester-recap', $user, ['scope' => 'homeroom']);
        $this->assertCount(1, $recap['rows']);
        $this->assertSame(2, $recap['rows']->first()['hadir']);
        $this->assertEquals(85, $recap['rows']->first()['rata_rata_nilai']);
    }

    public function test_scope_and_filter_tampering_is_rejected_for_both_export_formats(): void
    {
        $f = $this->scopedReportingFixture();
        $user = $f['owner']['user'];
        foreach (['pdf', 'excel'] as $format) {
            foreach ([
                ['attendance', ['scope' => 'teaching', 'subject_id' => $f['otherSubject']->id]],
                ['grades', ['scope' => 'teaching', 'schedule_id' => $f['other']->id]],
                ['schedules', ['scope' => 'homeroom', 'class_id' => $f['outside']->id]],
                ['students', ['scope' => 'school']],
                ['semester-recap', ['scope' => 'teaching']],
            ] as [$type, $filters]) {
                $this->actingAs($user)->getJson($this->reportUrl($type, ['format' => $format] + $filters))->assertForbidden();
            }
        }
        $this->actingAs($f['peer']['user'])->getJson($this->reportUrl('attendance', ['format' => 'excel', 'scope' => 'homeroom']))->assertForbidden();
    }

    public function test_rollover_revokes_homeroom_and_old_teaching_reports(): void
    {
        $f = $this->scopedReportingFixture();
        $f['year']->update(['is_active' => false]);
        $new = TahunAkademik::create(['tahun_mulai' => 2027, 'tahun_selesai' => 2028, 'semester' => 'Genap', 'is_active' => true]);
        $user = $f['owner']['user'];
        $this->actingAs($user)->getJson($this->reportUrl('grades', ['format' => 'excel', 'scope' => 'homeroom']))->assertForbidden();
        $this->getJson($this->reportUrl('schedules', ['format' => 'pdf', 'academic_year_id' => $f['year']->id]))->assertForbidden();
        $this->assertCount(0, app(ReportService::class)->build('schedules', $user, [])['rows']);
        $newClass = $this->createClass($new, 'Binaan Baru');
        WaliKelas::create(['guru_id' => $f['owner']['guru']->id, 'kelas_id' => $newClass->id, 'tahun_akademik_id' => $new->id,
            'tanggal_mulai' => today(), 'tanggal_selesai' => null]);
        $this->assertCount(0, app(ReportService::class)->build('students', $user, ['scope' => 'homeroom'])['rows']);
        $this->getJson($this->reportUrl('students', ['format' => 'excel', 'scope' => 'homeroom', 'class_id' => $f['class']->id]))->assertForbidden();
    }

    public function test_student_reports_contain_only_own_rows_and_reject_other_student_id(): void
    {
        $f = $this->scopedReportingFixture();
        $user = $f['student']['user'];
        foreach (['attendance', 'grades'] as $type) {
            $report = app(ReportService::class)->build($type, $user, ['scope' => 'own']);
            $this->assertCount(2, $report['rows']);
            $this->assertSame([$f['student']['student']->nama_lengkap], $report['rows']->pluck('siswa')->unique()->values()->all());
            $this->actingAs($user)->getJson($this->reportUrl($type, ['format' => 'excel', 'student_id' => $f['outsider']['student']->id]))->assertForbidden();
        }
        $this->actingAs($user)->getJson($this->reportUrl('schedules', ['format' => 'excel', 'scope' => 'homeroom']))->assertForbidden();
    }

    public function test_ambiguous_active_period_fails_closed_for_teacher(): void
    {
        $f = $this->scopedReportingFixture();
        TahunAkademik::create(['tahun_mulai' => 2027, 'tahun_selesai' => 2028, 'semester' => 'Genap', 'is_active' => true]);
        $this->actingAs($f['owner']['user'])->getJson($this->reportUrl('schedules', ['format' => 'excel', 'scope' => 'teaching']))->assertForbidden();
        $this->getJson($this->reportUrl('schedules', ['format' => 'excel', 'scope' => 'homeroom']))->assertForbidden();
    }

    public function test_excel_export_contains_only_the_selected_scope(): void
    {
        $f = $this->scopedReportingFixture();
        foreach (['teaching' => 1, 'homeroom' => 2] as $scope => $count) {
            $response = $this->actingAs($f['owner']['user'])->get($this->reportUrl('grades', [
                'format' => 'excel', 'scope' => $scope,
            ]));
            $response->assertOk();
            $workbook = \PhpOffice\PhpSpreadsheet\IOFactory::load($response->baseResponse->getFile()->getPathname());
            $sheet = $workbook->getActiveSheet();
            $rows = $sheet->rangeToArray('A'.ReportExport::HEADER_ROW.':'.$sheet->getHighestDataColumn().$sheet->getHighestDataRow());
            $workbook->disconnectWorksheets();
            $this->assertCount($count + 1, $rows); // including headings
            $headings = array_shift($rows);
            $this->assertContains('Mata Pelajaran', $headings);
            $this->assertContains('Kelas', $headings);
            $subjectColumn = array_search('Mata Pelajaran', $headings, true);
            $classColumn = array_search('Kelas', $headings, true);
            $this->assertContains($f['subject']->nama, array_column($rows, $subjectColumn));
            $this->assertNotContains($f['outside']->nama, array_column($rows, $classColumn));
            if ($scope === 'teaching') $this->assertNotContains($f['otherSubject']->nama, array_column($rows, $subjectColumn));
            else $this->assertContains($f['otherSubject']->nama, array_column($rows, $subjectColumn));
        }
    }

    public function test_future_or_closed_homeroom_assignment_cannot_export(): void
    {
        $f = $this->scopedReportingFixture();
        $assignment = WaliKelas::query()->where('guru_id', $f['owner']['guru']->id)->firstOrFail();
        $assignment->update(['tanggal_mulai' => today()->addDay()]);
        $url = $this->reportUrl('grades', ['format' => 'excel', 'scope' => 'homeroom']);
        $this->actingAs($f['owner']['user'])->getJson($url)->assertForbidden();
        $assignment->update(['tanggal_mulai' => today()->subDays(2), 'tanggal_selesai' => today()->subDay()]);
        $this->getJson($url)->assertForbidden();
        $this->assertCount(1, app(ReportService::class)->build('grades', $f['owner']['user'], [])['rows']);
    }

    public function test_grade_details_include_component_weight_period_and_numeric_zero(): void
    {
        $f = $this->scopedReportingFixture();
        $grade = Nilai::query()->where('siswa_id', $f['student']['student']->id)
            ->whereHas('komponenNilai', fn ($q) => $q->where('jadwal_pelajaran_id', $f['own']->id))->firstOrFail();
        $grade->update(['nilai' => 0]);
        $report = app(ReportService::class)->build('grades', $f['owner']['user'], ['scope' => 'teaching']);
        $this->assertCount(1, $report['rows']);
        $row = $report['rows']->first();
        $this->assertSame('Tugas', $row['komponen']);
        $this->assertSame(25.0, $row['bobot']);
        $this->assertSame(0.0, $row['nilai']);
        $this->assertSame('2026/2027', $row['tahun_akademik']);
        $this->assertSame($f['year']->semester, $row['semester']);
        $this->assertSame($f['student']['student']->nisn, $row['nisn']);
        $this->assertArrayNotHasKey('id', $row);
        $this->assertCount(count($report['headings']), $row);
    }

    public function test_recap_counts_meetings_not_days_and_preserves_zero_and_missing(): void
    {
        $f = $this->scopedReportingFixture();
        $member = $f['student']['student'];
        $ungraded = $this->createStudent('Ungraded');
        $this->addStudentToClass($ungraded['student'], $f['class']);
        $zero = $this->createStudent('Zero');
        $this->addStudentToClass($zero['student'], $f['class']);
        $component = KomponenNilai::query()->where('jadwal_pelajaran_id', $f['own']->id)->firstOrFail();
        Nilai::create(['komponen_nilai_id' => $component->id, 'siswa_id' => $zero['student']->id, 'nilai' => 0]);
        Presensi::query()->where('siswa_id', $member->id)->where('jadwal_pelajaran_id', $f['other']->id)->update(['status' => 'izin']);
        $rows = app(ReportService::class)->build('semester-recap', $f['owner']['user'], ['scope' => 'homeroom'])['rows']->keyBy('nis');
        $this->assertCount(3, $rows);
        $this->assertSame(1, $rows[$member->nis]['hadir']);
        $this->assertSame(1, $rows[$member->nis]['izin']);
        $this->assertSame(2, $rows[$member->nis]['total_presensi']);
        $this->assertEquals(50, $rows[$member->nis]['persentase_hadir']);
        $this->assertEquals(85, $rows[$member->nis]['rata_rata_nilai']);
        $this->assertSame(2, $rows[$member->nis]['jumlah_nilai']);
        $this->assertEquals(0, $rows[$zero['student']->nis]['rata_rata_nilai']);
        $this->assertSame(1, $rows[$zero['student']->nis]['jumlah_nilai']);
        $this->assertNull($rows[$ungraded['student']->nis]['rata_rata_nilai']);
        $this->assertNull($rows[$ungraded['student']->nis]['persentase_hadir']);
        $this->assertSame(0, $rows[$ungraded['student']->nis]['jumlah_nilai']);
    }

    public function test_school_recap_keeps_same_student_separate_by_class_and_period(): void
    {
        $f = $this->scopedReportingFixture();
        $tu = $this->createUser('TU', 'MultiRecap');
        $student = $f['student']['student'];
        $old = TahunAkademik::create(['tahun_mulai' => 2025, 'tahun_selesai' => 2026, 'semester' => 'Genap', 'is_active' => false]);
        $oldClass = $this->createClass($old, $f['class']->nama);
        AnggotaKelas::create(['kelas_id' => $oldClass->id, 'siswa_id' => $student->id,
            'tanggal_mulai' => today()->subDays(20), 'tanggal_selesai' => today()->subDays(5)]);
        $schedule = $this->createSchedule($old, $oldClass, $f['subject'], $f['owner']['guru']);
        $component = KomponenNilai::create(['jadwal_pelajaran_id' => $schedule->id, 'nama' => 'UAS', 'bobot' => 40]);
        Nilai::create(['komponen_nilai_id' => $component->id, 'siswa_id' => $student->id, 'nilai' => 40]);
        Presensi::create(['jadwal_pelajaran_id' => $schedule->id, 'siswa_id' => $student->id,
            'tanggal' => today()->subDays(5), 'status' => 'sakit']);
        $service = app(ReportService::class);
        $rows = $service->build('semester-recap', $tu, ['student_id' => $student->id])['rows']->keyBy('tahun_akademik');
        $this->assertCount(2, $rows);
        $this->assertEquals(40, $rows['2025/2026']['rata_rata_nilai']);
        $this->assertSame(1, $rows['2025/2026']['sakit']);
        $this->assertEquals(85, $rows['2026/2027']['rata_rata_nilai']);
        $this->assertSame(2, $rows['2026/2027']['hadir']);
        $filtered = $service->build('semester-recap', $tu, ['student_id' => $student->id, 'semester' => 'Genap']);
        $this->assertCount(1, $filtered['rows']);
        $this->assertSame('2025/2026', $filtered['rows']->first()['tahun_akademik']);
    }

    public function test_roster_and_details_use_matching_membership_and_meeting_dates(): void
    {
        $f = $this->scopedReportingFixture();
        $tu = $this->createUser('TU', 'Membership');
        $student = $f['student']['student'];
        // Same student has a historical membership in another class; it must not label the present roster.
        AnggotaKelas::create(['kelas_id' => $f['outside']->id, 'siswa_id' => $student->id,
            'tanggal_mulai' => today()->subDays(10), 'tanggal_selesai' => today()->subDays(2)]);
        Presensi::create(['jadwal_pelajaran_id' => $f['own']->id, 'siswa_id' => $student->id,
            'tanggal' => today()->subDays(2), 'status' => 'alpa']);
        $service = app(ReportService::class);
        $report = $service->build('students', $f['owner']['user'], ['scope' => 'teaching', 'schedule_id' => $f['own']->id]);
        $this->assertCount(1, $report['rows']);
        $this->assertSame($f['class']->nama, $report['rows']->first()['kelas']);
        $this->assertSame('Aktif', $report['rows']->first()['status_keanggotaan']);
        $details = $service->build('attendance', $tu, ['class_id' => $f['class']->id]);
        $this->assertCount(2, $details['rows']); // before membership and future member excluded
        $this->assertNotContains('alpa', $details['rows']->pluck('status')->all());
        $this->assertSame([$f['year']->semester], $details['rows']->pluck('semester')->unique()->values()->all());
        $this->assertCount(2, $service->build('grades', $tu, ['class_id' => $f['class']->id])['rows']);
    }

    public function test_excel_preserves_identifiers_literal_text_zero_and_empty_cells(): void
    {
        $f = $this->scopedReportingFixture();
        $student = $f['student']['student'];
        $student->update(['nis' => '000123', 'nisn' => '0012345678']);
        $grade = Nilai::query()->where('siswa_id', $student->id)
            ->whereHas('komponenNilai', fn ($q) => $q->where('jadwal_pelajaran_id', $f['own']->id))->firstOrFail();
        $grade->update(['nilai' => 0, 'catatan' => '=1+1']);
        $response = $this->actingAs($f['owner']['user'])->get($this->reportUrl('grades', ['format' => 'excel', 'scope' => 'teaching']));
        $response->assertOk();
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($response->baseResponse->getFile()->getPathname());
        $sheet = $book->getActiveSheet();
        $headings = $sheet->rangeToArray('A'.ReportExport::HEADER_ROW.':P'.ReportExport::HEADER_ROW)[0];
        $cell = fn ($heading) => $sheet->getCell([array_search($heading, $headings, true) + 1, ReportExport::DATA_ROW]);
        $this->assertSame('000123', $cell('NIS')->getValue());
        $this->assertSame('0012345678', $cell('NISN')->getValue());
        $this->assertSame('s', $cell('NIS')->getDataType());
        $this->assertSame('=1+1', $cell('Catatan')->getValue());
        $this->assertSame('s', $cell('Catatan')->getDataType());
        $this->assertSame('n', $cell('Nilai Tercatat')->getDataType());
        $this->assertEquals(0, $cell('Nilai Tercatat')->getValue());
        $book->disconnectWorksheets();
        // Verify missing values independently of grade rows, which are only recorded values.
        $empty = $this->createStudent('EmptyExcel');
        $this->addStudentToClass($empty['student'], $f['class']);
        $response = $this->get($this->reportUrl('semester-recap', ['format' => 'excel', 'scope' => 'homeroom', 'student_id' => $empty['student']->id]));
        $response->assertOk();
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($response->baseResponse->getFile()->getPathname());
        $sheet = $book->getActiveSheet();
        $this->assertEquals(0, $sheet->getCell('H'.ReportExport::DATA_ROW)->getValue());
        $this->assertNull($sheet->getCell('O'.ReportExport::DATA_ROW)->getValue());
        $book->disconnectWorksheets();
    }

    public function test_schedule_is_in_weekday_order_and_identifies_level_and_period(): void
    {
        $f = $this->scopedReportingFixture();
        $f['own']->update(['hari' => 'SENIN']);
        $f['other']->update(['hari' => 'KAMIS']);
        $report = app(ReportService::class)->build('schedules', $f['owner']['user'], ['scope' => 'homeroom']);
        $this->assertSame(['SENIN', 'KAMIS'], $report['rows']->pluck('hari')->map(fn ($day) => strtoupper($day))->all());
        $this->assertEquals(10, $report['rows']->first()['tingkat']);
        $this->assertSame('2026/2027', $report['rows']->first()['tahun_akademik']);
    }

    public function test_document_metadata_uses_effective_scope_and_excel_layout_is_structured(): void
    {
        $f = $this->scopedReportingFixture();
        foreach (['teaching' => 'Penugasan mengajar sendiri', 'homeroom' => 'Kelas binaan Wali Kelas'] as $scope => $label) {
            $response = $this->actingAs($f['owner']['user'])->get($this->reportUrl('grades', [
                'format' => 'excel', 'scope' => $scope,
            ]));
            $response->assertOk();
            $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($response->baseResponse->getFile()->getPathname());
            $sheet = $book->getActiveSheet();
            $this->assertSame('Penilaian', $sheet->getTitle());
            $this->assertSame('SMA NEGERI 1 V KOTO TIMUR', $sheet->getCell('A1')->getValue());
            $this->assertSame('Konteks: '.$label, $sheet->getCell('A3')->getValue());
            $this->assertStringContainsString('2026/2027', $sheet->getCell('A4')->getValue());
            if ($scope === 'homeroom') $this->assertStringContainsString($f['class']->nama, $sheet->getCell('A5')->getValue());
            $this->assertSame('A'.ReportExport::DATA_ROW, $sheet->getFreezePane());
            $this->assertSame('A9:P'.($scope === 'teaching' ? '10' : '11'), $sheet->getAutoFilter()->getRange());
            $this->assertSame('NIS', $sheet->getCell('A9')->getValue());
            $this->assertSame('285F98', $sheet->getStyle('A9')->getFill()->getStartColor()->getRGB());
            $this->assertSame('@', $sheet->getStyle('A10')->getNumberFormat()->getFormatCode());
            $this->assertFalse($sheet->getShowGridlines());
            $book->disconnectWorksheets();
        }
    }

    public function test_pdf_projection_keeps_context_and_escapes_literal_text(): void
    {
        $f = $this->scopedReportingFixture();
        $grade = Nilai::query()->where('siswa_id', $f['student']['student']->id)
            ->whereHas('komponenNilai', fn ($q) => $q->where('jadwal_pelajaran_id', $f['own']->id))->firstOrFail();
        $grade->update(['nilai' => 0, 'catatan' => '<script>alert(1)</script>']);
        $report = app(ReportService::class)->build('grades', $f['owner']['user'], ['scope' => 'teaching']);
        $sections = ReportDocument::sections('grades', $report);
        $this->assertCount(1, $sections);
        $this->assertStringContainsString($f['class']->nama, $sections[0]['context']);
        $this->assertStringContainsString('2026/2027', $sections[0]['context']);
        $this->assertCount(1, $sections[0]['projected']);
        $this->assertSame(0.0, $sections[0]['projected']->first()[5]);
        $html = view('reports.pdf', ['meta' => ReportDocument::metadata('grades', $f['owner']['user'], $report),
            'sections' => $sections, 'logo' => ReportDocument::logo()])->render();
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString($f['otherSubject']->nama, $html);
        $response = $this->actingAs($f['owner']['user'])->get($this->reportUrl('grades', ['format' => 'pdf', 'scope' => 'teaching']));
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $tu = $this->createUser('TU', 'PDFGroups');
        $all = app(ReportService::class)->build('grades', $tu, []);
        $sections = ReportDocument::sections('grades', $all);
        $this->assertCount(2, $sections);
        $this->assertSame($all['rows']->count(), collect($sections)->sum(fn ($section) => $section['projected']->count()));
    }

    public function test_empty_report_has_metadata_and_no_fake_excel_rows(): void
    {
        $this->createAcademicYear();
        $teacher = $this->createTeacher('NoSchedules');
        $response = $this->actingAs($teacher['user'])->get($this->reportUrl('grades', ['format' => 'excel']));
        $response->assertOk();
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($response->baseResponse->getFile()->getPathname());
        $sheet = $book->getActiveSheet();
        $this->assertSame(ReportExport::HEADER_ROW, $sheet->getHighestDataRow());
        $this->assertStringContainsString('Tidak ada data', $sheet->getCell('A7')->getValue());
        $this->assertStringContainsString('Jumlah baris: 0', $sheet->getCell('A6')->getValue());
        $book->disconnectWorksheets();
        $report = app(ReportService::class)->build('grades', $teacher['user'], []);
        $sections = ReportDocument::sections('grades', $report);
        $this->assertCount(1, $sections);
        $this->assertCount(0, $sections[0]['projected']);
        $this->actingAs($teacher['user'])->get($this->reportUrl('grades', ['format' => 'pdf']))->assertOk();
    }
    public function test_workspace_context_is_teacher_only_and_separates_assignments(): void
    {
        $f = $this->scopedReportingFixture();
        $this->getJson('/api/reports/context')->assertUnauthorized();
        $this->actingAs($f['student']['user'])->getJson('/api/reports/context')->assertForbidden();
        $this->actingAs($f['owner']['user'])->getJson('/api/reports/context')->assertOk()
            ->assertJsonPath('data.year.id', $f['year']->id)
            ->assertJsonCount(1, 'data.teaching.schedules')->assertJsonCount(2, 'data.homeroom.schedules')
            ->assertJsonPath('data.teaching.schedules.0.id', $f['own']->id)
            ->assertJsonCount(1, 'data.homeroom.classes');
        $this->actingAs($f['peer']['user'])->getJson('/api/reports/context')->assertOk()->assertJsonCount(0, 'data.homeroom.classes');
    }

    public function test_preview_uses_authorized_dataset_and_rejects_scope_tampering(): void
    {
        $f = $this->scopedReportingFixture();
        $this->actingAs($f['owner']['user']);
        $url = '/api/reports/grades/preview?scope=';
        $this->getJson($url.'teaching')->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.rows.0.mata_pelajaran', $f['subject']->nama);
        $this->getJson($url.'homeroom')->assertOk()->assertJsonPath('data.total', 2);
        $this->getJson($url.'teaching&schedule_id='.$f['other']->id)->assertForbidden();
        $this->getJson('/api/reports/teachers/preview')->assertForbidden();
        $this->getJson('/api/reports/semester-recap/preview?scope=teaching')->assertForbidden();
        $this->getJson($url.'teaching&format=pdf')->assertUnprocessable();
        $this->actingAs($f['student']['user'])->getJson('/api/reports/grades/preview')->assertForbidden();
    }

    public function test_preview_hash_detects_changed_data_and_export_reauthorizes(): void
    {
        $f = $this->scopedReportingFixture();
        $this->actingAs($f['owner']['user']);
        $filters = ['scope' => 'homeroom', 'academic_year_id' => $f['year']->id, 'class_id' => $f['class']->id];
        $hash = $this->getJson('/api/reports/grades/preview?'.http_build_query($filters))->assertOk()->json('data.preview_hash');
        $url = $this->reportUrl('grades', $filters + ['format' => 'excel', 'preview_hash' => $hash]);
        $this->get($url)->assertOk();
        Nilai::query()->where('siswa_id', $f['student']['student']->id)->update(['nilai' => 1]);
        $this->getJson($url)->assertStatus(409);
        $f['year']->update(['is_active' => false]);
        $this->getJson($url)->assertForbidden();
        $this->getJson('/api/reports/context')->assertOk()->assertJsonPath('data.year', null);
    }

    public function test_preview_pagination_preserves_zero_and_empty_and_rejects_invalid_page(): void
    {
        $f = $this->scopedReportingFixture();
        $this->actingAs($f['owner']['user']);
        for ($i = 0; $i < 9; $i++) {
            $component = KomponenNilai::create(['jadwal_pelajaran_id' => $f['own']->id, 'nama' => 'Tambahan '.$i, 'bobot' => 1]);
            Nilai::create(['komponen_nilai_id' => $component->id, 'siswa_id' => $f['student']['student']->id, 'nilai' => 0]);
        }
        $url = '/api/reports/grades/preview?scope=teaching';
        $a = $this->getJson($url)->assertOk()->assertJsonPath('data.total',10)->assertJsonCount(8,'data.rows');
        $this->getJson($url.'&page=2')->assertOk()->assertJsonCount(2,'data.rows')->assertJsonPath('data.rows.0.nilai',0)
            ->assertJsonPath('data.preview_hash',$a->json('data.preview_hash'));
        $this->getJson($url.'&page=3')->assertUnprocessable();
        $this->getJson('/api/reports/attendance/preview?scope=teaching&date_from=1900-01-01&date_to=1900-01-02')->assertOk()->assertJsonPath('data.total',0)->assertJsonCount(0,'data.rows');
    }

    public function test_multipage_pdf_and_long_note_keep_authorized_dataset(): void
    {
        $f = $this->scopedReportingFixture();
        $user = $f['owner']['user'];
        $longNote = str_repeat('Catatan evaluasi pembelajaran. ', 15).'AKHIR_CATATAN_06';
        for ($i = 0; $i < 40; $i++) {
            $component = KomponenNilai::create([
                'jadwal_pelajaran_id' => $f['own']->id,
                'nama' => 'Uji Dokumen '.$i,
                'bobot' => 1,
            ]);
            Nilai::create([
                'komponen_nilai_id' => $component->id,
                'siswa_id' => $f['student']['student']->id,
                'nilai' => 0,
                'catatan' => $i === 0 ? $longNote : null,
            ]);
        }
        $filters = ['scope' => 'teaching', 'class_id' => $f['class']->id];
        $preview = $this->actingAs($user)->getJson('/api/reports/grades/preview?'.http_build_query($filters))
            ->assertOk()->assertJsonPath('data.total', 41)->assertJsonCount(8, 'data.rows');
        $hash = $preview->json('data.preview_hash');
        $report = app(ReportService::class)->build('grades', $user, $filters);
        $sections = ReportDocument::sections('grades', $report);
        $this->assertCount(1, $sections);
        $this->assertCount(41, $sections[0]['projected']);
        $view = ['meta' => ReportDocument::metadata('grades', $user, $report),
            'sections' => $sections, 'logo' => ReportDocument::logo()];
        $html = view('reports.pdf', $view)->render();
        $this->assertStringContainsString('Lihat catatan lengkap baris', $html);
        $this->assertStringContainsString($longNote, $html);
        $this->assertStringNotContainsString($f['otherSubject']->nama, $html);
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('reports.pdf', $view)->setPaper('a4', 'landscape');
        $pdf->render();
        $this->assertGreaterThan(1, $pdf->getDomPDF()->getCanvas()->get_page_count());
        $response = $this->get($this->reportUrl('grades', $filters + ['format' => 'pdf', 'preview_hash' => $hash]));
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        // The workbook must contain all authorized rows, not only the preview page.
        $response = $this->get($this->reportUrl('grades', $filters + ['format' => 'excel', 'preview_hash' => $hash]));
        $response->assertOk();
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($response->baseResponse->getFile()->getPathname());
        $sheet = $book->getActiveSheet();
        $rows = $sheet->rangeToArray('A'.ReportExport::DATA_ROW.':P'.(ReportExport::DATA_ROW + 40));
        $this->assertSame(ReportExport::DATA_ROW + 40, $sheet->getHighestDataRow());
        $this->assertCount(41, $rows);
        $this->assertSame([$f['subject']->nama], array_values(array_unique(array_column($rows, 5))));
        $this->assertContains($longNote, array_column($rows, 13));
        $book->disconnectWorksheets();
    }

    public function test_both_formats_reject_stale_preview_and_revoked_homeroom_in_same_period(): void
    {
        $f = $this->scopedReportingFixture();
        $this->actingAs($f['owner']['user']);
        $filters = ['scope' => 'homeroom', 'academic_year_id' => $f['year']->id, 'class_id' => $f['class']->id];
        $previewUrl = '/api/reports/grades/preview?'.http_build_query($filters);
        $hash = $this->getJson($previewUrl)->assertOk()->assertJsonPath('data.total', 2)->json('data.preview_hash');
        foreach (['pdf', 'excel'] as $format) {
            $this->get($this->reportUrl('grades', $filters + ['format' => $format, 'preview_hash' => $hash]))->assertOk();
        }
        Nilai::query()->where('siswa_id', $f['student']['student']->id)->update(['nilai' => 7]);
        foreach (['pdf', 'excel'] as $format) {
            $this->getJson($this->reportUrl('grades', $filters + ['format' => $format, 'preview_hash' => $hash]))->assertStatus(409);
        }
        $freshHash = $this->getJson($previewUrl)->assertOk()->json('data.preview_hash');
        $this->assertNotSame($hash, $freshHash);
        WaliKelas::query()->where('guru_id', $f['owner']['guru']->id)
            ->where('kelas_id', $f['class']->id)->where('tahun_akademik_id', $f['year']->id)
            ->update(['tanggal_selesai' => today()->subDay()->toDateString()]);
        $this->getJson($previewUrl)->assertForbidden();
        foreach (['pdf', 'excel'] as $format) {
            $this->getJson($this->reportUrl('grades', $filters + ['format' => $format, 'preview_hash' => $freshHash]))->assertForbidden();
        }
        $this->getJson('/api/reports/context')->assertOk()->assertJsonCount(0, 'data.homeroom.classes');
        $this->getJson('/api/reports/grades/preview?scope=teaching')->assertOk()->assertJsonPath('data.total', 1);
    }
}
