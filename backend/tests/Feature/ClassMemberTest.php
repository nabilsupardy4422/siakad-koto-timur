<?php

namespace Tests\Feature;

use App\Models\AnggotaKelas;
use App\Models\Guru;
use App\Models\JadwalPelajaran;
use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\TahunAkademik;
use App\Models\User;
use App\Models\WaliKelas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClassMemberTest extends TestCase
{
    use RefreshDatabase;

    private function createRole(string $code): int
    {
        return \App\Models\Role::firstOrCreate(
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

    private function createAcademicYear(
        bool $active = true
    ): TahunAkademik {
        return TahunAkademik::create([
            'tahun_mulai' => 2026,
            'tahun_selesai' => 2027,
            'semester' => 'GANJIL',
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

    private function createMembership(
        Kelas $class,
        Siswa $student,
        string $tanggalMulai = '2026-07-01',
        ?string $tanggalSelesai = null
    ): AnggotaKelas {
        return AnggotaKelas::create([
            'kelas_id' => $class->id,
            'siswa_id' => $student->id,
            'tanggal_mulai' => $tanggalMulai,
            'tanggal_selesai' => $tanggalSelesai,
        ]);
    }

    private function createSubject(): \App\Models\Mapel
    {
        return \App\Models\Mapel::create([
            'kode' => 'MAPEL-' . uniqid(),
            'nama' => 'Mapel Test ' . uniqid(),
        ]);
    }

    private function createTeachingSchedule(
        Guru $guru,
        Kelas $class,
        TahunAkademik $academicYear
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

    private function assignWaliKelas(
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

    public function test_guest_cannot_access_class_members(): void
    {
        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $this->getJson("/api/classes/{$class->id}/students")
            ->assertStatus(401);
    }

    public function test_tu_can_view_class_members(): void
    {
        $user = $this->createUser('TU');
        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        [, $student] = $this->createStudent();

        $this->createMembership($class, $student);

        Sanctum::actingAs($user);

        $this->getJson("/api/classes/{$class->id}/students")
            ->assertOk()
            ->assertJsonStructure([
                'data',
                'meta',
            ])
            ->assertJsonFragment([
                'siswa_id' => $student->id,
            ]);
    }

    public function test_kepala_sekolah_can_view_class_members(): void
    {
        $user = $this->createUser('KEPALA_SEKOLAH');
        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        [, $student] = $this->createStudent();

        $this->createMembership($class, $student);

        Sanctum::actingAs($user);

        $this->getJson("/api/classes/{$class->id}/students")
            ->assertOk()
            ->assertJsonFragment([
                'siswa_id' => $student->id,
            ]);
    }

    public function test_guru_can_view_members_of_teaching_class(): void
    {
        [$user, $guru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        [, $student] = $this->createStudent();

        $this->createMembership($class, $student);
        $this->createTeachingSchedule($guru, $class, $academicYear);

        Sanctum::actingAs($user);

        $this->getJson("/api/classes/{$class->id}/students")
            ->assertOk()
            ->assertJsonFragment([
                'siswa_id' => $student->id,
            ]);
    }

    public function test_guru_cannot_view_members_of_class_outside_teaching_scope(): void
    {
        [$user, $guru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();

        $assignedClass = $this->createClass(
            $academicYear,
            'X IPA 1'
        );

        $otherClass = $this->createClass(
            $academicYear,
            'X IPA 2'
        );

        [, $student] = $this->createStudent();

        $this->createMembership($otherClass, $student);
        $this->createTeachingSchedule($guru, $assignedClass, $academicYear);

        Sanctum::actingAs($user);

        $this->getJson("/api/classes/{$otherClass->id}/students")
            ->assertForbidden();
    }

    public function test_wali_kelas_can_view_assigned_class_members(): void
    {
        [$user, $guru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        [, $student] = $this->createStudent();

        $this->createMembership($class, $student);
        $this->assignWaliKelas($guru, $class, $academicYear);

        Sanctum::actingAs($user);

        $this->getJson("/api/classes/{$class->id}/students")
            ->assertOk()
            ->assertJsonFragment([
                'siswa_id' => $student->id,
            ]);
    }

    public function test_wali_kelas_assignment_does_not_grant_access_to_another_academic_year(): void
    {
        [$user, $guru] = $this->createTeacher();

        $academicYearOne = $this->createAcademicYear();

        $academicYearTwo = TahunAkademik::create([
            'tahun_mulai' => 2027,
            'tahun_selesai' => 2028,
            'semester' => 'GANJIL',
            'is_active' => false,
        ]);

        $assignedClass = $this->createClass(
            $academicYearOne,
            'X IPA 1'
        );

        $otherClass = $this->createClass(
            $academicYearTwo,
            'X IPA 1'
        );

        [, $student] = $this->createStudent();

        $this->createMembership($otherClass, $student);
        $this->assignWaliKelas($guru, $assignedClass, $academicYearOne);

        Sanctum::actingAs($user);

        $this->getJson("/api/classes/{$assignedClass->id}/students")
            ->assertOk();

        $this->getJson("/api/classes/{$otherClass->id}/students")
            ->assertForbidden();
    }

    public function test_siswa_can_view_own_class_membership_only(): void
    {
        [$user, $student] = $this->createStudent();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $this->createMembership($class, $student);

        Sanctum::actingAs($user);

        $this->getJson("/api/classes/{$class->id}/students")
            ->assertOk()
            ->assertJsonFragment([
                'siswa_id' => $student->id,
            ]);
    }

    public function test_siswa_cannot_view_class_where_they_are_not_member(): void
    {
        [$user, $student] = $this->createStudent();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        [, $otherStudent] = $this->createStudent();

        $this->createMembership($class, $otherStudent);

        Sanctum::actingAs($user);

        $this->getJson("/api/classes/{$class->id}/students")
            ->assertForbidden();
    }

    public function test_siswa_does_not_see_other_members_of_own_class(): void
    {
        [$user, $student] = $this->createStudent();
        [, $otherStudent] = $this->createStudent();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        $this->createMembership($class, $student);
        $this->createMembership(
            $class,
            $otherStudent,
            '2026-07-02'
        );

        Sanctum::actingAs($user);

        $this->getJson("/api/classes/{$class->id}/students")
            ->assertOk()
            ->assertJsonFragment([
                'siswa_id' => $student->id,
            ])
            ->assertJsonMissing([
                'siswa_id' => $otherStudent->id,
            ]);
    }

    public function test_tu_can_add_student_to_class(): void
    {
        $user = $this->createUser('TU');
        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        [, $student] = $this->createStudent();

        Sanctum::actingAs($user);

        $response = $this->postJson(
            "/api/classes/{$class->id}/students",
            [
                'siswa_id' => $student->id,
                'tanggal_mulai' => '2026-07-01',
            ]
        );

        $response
            ->assertCreated()
            ->assertJsonPath('data.siswa_id', $student->id)
            ->assertJsonPath('data.kelas_id', $class->id)
            ->assertJsonPath('data.tanggal_mulai', '2026-07-01')
            ->assertJsonPath('data.tanggal_selesai', null);

        $this->assertDatabaseHas('anggota_kelas', [
            'kelas_id' => $class->id,
            'siswa_id' => $student->id,
            'tanggal_mulai' => '2026-07-01 00:00:00',
            'tanggal_selesai' => null,
        ]);
    }

    public function test_wali_kelas_cannot_add_student_to_assigned_class(): void
    {
        [$user, $guru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        [, $student] = $this->createStudent();

        $this->assignWaliKelas($guru, $class, $academicYear);

        Sanctum::actingAs($user);

        $this->postJson(
            "/api/classes/{$class->id}/students",
            [
                'siswa_id' => $student->id,
                'tanggal_mulai' => '2026-07-01',
            ]
        )->assertForbidden();

        $this->assertDatabaseMissing('anggota_kelas', [
            'kelas_id' => $class->id,
            'siswa_id' => $student->id,
        ]);
    }

    public function test_guru_without_wali_assignment_cannot_add_student(): void
    {
        [$user] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        [, $student] = $this->createStudent();

        Sanctum::actingAs($user);

        $this->postJson(
            "/api/classes/{$class->id}/students",
            [
                'siswa_id' => $student->id,
                'tanggal_mulai' => '2026-07-01',
            ]
        )->assertForbidden();
    }

    public function test_kepala_sekolah_cannot_add_student(): void
    {
        $user = $this->createUser('KEPALA_SEKOLAH');

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        [, $student] = $this->createStudent();

        Sanctum::actingAs($user);

        $this->postJson(
            "/api/classes/{$class->id}/students",
            [
                'siswa_id' => $student->id,
                'tanggal_mulai' => '2026-07-01',
            ]
        )->assertForbidden();
    }

    public function test_siswa_cannot_add_student(): void
    {
        [$user] = $this->createStudent();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        [, $student] = $this->createStudent();

        Sanctum::actingAs($user);

        $this->postJson(
            "/api/classes/{$class->id}/students",
            [
                'siswa_id' => $student->id,
                'tanggal_mulai' => '2026-07-01',
            ]
        )->assertForbidden();
    }

    public function test_create_membership_requires_valid_fields(): void
    {
        $user = $this->createUser('TU');

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        Sanctum::actingAs($user);

        $this->postJson("/api/classes/{$class->id}/students", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'siswa_id',
                'tanggal_mulai',
            ]);
    }

    public function test_create_membership_rejects_unknown_student(): void
    {
        $user = $this->createUser('TU');

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        Sanctum::actingAs($user);

        $this->postJson(
            "/api/classes/{$class->id}/students",
            [
                'siswa_id' => 999999,
                'tanggal_mulai' => '2026-07-01',
            ]
        )
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'siswa_id',
            ]);
    }

    public function test_student_with_active_membership_cannot_join_another_class_in_same_academic_year(): void
    {
        $user = $this->createUser('TU');

        $academicYear = $this->createAcademicYear();

        $classOne = $this->createClass(
            $academicYear,
            'X IPA 1'
        );

        $classTwo = $this->createClass(
            $academicYear,
            'X IPA 2'
        );

        [, $student] = $this->createStudent();

        $this->createMembership($classOne, $student);

        Sanctum::actingAs($user);

        $this->postJson(
            "/api/classes/{$classTwo->id}/students",
            [
                'siswa_id' => $student->id,
                'tanggal_mulai' => '2026-08-01',
            ]
        )
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Siswa sudah memiliki kelas aktif pada tahun akademik ini.'
            );
    }

    public function test_student_can_join_class_in_different_academic_year_after_previous_membership_ends(): void
    {
        $user = $this->createUser('TU');

        $academicYearOne = $this->createAcademicYear();

        $academicYearTwo = TahunAkademik::create([
            'tahun_mulai' => 2027,
            'tahun_selesai' => 2028,
            'semester' => 'GANJIL',
            'is_active' => false,
        ]);

        $classOne = $this->createClass(
            $academicYearOne,
            'X IPA 1'
        );

        $classTwo = $this->createClass(
            $academicYearTwo,
            'XI IPA 1'
        );

        [, $student] = $this->createStudent();

        $this->createMembership(
            $classOne,
            $student,
            '2026-07-01',
            '2027-06-30'
        );

        Sanctum::actingAs($user);

        $this->postJson(
            "/api/classes/{$classTwo->id}/students",
            [
                'siswa_id' => $student->id,
                'tanggal_mulai' => '2027-07-01',
            ]
        )->assertCreated();

        $this->assertDatabaseHas('anggota_kelas', [
            'kelas_id' => $classTwo->id,
            'siswa_id' => $student->id,
            'tanggal_mulai' => '2027-07-01 00:00:00',
            'tanggal_selesai' => null,
        ]);
    }

    public function test_class_member_list_only_returns_active_membership(): void
    {
        $user = $this->createUser('TU');

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        [, $activeStudent] = $this->createStudent();
        [, $historicalStudent] = $this->createStudent();

        $this->createMembership(
            $class,
            $activeStudent,
            '2026-07-01'
        );

        $this->createMembership(
            $class,
            $historicalStudent,
            '2026-07-01',
            '2026-10-01'
        );

        Sanctum::actingAs($user);

        $this->getJson("/api/classes/{$class->id}/students")
            ->assertOk()
            ->assertJsonFragment([
                'siswa_id' => $activeStudent->id,
            ])
            ->assertJsonMissing([
                'siswa_id' => $historicalStudent->id,
            ]);
    }

    public function test_tu_can_end_class_membership(): void
    {
        $user = $this->createUser('TU');

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        [, $student] = $this->createStudent();

        $membership = $this->createMembership(
            $class,
            $student
        );

        Sanctum::actingAs($user);

        $this->deleteJson(
            "/api/classes/{$class->id}/students/{$student->id}"
        )
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Keanggotaan siswa pada kelas berhasil diakhiri.'
            );

        $this->assertDatabaseHas('anggota_kelas', [
            'id' => $membership->id,
            'kelas_id' => $class->id,
            'siswa_id' => $student->id,
        ]);

        $this->assertNotNull(
            AnggotaKelas::find($membership->id)->tanggal_selesai
        );
    }

    public function test_wali_kelas_cannot_end_class_membership(): void
    {
        [$user, $guru] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        [, $student] = $this->createStudent();

        $membership = $this->createMembership(
            $class,
            $student
        );

        $this->assignWaliKelas($guru, $class, $academicYear);

        Sanctum::actingAs($user);

        $this->deleteJson(
            "/api/classes/{$class->id}/students/{$student->id}"
        )->assertForbidden();

        $this->assertNull(
            AnggotaKelas::find($membership->id)->tanggal_selesai
        );
    }

    public function test_guru_without_wali_assignment_cannot_end_class_membership(): void
    {
        [$user] = $this->createTeacher();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        [, $student] = $this->createStudent();

        $this->createMembership(
            $class,
            $student
        );

        Sanctum::actingAs($user);

        $this->deleteJson(
            "/api/classes/{$class->id}/students/{$student->id}"
        )->assertForbidden();
    }

    public function test_siswa_cannot_end_class_membership(): void
    {
        [$user] = $this->createStudent();

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        [, $student] = $this->createStudent();

        $this->createMembership(
            $class,
            $student
        );

        Sanctum::actingAs($user);

        $this->deleteJson(
            "/api/classes/{$class->id}/students/{$student->id}"
        )->assertForbidden();
    }

    public function test_ending_non_active_membership_returns_not_found(): void
    {
        $user = $this->createUser('TU');

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        [, $student] = $this->createStudent();

        $this->createMembership(
            $class,
            $student,
            '2026-07-01',
            '2026-10-01'
        );

        Sanctum::actingAs($user);

        $this->deleteJson(
            "/api/classes/{$class->id}/students/{$student->id}"
        )->assertNotFound();
    }

    public function test_duplicate_membership_start_date_is_rejected(): void
    {
        $user = $this->createUser('TU');

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        [, $student] = $this->createStudent();

        $this->createMembership(
            $class,
            $student,
            '2026-07-01',
            '2026-10-01'
        );

        Sanctum::actingAs($user);

        $this->postJson(
            "/api/classes/{$class->id}/students",
            [
                'siswa_id' => $student->id,
                'tanggal_mulai' => '2026-07-01',
            ]
        )
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'tanggal_mulai',
            ]);
    }

    public function test_class_members_support_pagination(): void
    {
        $user = $this->createUser('TU');

        $academicYear = $this->createAcademicYear();
        $class = $this->createClass($academicYear);

        for ($i = 0; $i < 3; $i++) {
            [, $student] = $this->createStudent();

            $this->createMembership(
                $class,
                $student,
                '2026-07-' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT)
            );
        }

        Sanctum::actingAs($user);

        $this->getJson(
            "/api/classes/{$class->id}/students?per_page=2&page=1"
        )
            ->assertOk()
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.total', 3);
    }

    public function test_homeroom_scope_disappears_when_period_changes_and_returns_only_after_new_assignment(): void
    {
        [$user, $guru] = $this->createTeacher();
        $old = $this->createAcademicYear();
        $new = TahunAkademik::create(['tahun_mulai'=>2026,'tahun_selesai'=>2027,'semester'=>'GENAP','is_active'=>false]);
        $oldClass = $this->createClass($old);
        [, $peer] = $this->createTeacher();
        $this->createTeachingSchedule($peer, $oldClass, $old);
        $newClass = $this->createClass($new);
        [, $student] = $this->createStudent();
        $this->createMembership($oldClass, $student, today()->subDays(2)->toDateString());
        $assignment = $this->assignWaliKelas($guru, $oldClass, $old);
        $assignment->update(['tanggal_mulai'=>today()->subDays(2)->toDateString()]);
        Sanctum::actingAs($user);
        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.assignments.0.academic_year_id',$old->id);
        $this->getJson("/api/classes/{$oldClass->id}/students?scope=homeroom&membership_status=all")->assertOk()->assertJsonCount(1,'data');
        $this->getJson("/api/classes/{$oldClass->id}")->assertOk();
        $this->getJson("/api/students/{$student->id}")->assertOk();
        $this->get("/api/reports/schedules?format=excel&scope=homeroom&academic_year_id={$old->id}&class_id={$oldClass->id}")->assertOk();
        $old->update(['is_active'=>false]);
        $new->update(['is_active'=>true]);
        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.assignments',[]);
        $this->getJson("/api/classes/{$oldClass->id}/students?scope=homeroom")->assertForbidden();
        $this->getJson("/api/classes/{$oldClass->id}/students")->assertForbidden();
        $this->getJson("/api/classes/{$oldClass->id}")->assertForbidden();
        $this->getJson("/api/students/{$student->id}")->assertForbidden();
        $this->getJson("/api/grades/recap?scope=homeroom&class_id={$oldClass->id}")->assertForbidden();
        $this->getJson("/api/attendance/recap?scope=homeroom&class_id={$oldClass->id}")->assertForbidden();
        $this->getJson("/api/reports/schedules?format=excel&scope=homeroom&academic_year_id={$old->id}&class_id={$oldClass->id}")->assertForbidden();
        $this->getJson("/api/classes/{$newClass->id}/students?scope=homeroom")->assertForbidden();
        $this->getJson('/api/homeroom-assignments?scope=homeroom')->assertOk()->assertJsonCount(0,'data');
        $this->assertDatabaseHas('wali_kelas',['id'=>$assignment->id,'tanggal_selesai'=>null]);
        $next = $this->assignWaliKelas($guru, $newClass, $new);
        $next->update(['tanggal_mulai'=>today()->subDay()->toDateString()]);
        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.assignments.0.academic_year_id',$new->id);
        $this->getJson("/api/classes/{$newClass->id}/students?scope=homeroom")->assertOk();
        $this->getJson("/api/classes/{$oldClass->id}/students?scope=homeroom")->assertForbidden();
    }

    public function test_homeroom_history_requires_effective_assignment_and_legacy_roster_is_unchanged(): void
    {
        [$user,$guru]=$this->createTeacher();
        $year=$this->createAcademicYear();
        $class=$this->createClass($year);
        $outside=$this->createClass($year,'Outside');
        $assignment=$this->assignWaliKelas($guru,$class,$year);
        $assignment->update(['tanggal_mulai'=>today()->subDays(10)->toDateString()]);
        [, $student]=$this->createStudent();
        [, $future]=$this->createStudent();
        $this->createMembership($class,$student,today()->subDays(3)->toDateString(),today()->subDay()->toDateString());
        $this->createMembership($class,$future,today()->addDay()->toDateString());
        Sanctum::actingAs($user);
        $all="/api/classes/{$class->id}/students?scope=homeroom&membership_status=all";
        $this->getJson($all)->assertOk()->assertJsonCount(2,'data')->assertJsonPath('meta.as_of',today()->toDateString());
        $this->getJson("/api/classes/{$class->id}/students?scope=homeroom")->assertOk()->assertJsonCount(0,'data');
        $this->getJson("/api/classes/{$class->id}/students?membership_status=all")->assertForbidden();
        $this->getJson("/api/classes/{$outside->id}/students?scope=homeroom&membership_status=all")->assertForbidden();
        $assignment->update(['tanggal_selesai'=>today()->toDateString()]);
        $this->getJson($all)->assertForbidden();
    }
}
