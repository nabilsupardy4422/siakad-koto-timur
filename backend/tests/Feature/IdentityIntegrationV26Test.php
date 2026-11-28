<?php

namespace Tests\Feature;

use App\Models\{AnggotaKelas, Guru, JadwalPelajaran, Kelas, Mapel, Presensi, Role, Siswa, TahunAkademik, User, WaliKelas};
use App\Services\GoogleIdentityVerifier;
use App\Services\Reporting\ReportService;
use App\Support\IdentityIntegrationAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Event, Hash};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IdentityIntegrationV26Test extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Integration#2026!';

    private function account(string $role, string $name): User
    {
        $r = Role::firstOrCreate(['code' => $role], ['name' => $role]);
        return User::create(['role_id' => $r->id, 'username' => $name, 'name' => $name, 'password' => self::PASSWORD, 'account_status' => 'active']);
    }

    private function teacher(string $name = 'teacher'): Guru
    {
        return Guru::create(['user_id' => $this->account('GURU', $name)->id, 'nip' => 'NIP-'.$name, 'nama_lengkap' => $name]);
    }

    private function student(string $name = 'student'): Siswa
    {
        return Siswa::create(['user_id' => $this->account('SISWA', $name)->id, 'nisn' => 'NISN-'.$name, 'nama_lengkap' => $name, 'alamat' => 'private-contact-'.$name]);
    }

    private function period(bool $active = true, string $semester = 'GANJIL'): TahunAkademik
    {
        return TahunAkademik::create(['tahun_mulai' => 2026, 'tahun_selesai' => 2027, 'semester' => $semester, 'is_active' => $active]);
    }

    private function classroom(TahunAkademik $year, string $name): Kelas
    {
        return Kelas::create(['tahun_akademik_id' => $year->id, 'nama' => $name, 'tingkat' => 10]);
    }

    private function membership(Siswa $student, Kelas $class, ?string $start = null, ?string $end = null): AnggotaKelas
    {
        return AnggotaKelas::create(['siswa_id' => $student->id, 'kelas_id' => $class->id, 'tanggal_mulai' => $start ?? now('Asia/Jakarta')->subDays(10)->toDateString(), 'tanggal_selesai' => $end]);
    }

    private function schedule(Guru $teacher, Kelas $class, TahunAkademik $year): JadwalPelajaran
    {
        $subject = Mapel::create(['kode' => 'AUDIT', 'nama' => 'Audit']);
        return JadwalPelajaran::create(['guru_id' => $teacher->id, 'kelas_id' => $class->id, 'tahun_akademik_id' => $year->id, 'mapel_id' => $subject->id, 'hari' => 'Senin', 'jam_mulai' => '07:00', 'jam_selesai' => '08:00']);
    }

    private function assignment(Guru $teacher, Kelas $class, TahunAkademik $year): WaliKelas
    {
        return WaliKelas::create(['guru_id' => $teacher->id, 'kelas_id' => $class->id, 'tahun_akademik_id' => $year->id, 'tanggal_mulai' => now('Asia/Jakarta')->subDay()->toDateString()]);
    }

    public function test_teacher_filter_and_authorization_must_match_the_same_membership(): void
    {
        $teacher = $this->teacher(); $student = $this->student(); $year = $this->period();
        $allowed = $this->classroom($year, 'X A'); $other = $this->classroom($year, 'X B');
        $this->membership($student, $allowed); $this->membership($student, $other);
        $this->schedule($teacher, $allowed, $year);
        Sanctum::actingAs($teacher->user);
        $this->getJson('/api/students?class_id='.$allowed->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.alamat');
        $this->getJson('/api/students?class_id='.$other->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/students/'.$student->id.'?class_id='.$other->id)->assertForbidden();
        $this->getJson('/api/students/'.$student->id.'?class_id='.$allowed->id)->assertOk();
    }

    public function test_mismatched_schedule_period_does_not_grant_student_directory_access(): void
    {
        $teacher = $this->teacher(); $student = $this->student(); $year = $this->period(); $other = $this->period(false, 'GENAP');
        $class = $this->classroom($other, 'X B'); $this->membership($student, $class);
        $schedule = $this->schedule($teacher, $class, $year); // deliberately inconsistent legacy record
        Sanctum::actingAs($teacher->user);
        $this->getJson('/api/students')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/students/'.$student->id)->assertForbidden();
        $this->assertSame(1, app(IdentityIntegrationAudit::class)->counts()['schedule_period_mismatch']);
        $tu = $this->account('TU', 'tu');
        $this->assertCount(0, app(ReportService::class)->build('students', $tu, ['schedule_id' => $schedule->id])['rows']);
    }

    public function test_homeroom_effective_directory_and_explicit_history_remain_distinct(): void
    {
        $teacher = $this->teacher(); $year = $this->period(); $class = $this->classroom($year, 'X A');
        $current = $this->student('current'); $future = $this->student('future'); $former = $this->student('former');
        $today = now('Asia/Jakarta');
        $this->membership($current, $class, null, $today->toDateString());
        $this->membership($future, $class, $today->copy()->addDay()->toDateString());
        $this->membership($former, $class, null, $today->copy()->subDay()->toDateString());
        $this->assignment($teacher, $class, $year); Sanctum::actingAs($teacher->user);
        $this->getJson('/api/students?class_id='.$class->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $current->id);
        $this->getJson('/api/students/'.$future->id)->assertForbidden();
        $this->getJson('/api/students/'.$former->id)->assertForbidden();
        $this->getJson('/api/classes/'.$class->id.'/students?scope=homeroom&membership_status=all&academic_year_id='.$year->id)->assertOk()->assertJsonCount(3, 'data')->assertJsonMissingPath('data.0.siswa.user.username');
        $year->update(['is_active' => false]);
        $this->getJson('/api/students')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/classes/'.$class->id.'/students?scope=homeroom&membership_status=all')->assertForbidden();
        $this->assertDatabaseCount('anggota_kelas', 3);
    }

    public function test_deactivation_revokes_access_but_preserves_membership_attendance_and_report(): void
    {
        $tu = $this->account('TU', 'tu'); $teacher = $this->teacher(); $student = $this->student(); $year = $this->period(); $class = $this->classroom($year, 'X A');
        $member = $this->membership($student, $class); $schedule = $this->schedule($teacher, $class, $year);
        $attendance = Presensi::create(['siswa_id' => $student->id, 'jadwal_pelajaran_id' => $schedule->id, 'tanggal' => now('Asia/Jakarta')->toDateString(), 'status' => 'hadir']);
        $user = $student->user; $token = $user->createToken('mobile')->plainTextToken;
        $service = app(ReportService::class); $before = $service->build('students', $tu, ['academic_year_id' => $year->id])['rows']->all();
        $attendanceBefore = $service->build('attendance', $tu, ['academic_year_id' => $year->id])['rows']->all();
        $this->assertCount(1, $before); $this->assertCount(1, $attendanceBefore);
        $this->withToken($tu->createToken('admin')->plainTextToken)->postJson('/api/users/'.$user->id.'/deactivate')->assertOk();
        $this->assertSame(0, $user->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/profile')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/schedules')->assertUnauthorized();
        $this->assertDatabaseHas('siswa', ['id' => $student->id]);
        $this->assertDatabaseHas('anggota_kelas', ['id' => $member->id]);
        $this->assertDatabaseHas('presensi', ['id' => $attendance->id]);
        $this->assertSame($before, $service->build('students', $tu, ['academic_year_id' => $year->id])['rows']->all());
        $this->assertSame($attendanceBefore, $service->build('attendance', $tu, ['academic_year_id' => $year->id])['rows']->all());
    }

    public function test_account_name_and_profile_are_synced_without_changing_schedule_or_username(): void
    {
        $tu = $this->account('TU', 'tu'); $teacher = $this->teacher(); $year = $this->period(); $class = $this->classroom($year, 'X A');
        $schedule = $this->schedule($teacher, $class, $year); $assignment = $this->assignment($teacher, $class, $year);
        Sanctum::actingAs($tu);
        $this->putJson('/api/teachers/'.$teacher->id, ['user_id' => $teacher->user_id, 'nip' => 'NIP-updated', 'nama_lengkap' => 'Official name'])->assertOk();
        $this->assertSame('Official name', $teacher->user->fresh()->name);
        $this->assertSame('teacher', $teacher->user->fresh()->username);
        $this->assertDatabaseHas('jadwal_pelajaran', ['id' => $schedule->id, 'guru_id' => $teacher->id]);
        $this->assertDatabaseHas('wali_kelas', ['id' => $assignment->id, 'guru_id' => $teacher->id]);
        $this->deleteJson('/api/teachers/'.$teacher->id)->assertUnprocessable();
        Sanctum::actingAs($teacher->user->fresh());
        $this->getJson('/api/profile')->assertOk()->assertJsonPath('data.user.name', 'Official name')->assertJsonPath('data.academic.homeroom_classes.0.id', $class->id);
    }

    public function test_password_reset_between_read_and_token_issue_cannot_mint_stale_token(): void
    {
        $user = $this->account('GURU', 'race-password'); $changed = false;
        // Deterministic interleaving simulation; real MySQL contention is a separate test.
        Event::listen('eloquent.retrieved: '.User::class, function (User $read) use ($user, &$changed): void {
            if (! $changed && $read->id === $user->id) {
                $changed = true;
                DB::table('users')->where('id', $user->id)->update(['password' => Hash::make('Replacement#2026!')]);
            }
        });
        $this->postJson('/api/login', ['username' => $user->username, 'password' => self::PASSWORD])->assertUnauthorized();
        $this->assertTrue($changed);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_google_unlink_between_read_and_issue_cannot_mint_token(): void
    {
        $user = $this->account('GURU', 'race-google'); $user->forceFill(['google_id' => 'audit-subject'])->save(); $changed = false;
        $this->mock(GoogleIdentityVerifier::class)->shouldReceive('subject')->once()->with('dummy-credential')->andReturn('audit-subject');
        Event::listen('eloquent.retrieved: '.User::class, function (User $read) use ($user, &$changed): void {
            if (! $changed && $read->id === $user->id) {
                $changed = true;
                DB::table('users')->where('id', $user->id)->update(['google_id' => null]);
            }
        });
        $this->postJson('/api/login/google', ['credential' => 'dummy-credential'])->assertUnauthorized();
        $this->assertTrue($changed); $this->assertSame(0, $user->tokens()->count());
    }

    public function test_google_login_returns_same_teacher_context_as_me(): void
    {
        $teacher = $this->teacher(); $user = $teacher->user; $user->forceFill(['google_id' => 'audit-subject'])->save();
        $this->mock(GoogleIdentityVerifier::class)->shouldReceive('subject')->once()->with('dummy-credential')->andReturn('audit-subject');
        $login = $this->postJson('/api/login/google', ['credential' => 'dummy-credential'])->assertOk()->assertJsonPath('data.user.guru.id', $teacher->id);
        $this->withToken($login->json('data.token'))->getJson('/api/me')->assertOk()->assertJsonPath('data.user.guru', $login->json('data.user.guru'));
    }

    public function test_read_only_audit_detects_anomalies_without_repairing_records(): void
    {
        $teacher = $this->teacher(); $student = $this->student(); $orphan = $this->account('GURU', 'unlinked');
        $teacher->user->update(['name' => 'Different name']);
        $year = $this->period(); $other = $this->period(false, 'GENAP');
        $class = $this->classroom($year, 'X A'); $second = $this->classroom($year, 'X B'); $outside = $this->classroom($other, 'X C');
        $this->membership($student, $class); $this->membership($student, $second);
        $this->assignment($teacher, $outside, $year);
        $student->user->update(['account_status' => 'inactive']); $student->user->createToken('stale');
        Guru::create(['user_id' => $student->user_id, 'nip' => 'wrong-role', 'nama_lengkap' => 'Conflict']);
        $counts = app(IdentityIntegrationAudit::class)->counts();
        foreach (['academic_accounts_without_profile', 'official_name_mismatch', 'accounts_with_profile_conflict', 'active_periods', 'homeroom_period_mismatch', 'effective_membership_conflicts', 'unusable_accounts_with_tokens'] as $key) $this->assertSame(1, $counts[$key], $key);
        $this->artisan('identity:audit-integration')->expectsOutputToContain('Read-only audit')->assertSuccessful();
        $this->assertDatabaseHas('users', ['id' => $teacher->user_id, 'name' => 'Different name']);
        $this->assertDatabaseHas('users', ['id' => $orphan->id]);
        $this->assertDatabaseCount('anggota_kelas', 2);
        $this->assertSame(1, $student->user->tokens()->count());
    }

    public function test_student_import_preview_rejects_contact_change_from_personal_profile(): void
    {
        $tu = $this->account('TU', 'tu'); $student = $this->student();
        $student->update(['nisn' => '0000012344']);
        $workbook = function () use ($student): \Illuminate\Http\UploadedFile {
            $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $book->getActiveSheet();
            $rows = [['Nama','NIPD','JK','NISN','Tempat Lahir','Tanggal Lahir','Alamat','HP','Rombel Saat Ini'],
                ['Imported name','','L',$student->nisn,'','','Old import address','',''],
                ['New student','','P','0000012345','','','','','']];
            foreach ($rows as $r => $row) foreach ($row as $c => $value) {
                $sheet->setCellValueExplicit(chr(65 + $c).($r + 1), (string) $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
            $path = tempnam(sys_get_temp_dir(), 'siakad-v26-');
            try {
                (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);
                return \Illuminate\Http\UploadedFile::fake()->createWithContent('identity.xlsx', file_get_contents($path));
            } finally {
                $book->disconnectWorksheets(); unlink($path);
            }
        };
        Sanctum::actingAs($tu);
        $preview = $this->post('/api/students/import/preview', ['file' => $workbook()], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.summary.valid_rows', 2);
        $studentUser = $student->user;
        Sanctum::actingAs($studentUser);
        // Contact writes recheck persisted tokens under the account lock.
        // Replace Sanctum's mock token with a real token for this fixture.
        $studentUser->withAccessToken($studentUser->createToken('identity-integration-v26')->accessToken);
        $version = $this->getJson('/api/profile')->assertOk()->json('data.profile_version');
        $this->putJson('/api/profile', ['profile_version' => $version, 'alamat' => 'New contact'])->assertOk();
        Sanctum::actingAs($tu);
        $this->post('/api/students/import', ['file' => $workbook(), 'preview_token' => $preview->json('data.preview_token')], ['Accept' => 'application/json'])->assertConflict();
        $this->assertSame('New contact', $student->fresh()->alamat);
        $this->assertSame('student', $student->fresh()->nama_lengkap);
        $this->assertDatabaseMissing('siswa', ['nisn' => '0000012345']);
        $this->assertDatabaseMissing('users', ['username' => '0000012345']);
    }

}
