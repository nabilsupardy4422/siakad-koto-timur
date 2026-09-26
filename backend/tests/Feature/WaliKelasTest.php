<?php

namespace Tests\Feature;

use App\Models\Guru;
use App\Models\Kelas;
use App\Models\Role;
use App\Models\TahunAkademik;
use App\Models\User;
use App\Models\WaliKelas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WaliKelasTest extends TestCase
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

    private function createAssignment(
        Guru $guru,
        Kelas $class,
        TahunAkademik $academicYear,
        string $startDate = '2026-07-01',
        ?string $endDate = null
    ): WaliKelas {
        return WaliKelas::create([
            'guru_id' => $guru->id,
            'kelas_id' => $class->id,
            'tahun_akademik_id' => $academicYear->id,
            'tanggal_mulai' => $startDate,
            'tanggal_selesai' => $endDate,
        ]);
    }

    public function test_guest_cannot_access_homeroom_assignments(): void
    {
        $this->getJson('/api/homeroom-assignments')
            ->assertUnauthorized();
    }

    public function test_tu_can_view_all_assignments(): void
    {
        $tuUser = $this->createUser('TU');

        [, $guruOne] = $this->createTeacher();
        [, $guruTwo] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();

        $classOne = $this->createClass(
            $academicYear,
            'X IPA 1'
        );

        $classTwo = $this->createClass(
            $academicYear,
            'X IPA 2'
        );

        $assignmentOne = $this->createAssignment(
            $guruOne,
            $classOne,
            $academicYear
        );

        $assignmentTwo = $this->createAssignment(
            $guruTwo,
            $classTwo,
            $academicYear
        );

        Sanctum::actingAs($tuUser);

        $response = $this->getJson(
            '/api/homeroom-assignments'
        );

        $response
            ->assertOk()
            ->assertJsonFragment([
                'id' => $assignmentOne->id,
            ])
            ->assertJsonFragment([
                'id' => $assignmentTwo->id,
            ]);
    }

    public function test_kepala_sekolah_can_view_all_assignments(): void
    {
        $kepalaUser = $this->createUser(
            'KEPALA_SEKOLAH'
        );

        [, $guruOne] = $this->createTeacher();
        [, $guruTwo] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();

        $classOne = $this->createClass(
            $academicYear,
            'X IPA 1'
        );

        $classTwo = $this->createClass(
            $academicYear,
            'X IPA 2'
        );

        $assignmentOne = $this->createAssignment(
            $guruOne,
            $classOne,
            $academicYear
        );

        $assignmentTwo = $this->createAssignment(
            $guruTwo,
            $classTwo,
            $academicYear
        );

        Sanctum::actingAs($kepalaUser);

        $response = $this->getJson(
            '/api/homeroom-assignments'
        );

        $response
            ->assertOk()
            ->assertJsonFragment([
                'id' => $assignmentOne->id,
            ])
            ->assertJsonFragment([
                'id' => $assignmentTwo->id,
            ]);
    }

    public function test_guru_can_view_own_assignments(): void
    {
        [$user, $guru] = $this->createTeacher();
        [, $otherGuru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();

        $classOne = $this->createClass(
            $academicYear,
            'X IPA 1'
        );

        $classTwo = $this->createClass(
            $academicYear,
            'X IPA 2'
        );

        $ownAssignment = $this->createAssignment(
            $guru,
            $classOne,
            $academicYear
        );

        $otherAssignment = $this->createAssignment(
            $otherGuru,
            $classTwo,
            $academicYear
        );

        Sanctum::actingAs($user);

        $response = $this->getJson(
            '/api/homeroom-assignments'
        );

        $response
            ->assertOk()
            ->assertJsonFragment([
                'id' => $ownAssignment->id,
            ])
            ->assertJsonMissing([
                'id' => $otherAssignment->id,
            ]);
    }

    public function test_guru_cannot_view_another_teacher_assignment(): void
    {
        [$user, $guru] = $this->createTeacher();
        [, $otherGuru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();

        $classOne = $this->createClass(
            $academicYear,
            'X IPA 1'
        );

        $classTwo = $this->createClass(
            $academicYear,
            'X IPA 2'
        );

        $ownAssignment = $this->createAssignment(
            $guru,
            $classOne,
            $academicYear
        );

        $otherAssignment = $this->createAssignment(
            $otherGuru,
            $classTwo,
            $academicYear
        );

        Sanctum::actingAs($user);

        $response = $this->getJson(
            '/api/homeroom-assignments'
        );

        $response
            ->assertOk()
            ->assertJsonFragment([
                'id' => $ownAssignment->id,
            ])
            ->assertJsonMissing([
                'id' => $otherAssignment->id,
            ]);
    }

    public function test_siswa_cannot_access_homeroom_assignments(): void
    {
        $user = $this->createUser('SISWA');

        Sanctum::actingAs($user);

        $this->getJson('/api/homeroom-assignments')
            ->assertForbidden();
    }

    public function test_tu_can_create_assignment(): void
    {
        $tuUser = $this->createUser('TU');

        [, $guru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();

        $class = $this->createClass(
            $academicYear
        );

        Sanctum::actingAs($tuUser);

        $response = $this->postJson(
            '/api/homeroom-assignments',
            [
                'guru_id' => $guru->id,
                'kelas_id' => $class->id,
                'tahun_akademik_id' => $academicYear->id,
                'tanggal_mulai' => '2026-07-01',
                'tanggal_selesai' => null,
            ]
        );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.guru_id',
                $guru->id
            )
            ->assertJsonPath(
                'data.kelas_id',
                $class->id
            )
            ->assertJsonPath(
                'data.tahun_akademik_id',
                $academicYear->id
            )
            ->assertJsonPath(
                'data.tanggal_mulai',
                '2026-07-01'
            )
            ->assertJsonPath(
                'data.tanggal_selesai',
                null
            );

        $assignment = WaliKelas::query()
            ->where('guru_id', $guru->id)
            ->where('kelas_id', $class->id)
            ->where(
                'tahun_akademik_id',
                $academicYear->id
            )
            ->first();

        $this->assertNotNull($assignment);

        $this->assertSame(
            '2026-07-01',
            $assignment->tanggal_mulai->toDateString()
        );

        $this->assertNull(
            $assignment->tanggal_selesai
        );
    }

    public function test_non_tu_cannot_create_assignment(): void
    {
        [$guruUser] = $this->createTeacher();

        [, $guru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();

        $class = $this->createClass(
            $academicYear
        );

        Sanctum::actingAs($guruUser);

        $this->postJson(
            '/api/homeroom-assignments',
            [
                'guru_id' => $guru->id,
                'kelas_id' => $class->id,
                'tahun_akademik_id' => $academicYear->id,
                'tanggal_mulai' => '2026-07-01',
                'tanggal_selesai' => null,
            ]
        )->assertForbidden();
    }

    public function test_assignment_requires_valid_fields(): void
    {
        $tuUser = $this->createUser('TU');

        Sanctum::actingAs($tuUser);

        $this->postJson(
            '/api/homeroom-assignments',
            []
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'guru_id',
                'kelas_id',
                'tahun_akademik_id',
                'tanggal_mulai',
            ]);
    }

    public function test_assignment_rejects_mismatched_academic_year(): void
    {
        $tuUser = $this->createUser('TU');

        [, $guru] = $this->createTeacher();

        $academicYearOne = $this->createAcademicYear(
            2026,
            2027,
            'GANJIL'
        );

        $academicYearTwo = $this->createAcademicYear(
            2027,
            2028,
            'GANJIL',
            false
        );

        $class = $this->createClass(
            $academicYearOne
        );

        Sanctum::actingAs($tuUser);

        $this->postJson(
            '/api/homeroom-assignments',
            [
                'guru_id' => $guru->id,
                'kelas_id' => $class->id,
                'tahun_akademik_id' => $academicYearTwo->id,
                'tanggal_mulai' => '2027-07-01',
                'tanggal_selesai' => null,
            ]
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'tahun_akademik_id',
            ]);
    }

    public function test_class_cannot_have_two_active_assignments(): void
    {
        $tuUser = $this->createUser('TU');

        [, $guruOne] = $this->createTeacher();
        [, $guruTwo] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();

        $class = $this->createClass(
            $academicYear
        );

        $this->createAssignment(
            $guruOne,
            $class,
            $academicYear
        );

        Sanctum::actingAs($tuUser);

        $this->postJson(
            '/api/homeroom-assignments',
            [
                'guru_id' => $guruTwo->id,
                'kelas_id' => $class->id,
                'tahun_akademik_id' => $academicYear->id,
                'tanggal_mulai' => '2026-08-01',
                'tanggal_selesai' => null,
            ]
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'kelas_id',
            ]);
    }

    public function test_tu_can_update_assignment(): void
    {
        $tuUser = $this->createUser('TU');

        [, $guru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();

        $class = $this->createClass(
            $academicYear
        );

        $assignment = $this->createAssignment(
            $guru,
            $class,
            $academicYear
        );

        Sanctum::actingAs($tuUser);

        $this->putJson(
            "/api/homeroom-assignments/{$assignment->id}",
            [
                'tanggal_mulai' => '2026-08-01',
            ]
        )
            ->assertOk()
            ->assertJsonPath(
                'data.id',
                $assignment->id
            )
            ->assertJsonPath(
                'data.tanggal_mulai',
                '2026-08-01'
            );

        $assignment->refresh();

        $this->assertSame(
            '2026-08-01',
            $assignment->tanggal_mulai->toDateString()
        );
    }

    public function test_non_tu_cannot_update_assignment(): void
    {
        [$guruUser, $guru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();

        $class = $this->createClass(
            $academicYear
        );

        $assignment = $this->createAssignment(
            $guru,
            $class,
            $academicYear
        );

        Sanctum::actingAs($guruUser);

        $this->putJson(
            "/api/homeroom-assignments/{$assignment->id}",
            [
                'tanggal_mulai' => '2026-08-01',
            ]
        )->assertForbidden();
    }

    public function test_tu_can_end_assignment_without_deleting_history(): void
    {
        $tuUser = $this->createUser('TU');

        [, $guru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();

        $class = $this->createClass(
            $academicYear
        );

        $assignment = $this->createAssignment(
            $guru,
            $class,
            $academicYear
        );

        Sanctum::actingAs($tuUser);

        $this->deleteJson(
            "/api/homeroom-assignments/{$assignment->id}"
        )->assertOk();

        $assignment->refresh();

        $this->assertSame(
            '2026-07-01',
            $assignment->tanggal_mulai->toDateString()
        );

        $this->assertNotNull(
            $assignment->tanggal_selesai
        );

        $this->assertDatabaseHas(
            'wali_kelas',
            [
                'id' => $assignment->id,
                'tanggal_selesai' => $assignment->tanggal_selesai->format('Y-m-d 00:00:00'),
            ]
        );
    }

    public function test_filters_are_supported(): void
    {
        $tuUser = $this->createUser('TU');

        [, $guruOne] = $this->createTeacher();
        [, $guruTwo] = $this->createTeacher();

        $academicYearOne = $this->createAcademicYear(
            2026,
            2027,
            'GANJIL'
        );

        $academicYearTwo = $this->createAcademicYear(
            2026,
            2027,
            'GENAP',
            false
        );

        $classOne = $this->createClass(
            $academicYearOne,
            'X IPA 1'
        );

        $classTwo = $this->createClass(
            $academicYearTwo,
            'XI IPA 1'
        );

        $assignmentOne = $this->createAssignment(
            $guruOne,
            $classOne,
            $academicYearOne
        );

        $assignmentTwo = $this->createAssignment(
            $guruTwo,
            $classTwo,
            $academicYearTwo,
            '2027-01-01'
        );

        Sanctum::actingAs($tuUser);

        $response = $this->getJson(
            '/api/homeroom-assignments?academic_year_id='
            . $academicYearOne->id
        );

        $response
            ->assertOk()
            ->assertJsonFragment([
                'id' => $assignmentOne->id,
            ])
            ->assertJsonMissing([
                'id' => $assignmentTwo->id,
            ]);
    }

    public function test_assignment_list_supports_pagination(): void
    {
        $tuUser = $this->createUser('TU');

        $academicYear = $this->createAcademicYear();

        for ($index = 1; $index <= 3; $index++) {
            [, $guru] = $this->createTeacher();

            $class = $this->createClass(
                $academicYear,
                'X IPA ' . $index
            );

            $this->createAssignment(
                $guru,
                $class,
                $academicYear
            );
        }

        Sanctum::actingAs($tuUser);

        $this->getJson(
            '/api/homeroom-assignments?per_page=2'
        )
            ->assertOk()
            ->assertJsonPath(
                'meta.per_page',
                2
            )
            ->assertJsonPath(
                'meta.total',
                3
            );
    }

    public function test_show_returns_404_when_assignment_not_found(): void
    {
        $tuUser = $this->createUser('TU');

        Sanctum::actingAs($tuUser);

        $this->getJson(
            '/api/homeroom-assignments/999999'
        )->assertNotFound();
    }

    public function test_update_returns_404_when_assignment_not_found(): void
    {
        $tuUser = $this->createUser('TU');

        Sanctum::actingAs($tuUser);

        $this->putJson(
            '/api/homeroom-assignments/999999',
            [
                'tanggal_mulai' => '2026-08-01',
            ]
        )->assertNotFound();
    }
}