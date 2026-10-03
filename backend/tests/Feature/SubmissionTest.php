<?php

namespace Tests\Feature;

use App\Models\AnggotaKelas;
use App\Models\Assignment;
use App\Models\Guru;
use App\Models\JadwalPelajaran;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\Role;
use App\Models\Siswa;
use App\Models\SubmissionTugas;
use App\Models\TahunAkademik;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubmissionTest extends TestCase
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

    private function createSubmission(
        Assignment $assignment,
        Siswa $student,
        array $attributes = []
    ): SubmissionTugas {
        return SubmissionTugas::create(array_merge([
            'assignment_id' => $assignment->id,
            'siswa_id' => $student->id,
            'jawaban' => 'Jawaban submission test.',
            'submitted_at' => now(),
            'status' => 'dikumpulkan',
            'catatan' => null,
        ], $attributes));
    }

    private function prepareStudentAssignment(): array
    {
        [$guruUser, $guru] = $this->createTeacher();
        [$studentUser, $student] = $this->createStudent();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $this->createMembership($student, $class);

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $guru
        );

        $assignment = $this->createAssignment(
            $schedule,
            $guruUser
        );

        return [
            $guruUser,
            $guru,
            $studentUser,
            $student,
            $academicYear,
            $class,
            $schedule,
            $assignment,
        ];
    }

    public function test_student_can_submit_text_answer(): void
    {
        [
            ,
            ,
            $studentUser,
            $student,
            ,
            ,
            ,
            $assignment
        ] = $this->prepareStudentAssignment();

        Sanctum::actingAs($studentUser);

        $response = $this->postJson(
            "/api/assignments/{$assignment->id}/submissions",
            [
                'jawaban' => 'Ini adalah jawaban siswa.',
            ]
        );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.assignment_id',
                $assignment->id
            )
            ->assertJsonPath(
                'data.siswa_id',
                $student->id
            )
            ->assertJsonPath(
                'data.jawaban',
                'Ini adalah jawaban siswa.'
            )
            ->assertJsonPath(
                'data.status',
                'dikumpulkan'
            );

        $this->assertDatabaseHas('submission_tugas', [
            'assignment_id' => $assignment->id,
            'siswa_id' => $student->id,
            'jawaban' => 'Ini adalah jawaban siswa.',
            'status' => 'dikumpulkan',
        ]);
    }

    public function test_student_can_submit_file_only(): void
    {
        Storage::fake('local');

        [
            ,
            ,
            $studentUser,
            $student,
            ,
            ,
            ,
            $assignment
        ] = $this->prepareStudentAssignment();

        Sanctum::actingAs($studentUser);

        $file = UploadedFile::fake()->create(
            'jawaban.pdf',
            100,
            'application/pdf'
        );

        $response = $this->post(
            "/api/assignments/{$assignment->id}/submissions",
            [
                'file' => $file,
            ],
            [
                'Accept' => 'application/json',
            ]
        );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.assignment_id',
                $assignment->id
            )
            ->assertJsonPath(
                'data.siswa_id',
                $student->id
            );

        $submission = SubmissionTugas::query()
            ->where('assignment_id', $assignment->id)
            ->where('siswa_id', $student->id)
            ->first();

        $this->assertNotNull($submission);
        $this->assertNotNull($submission->file_path);
        $this->assertSame(
            'jawaban.pdf',
            $submission->file_name
        );

        $this->assertTrue(
            Storage::disk('local')->exists(
                $submission->file_path
            )
        );
    }

    public function test_student_can_submit_text_and_file(): void
    {
        Storage::fake('local');

        [
            ,
            ,
            $studentUser,
            $student,
            ,
            ,
            ,
            $assignment
        ] = $this->prepareStudentAssignment();

        Sanctum::actingAs($studentUser);

        $file = UploadedFile::fake()->create(
            'jawaban.pdf',
            100,
            'application/pdf'
        );

        $response = $this->post(
            "/api/assignments/{$assignment->id}/submissions",
            [
                'jawaban' => 'Jawaban dengan lampiran.',
                'file' => $file,
            ],
            [
                'Accept' => 'application/json',
            ]
        );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.jawaban',
                'Jawaban dengan lampiran.'
            )
            ->assertJsonPath(
                'data.status',
                'dikumpulkan'
            );

        $submission = SubmissionTugas::query()
            ->where('assignment_id', $assignment->id)
            ->where('siswa_id', $student->id)
            ->first();

        $this->assertNotNull($submission);
        $this->assertNotNull($submission->file_path);
        $this->assertSame(
            'jawaban.pdf',
            $submission->file_name
        );
    }

    public function test_student_cannot_submit_empty_answer_and_file(): void
    {
        [
            ,
            ,
            $studentUser,
            ,
            ,
            ,
            ,
            $assignment
        ] = $this->prepareStudentAssignment();

        Sanctum::actingAs($studentUser);

        $this->postJson(
            "/api/assignments/{$assignment->id}/submissions",
            []
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'jawaban',
                'file',
            ]);
    }

    public function test_student_cannot_submit_assignment_for_another_class(): void
    {
        [
            ,
            $guru
        ] = $this->createTeacher();

        [
            $studentUser,
            $student
        ] = $this->createStudent();

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

        $this->postJson(
            "/api/assignments/{$assignment->id}/submissions",
            [
                'jawaban' => 'Tidak boleh.',
            ]
        )->assertForbidden();
    }

    public function test_student_can_view_own_submission(): void
    {
        [
            ,
            ,
            $studentUser,
            $student,
            ,
            ,
            ,
            $assignment
        ] = $this->prepareStudentAssignment();

        $submission = $this->createSubmission(
            $assignment,
            $student
        );

        Sanctum::actingAs($studentUser);

        $this->getJson(
            "/api/submissions/{$submission->id}"
        )
            ->assertOk()
            ->assertJsonPath(
                'data.id',
                $submission->id
            )
            ->assertJsonPath(
                'data.siswa_id',
                $student->id
            );
    }

    public function test_student_cannot_view_another_student_submission(): void
    {
        [
            ,
            $guru
        ] = $this->createTeacher();

        [
            $studentUser,
            $student
        ] = $this->createStudent();

        [
            ,
            $otherStudent
        ] = $this->createStudent();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $this->createMembership(
            $student,
            $class
        );

        $this->createMembership(
            $otherStudent,
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

        $otherSubmission = $this->createSubmission(
            $assignment,
            $otherStudent
        );

        Sanctum::actingAs($studentUser);

        $this->getJson(
            "/api/submissions/{$otherSubmission->id}"
        )->assertForbidden();
    }

    public function test_student_revision_updates_existing_submission(): void
    {
        [
            ,
            ,
            $studentUser,
            $student,
            ,
            ,
            ,
            $assignment
        ] = $this->prepareStudentAssignment();

        $submission = $this->createSubmission(
            $assignment,
            $student,
            [
                'jawaban' => 'Jawaban pertama.',
            ]
        );

        Sanctum::actingAs($studentUser);

        $response = $this->putJson(
            "/api/submissions/{$submission->id}",
            [
                'jawaban' => 'Jawaban revisi.',
            ]
        );

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.id',
                $submission->id
            )
            ->assertJsonPath(
                'data.jawaban',
                'Jawaban revisi.'
            );

        $this->assertDatabaseHas(
            'submission_tugas',
            [
                'id' => $submission->id,
                'jawaban' => 'Jawaban revisi.',
            ]
        );

        $this->assertDatabaseCount(
            'submission_tugas',
            1
        );
    }

    public function test_late_submission_is_marked_as_terlambat(): void
    {
        [
            ,
            ,
            $studentUser,
            $student,
            ,
            ,
            ,
            $assignment
        ] = $this->prepareStudentAssignment();

        $assignment->update([
            'deadline' => now()->subDay(),
        ]);

        Sanctum::actingAs($studentUser);

        $response = $this->postJson(
            "/api/assignments/{$assignment->id}/submissions",
            [
                'jawaban' => 'Jawaban terlambat.',
            ]
        );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.status',
                'terlambat'
            );

        $this->assertDatabaseHas(
            'submission_tugas',
            [
                'assignment_id' => $assignment->id,
                'siswa_id' => $student->id,
                'status' => 'terlambat',
            ]
        );
    }

    public function test_teacher_can_view_submissions_for_own_assignment(): void
    {
        [
            $guruUser,
            ,
            ,
            $student,
            ,
            ,
            ,
            $assignment
        ] = $this->prepareStudentAssignment();

        $submission = $this->createSubmission(
            $assignment,
            $student
        );

        Sanctum::actingAs($guruUser);

        $this->getJson(
            "/api/assignments/{$assignment->id}/submissions"
        )
            ->assertOk()
            ->assertJsonStructure([
                'data',
                'meta',
            ])
            ->assertJsonPath(
                'data.0.id',
                $submission->id
            );
    }

    public function test_teacher_cannot_view_submissions_for_another_teacher_assignment(): void
    {
        [
            $teacherUser
        ] = $this->createTeacher();

        [
            ,
            $otherGuru
        ] = $this->createTeacher();

        [
            $studentUser,
            $student
        ] = $this->createStudent();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $this->createMembership(
            $student,
            $class
        );

        $otherSchedule = $this->createSchedule(
            $academicYear,
            $class,
            $otherGuru
        );

        $assignment = $this->createAssignment(
            $otherSchedule,
            $otherGuru->user
        );

        $this->createSubmission(
            $assignment,
            $student
        );

        Sanctum::actingAs($teacherUser);

        $this->getJson(
            "/api/assignments/{$assignment->id}/submissions"
        )->assertForbidden();
    }

    public function test_teacher_can_view_submission_from_own_assignment(): void
    {
        [
            $guruUser,
            ,
            ,
            $student,
            ,
            ,
            ,
            $assignment
        ] = $this->prepareStudentAssignment();

        $submission = $this->createSubmission(
            $assignment,
            $student
        );

        Sanctum::actingAs($guruUser);

        $this->getJson(
            "/api/submissions/{$submission->id}"
        )
            ->assertOk()
            ->assertJsonPath(
                'data.id',
                $submission->id
            )
            ->assertJsonPath(
                'data.assignment_id',
                $assignment->id
            );
    }

    public function test_teacher_cannot_view_submission_from_another_teacher_assignment(): void
    {
        [
            $teacherUser
        ] = $this->createTeacher();

        [
            ,
            $otherGuru
        ] = $this->createTeacher();

        [
            ,
            $student
        ] = $this->createStudent();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $this->createMembership(
            $student,
            $class
        );

        $schedule = $this->createSchedule(
            $academicYear,
            $class,
            $otherGuru
        );

        $assignment = $this->createAssignment(
            $schedule,
            $otherGuru->user
        );

        $submission = $this->createSubmission(
            $assignment,
            $student
        );

        Sanctum::actingAs($teacherUser);

        $this->getJson(
            "/api/submissions/{$submission->id}"
        )->assertForbidden();
    }

    public function test_submission_rejects_unsupported_file_type(): void
    {
        Storage::fake('local');

        [
            ,
            ,
            $studentUser,
            ,
            ,
            ,
            ,
            $assignment
        ] = $this->prepareStudentAssignment();

        Sanctum::actingAs($studentUser);

        $file = UploadedFile::fake()->create(
            'malware.exe',
            100,
            'application/octet-stream'
        );

        $this->post(
            "/api/assignments/{$assignment->id}/submissions",
            [
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
}