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
    public function test_v60_teacher_cannot_hold_two_open_assignments_in_one_period(): void
    {
        Sanctum::actingAs($this->createUser('TU'));
        [, $guru] = $this->createTeacher();
        $year = $this->createAcademicYear();
        $a = $this->createClass($year, 'E1');
        $b = $this->createClass($year, 'E2');
        $this->createAssignment($guru, $a, $year, '2026-07-01');
        $this->postJson('/api/homeroom-assignments', ['guru_id'=>$guru->id,'kelas_id'=>$b->id,'tahun_akademik_id'=>$year->id,'tanggal_mulai'=>'2026-08-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('guru_id');
        $this->assertDatabaseCount('wali_kelas', 1);
    }

    public function test_v60_replacement_keeps_history_and_rejects_replay(): void
    {
        Sanctum::actingAs($this->createUser('TU'));
        [, $oldGuru] = $this->createTeacher();
        [, $newGuru] = $this->createTeacher();
        $year = $this->createAcademicYear();
        $class = $this->createClass($year);
        $today = today('Asia/Jakarta');
        $old = $this->createAssignment($oldGuru, $class, $year, $today->copy()->subDay()->toDateString());
        $version = $this->getJson("/api/homeroom-assignments/{$old->id}")->assertOk()->json('data.version');
        $payload = ['guru_id'=>$newGuru->id,'version'=>$version];
        $this->postJson("/api/homeroom-assignments/{$old->id}/replace", $payload)->assertCreated()->assertJsonPath('data.guru_id',$newGuru->id);
        $this->assertSame($oldGuru->id, $old->refresh()->guru_id);
        $this->assertSame($today->toDateString(), $old->tanggal_selesai->toDateString());
        $this->postJson("/api/homeroom-assignments/{$old->id}/replace", $payload)->assertConflict();
        $this->assertDatabaseCount('wali_kelas',2);
        $this->putJson("/api/homeroom-assignments/{$old->id}",['tanggal_selesai'=>null])->assertConflict();
    }

    public function test_v60_replacement_conflict_does_not_close_old_assignment(): void
    {
        Sanctum::actingAs($this->createUser('TU'));
        [, $oldGuru] = $this->createTeacher();
        [, $newGuru] = $this->createTeacher();
        $year = $this->createAcademicYear();
        $a = $this->createClass($year,'E1'); $b = $this->createClass($year,'E2');
        $start = today('Asia/Jakarta')->subDay()->toDateString();
        $old = $this->createAssignment($oldGuru,$a,$year,$start);
        $this->createAssignment($newGuru,$b,$year,$start);
        $version = \App\Services\HomeroomAssignmentService::version($old);
        $this->postJson("/api/homeroom-assignments/{$old->id}/replace",['guru_id'=>$newGuru->id,'version'=>$version])->assertUnprocessable()->assertJsonValidationErrors('guru_id');
        $this->assertNull($old->refresh()->tanggal_selesai);
        $this->assertDatabaseCount('wali_kelas',2);
    }

    public function test_v60_identity_immutable_and_stale_version_rejected(): void
    {
        Sanctum::actingAs($this->createUser('TU'));
        [, $guru] = $this->createTeacher(); [, $other] = $this->createTeacher();
        $year = $this->createAcademicYear(); $class = $this->createClass($year);
        $old = $this->createAssignment($guru,$class,$year);
        $this->putJson("/api/homeroom-assignments/{$old->id}",['guru_id'=>$other->id])->assertUnprocessable()->assertJsonValidationErrors('guru_id');
        $this->deleteJson("/api/homeroom-assignments/{$old->id}",['version'=>str_repeat('0',64)])->assertConflict();
        $this->assertSame($guru->id,$old->refresh()->guru_id);
        $this->assertNull($old->tanggal_selesai);
    }

    public function test_v60_workspace_counts_scheduled_without_granting_scope_and_filters_candidates(): void
    {
        Sanctum::actingAs($this->createUser('TU'));
        [$user, $guru] = $this->createTeacher(); [, $free] = $this->createTeacher();
        $year = $this->createAcademicYear(); $a = $this->createClass($year,'E1'); $b = $this->createClass($year,'E2');
        $start = today('Asia/Jakarta')->addDay()->toDateString();
        $this->createAssignment($guru,$a,$year,$start);
        $this->getJson('/api/homeroom-management?academic_year_id='.$year->id)->assertOk()->assertJsonPath('data.summary.scheduled',1)->assertJsonPath('data.summary.unassigned',1)->assertJsonPath('data.summary.effective',0);
        $this->getJson('/api/homeroom-management/candidates?class_id='.$b->id.'&tanggal_mulai='.$start)->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$free->id);
        Sanctum::actingAs($user);
        $this->getJson('/api/homeroom-management?academic_year_id='.$year->id)->assertForbidden();
        $this->getJson('/api/homeroom-management/candidates?class_id='.$b->id.'&tanggal_mulai='.$start)->assertForbidden();
        $this->getJson('/api/homeroom-assignments?scope=homeroom')->assertOk()->assertJsonCount(0,'data');
    }

    public function test_v60_workspace_flags_legacy_overlap_without_writing(): void
    {
        Sanctum::actingAs($this->createUser('TU'));
        [, $guru] = $this->createTeacher();
        $year = $this->createAcademicYear(); $a = $this->createClass($year,'E1'); $b = $this->createClass($year,'E2');
        $start = today('Asia/Jakarta')->subDay()->toDateString();
        $this->createAssignment($guru,$a,$year,$start);
        $this->createAssignment($guru,$b,$year,$start);
        $this->getJson('/api/homeroom-management?academic_year_id='.$year->id)->assertOk()->assertJsonPath('data.summary.conflict',2);
        $this->assertDatabaseCount('wali_kelas',2);
    }
    public function test_v60_replacement_changes_current_scope_without_changing_role(): void
    {
        $tu = $this->createUser('TU');
        [$oldUser,$oldGuru] = $this->createTeacher(); [$newUser,$newGuru] = $this->createTeacher();
        $year = $this->createAcademicYear(); $class = $this->createClass($year);
        $old = $this->createAssignment($oldGuru,$class,$year,today('Asia/Jakarta')->subDay()->toDateString());
        $suffix = '?scope=homeroom&academic_year_id='.$year->id;
        Sanctum::actingAs($oldUser);
        $this->getJson('/api/classes/'.$class->id.'/students'.$suffix)->assertOk();
        Sanctum::actingAs($tu);
        $this->postJson('/api/homeroom-assignments/'.$old->id.'/replace',['guru_id'=>$newGuru->id,'version'=>\App\Services\HomeroomAssignmentService::version($old),'as_of'=>today('Asia/Jakarta')->toDateString()])->assertCreated();
        Sanctum::actingAs($oldUser);
        $this->getJson('/api/classes/'.$class->id.'/students'.$suffix)->assertForbidden();
        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.user.role.code','GURU')->assertJsonCount(0,'data.assignments');
        Sanctum::actingAs($newUser);
        $this->getJson('/api/classes/'.$class->id.'/students'.$suffix)->assertOk();
        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.user.role.code','GURU')->assertJsonPath('data.assignments.0.class_ids.0',$class->id);
    }

    public function test_v60_future_end_and_stale_server_day_are_rejected(): void
    {
        Sanctum::actingAs($this->createUser('TU'));
        [, $guru] = $this->createTeacher(); $year = $this->createAcademicYear(); $class = $this->createClass($year);
        $today = today('Asia/Jakarta');
        $this->postJson('/api/homeroom-assignments',['guru_id'=>$guru->id,'kelas_id'=>$class->id,'tahun_akademik_id'=>$year->id,'tanggal_mulai'=>$today->toDateString(),'tanggal_selesai'=>$today->copy()->addDay()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('tanggal_selesai');
        $old = $this->createAssignment($guru,$class,$year,$today->copy()->subDay()->toDateString());
        $this->deleteJson('/api/homeroom-assignments/'.$old->id,['version'=>\App\Services\HomeroomAssignmentService::version($old),'as_of'=>$today->copy()->subDay()->toDateString()])->assertConflict();
        $this->assertNull($old->refresh()->tanggal_selesai);
    }

    public function test_v61_nonactive_excluded_pending_allowed_and_direct_mutations_revalidated(): void
    {
        Sanctum::actingAs($this->createUser('TU'));
        [$inactive,$bad] = $this->createTeacher(); [$pending,$good] = $this->createTeacher();
        $inactive->update(['account_status'=>'inactive']); $pending->forceFill(['requires_activation'=>true])->save();
        $year=$this->createAcademicYear(); $a=$this->createClass($year,'E1'); $b=$this->createClass($year,'E2');
        $today=today('Asia/Jakarta')->toDateString();
        $this->getJson('/api/homeroom-management/candidates?class_id='.$a->id.'&tanggal_mulai='.$today)->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$good->id)->assertJsonPath('data.0.account_state','pending');
        $payload=['guru_id'=>$bad->id,'kelas_id'=>$a->id,'tahun_akademik_id'=>$year->id,'tanggal_mulai'=>$today];
        $this->postJson('/api/homeroom-assignments',$payload)->assertUnprocessable()->assertJsonValidationErrors('guru_id');
        $this->postJson('/api/homeroom-assignments',array_replace($payload,['guru_id'=>$good->id]))->assertCreated();
        $old=WaliKelas::firstOrFail();
        $this->postJson('/api/homeroom-assignments/'.$old->id.'/replace',['guru_id'=>$bad->id,'version'=>\App\Services\HomeroomAssignmentService::version($old)])->assertUnprocessable();
        $this->assertNull($old->refresh()->tanggal_selesai);
        $this->getJson('/api/homeroom-management/candidates?class_id='.$b->id.'&tanggal_mulai='.$today)->assertOk()->assertJsonCount(0,'data');
        $pending->update(['account_status'=>'inactive']);
        $this->getJson('/api/homeroom-management?academic_year_id='.$year->id)->assertOk()->assertJsonPath('data.assignments.0.account_state','inactive')->assertJsonPath('data.summary.effective',1);
    }

    public function test_v61_cancel_preserves_history_releases_candidate_and_never_grants_scope(): void
    {
        $tu=$this->createUser('TU'); [$user,$guru]=$this->createTeacher();
        $year=$this->createAcademicYear(); $class=$this->createClass($year);
        $tomorrow=today('Asia/Jakarta')->addDay()->toDateString(); $today=today('Asia/Jakarta')->toDateString();
        $old=$this->createAssignment($guru,$class,$year,$tomorrow);
        $url='/api/homeroom-assignments/'.$old->id.'/cancel';
        $payload=['version'=>\App\Services\HomeroomAssignmentService::version($old),'as_of'=>$today,'reason'=>'UAT pembatalan'];
        Sanctum::actingAs($user); $this->postJson($url,$payload)->assertForbidden();
        Sanctum::actingAs($tu); $this->postJson($url,$payload)->assertOk(); $this->postJson($url,$payload)->assertConflict();
        $this->assertDatabaseCount('wali_kelas',1); $this->assertDatabaseHas('wali_kelas',['id'=>$old->id,'cancelled_by'=>$tu->id,'cancel_reason'=>'UAT pembatalan','tanggal_selesai'=>null]);
        $this->getJson('/api/homeroom-management?academic_year_id='.$year->id)->assertOk()->assertJsonPath('data.summary.unassigned',1)->assertJsonPath('data.assignments.0.status','cancelled');
        $this->getJson('/api/homeroom-management/candidates?class_id='.$class->id.'&tanggal_mulai='.$tomorrow)->assertOk()->assertJsonCount(1,'data');
        $this->getJson('/api/classes?directory=1')->assertOk()->assertJsonPath('data.0.homeroom_count',0)->assertJsonPath('data.0.history.homerooms',1)->assertJsonPath('data.0.locked',true);
        $this->putJson('/api/homeroom-assignments/'.$old->id,['tanggal_mulai'=>$today])->assertConflict();
        $this->travel(2)->days();
        Sanctum::actingAs($user);
        $this->getJson('/api/me')->assertOk()->assertJsonCount(0,'data.assignments');
        $this->getJson('/api/homeroom-assignments?scope=homeroom')->assertOk()->assertJsonCount(0,'data');
        $this->getJson('/api/reports/context')->assertOk()->assertJsonCount(0,'data.homeroom.classes');
        $this->getJson('/api/classes/'.$class->id.'/students?scope=homeroom&academic_year_id='.$year->id)->assertForbidden();
        $this->travelBack();
    }

    public function test_v61_cannot_cancel_effective_assignment_or_use_stale_date(): void
    {
        Sanctum::actingAs($this->createUser('TU')); [, $guru]=$this->createTeacher();
        $year=$this->createAcademicYear(); $class=$this->createClass($year);
        $today=today('Asia/Jakarta')->toDateString(); $old=$this->createAssignment($guru,$class,$year,$today);
        $this->postJson('/api/homeroom-assignments/'.$old->id.'/cancel',['version'=>\App\Services\HomeroomAssignmentService::version($old),'as_of'=>$today,'reason'=>'Salah'])->assertConflict();
        $this->assertNull($old->refresh()->cancelled_at);
    }

    public function test_v61_continuation_read_only_preview_atomic_commit_and_replay_rejected(): void
    {
        config(['app.key'=>'base64:'.base64_encode(str_repeat('a',32))]);
        $tu=$this->createUser('TU'); [$user,$guru]=$this->createTeacher(); [, $second]=$this->createTeacher();
        $source=$this->createAcademicYear(); $target=$this->createAcademicYear(2026,2027,'GENAP',false);
        $a=$this->createClass($source,'E1'); $b=$this->createClass($source,'E2'); $c=$this->createClass($target,'E1'); $d=$this->createClass($target,'E2');
        $today=today('Asia/Jakarta')->toDateString();
        $old=$this->createAssignment($guru,$a,$source,$today); $old2=$this->createAssignment($second,$b,$source,$today);
        $url='/api/homeroom-management/continuation'; $data=['source_id'=>$source->id,'target_id'=>$target->id,'tanggal_mulai'=>$today,'rows'=>[['assignment_id'=>$old->id,'class_id'=>$c->id],['assignment_id'=>$old2->id,'class_id'=>$d->id]]];
        Sanctum::actingAs($user); $this->postJson($url,$data)->assertForbidden();
        Sanctum::actingAs($tu); $token=$this->postJson($url,$data)->assertOk()->assertJsonPath('data.valid',true)->json('data.token');
        $this->assertDatabaseCount('wali_kelas',2);
        $this->postJson($url,$data+['token'=>$token.'x'])->assertConflict(); $this->assertDatabaseCount('wali_kelas',2);
        $this->postJson($url,$data+['token'=>$token])->assertOk()->assertJsonPath('data.created',2);
        $this->assertDatabaseCount('wali_kelas',4); $this->assertNull($old->refresh()->tanggal_selesai); $this->assertFalse($target->refresh()->is_active); $this->assertTrue($source->refresh()->is_active);
        $this->postJson($url,$data+['token'=>$token])->assertConflict();
        Sanctum::actingAs($user); $this->getJson('/api/me')->assertOk()->assertJsonPath('data.assignments.0.class_ids.0',$a->id);
        $source->update(['is_active'=>false]); $target->update(['is_active'=>true]);
        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.assignments.0.class_ids.0',$c->id);
    }

    public function test_v61_continuation_stale_candidate_and_wrong_year_never_partially_write(): void
    {
        config(['app.key'=>'base64:'.base64_encode(str_repeat('b',32))]);
        Sanctum::actingAs($this->createUser('TU')); [, $guru]=$this->createTeacher(); [$user2,$guru2]=$this->createTeacher();
        $source=$this->createAcademicYear(); $target=$this->createAcademicYear(2026,2027,'GENAP',false); $other=$this->createAcademicYear(2027,2028,'GANJIL',false);
        $a=$this->createClass($source,'E1'); $b=$this->createClass($source,'E2'); $c=$this->createClass($target,'E1'); $d=$this->createClass($target,'E2'); $e=$this->createClass($other,'E1');
        $today=today('Asia/Jakarta')->toDateString(); $old=$this->createAssignment($guru,$a,$source,$today); $old2=$this->createAssignment($guru2,$b,$source,$today);
        $url='/api/homeroom-management/continuation'; $data=['source_id'=>$source->id,'target_id'=>$target->id,'tanggal_mulai'=>$today,'rows'=>[['assignment_id'=>$old->id,'class_id'=>$c->id],['assignment_id'=>$old2->id,'class_id'=>$d->id]]];
        $this->postJson($url,array_replace($data,['target_id'=>$other->id,'rows'=>[['assignment_id'=>$old->id,'class_id'=>$e->id]]]))->assertUnprocessable();
        $token=$this->postJson($url,$data)->assertOk()->assertJsonPath('data.valid',true)->json('data.token');
        $user2->update(['account_status'=>'inactive']);
        $this->postJson($url,$data+['token'=>$token])->assertConflict(); $this->assertDatabaseCount('wali_kelas',2);
        $this->postJson($url,$data)->assertOk()->assertJsonPath('data.valid',false)->assertJsonPath('data.token',null);
    }

    public function test_v61_cancelled_destination_cannot_revive_consumed_preview(): void
    {
        config(['app.key'=>'base64:'.base64_encode(str_repeat('c',32))]);
        Sanctum::actingAs($this->createUser('TU')); [, $guru]=$this->createTeacher();
        $source=$this->createAcademicYear(); $target=$this->createAcademicYear(2026,2027,'GENAP',false);
        $a=$this->createClass($source,'E1'); $b=$this->createClass($target,'E1');
        $today=today('Asia/Jakarta')->toDateString(); $old=$this->createAssignment($guru,$a,$source,$today);
        $url='/api/homeroom-management/continuation'; $data=['source_id'=>$source->id,'target_id'=>$target->id,'tanggal_mulai'=>today('Asia/Jakarta')->addDay()->toDateString(),'rows'=>[['assignment_id'=>$old->id,'class_id'=>$b->id]]];
        $token=$this->postJson($url,$data)->assertOk()->assertJsonPath('data.valid',true)->json('data.token');
        $this->postJson($url,$data+['token'=>$token])->assertOk(); $new=WaliKelas::where('tahun_akademik_id',$target->id)->firstOrFail();
        $this->postJson('/api/homeroom-assignments/'.$new->id.'/cancel',['version'=>\App\Services\HomeroomAssignmentService::version($new),'as_of'=>$today,'reason'=>'Batal UAT'])->assertOk();
        $this->postJson($url,$data+['token'=>$token])->assertConflict(); $this->assertDatabaseCount('wali_kelas',2);
    }
}
