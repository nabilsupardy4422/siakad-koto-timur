<?php

namespace Tests\Feature;

use App\Models\Guru;
use App\Models\JadwalPelajaran;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\Role;
use App\Models\TahunAkademik;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubjectDirectoryV3Test extends TestCase
{
    use RefreshDatabase;

    private function loginAs(string $code = 'TU'): User
    {
        $role = Role::firstOrCreate(['code' => $code], ['name' => $code]);
        $user = User::create(['role_id' => $role->id, 'username' => uniqid('user'), 'name' => $code, 'password' => 'password-test', 'account_status' => 'active']);
        Sanctum::actingAs($user);
        return $user;
    }

    private function subject(string $code, string $name, mixed $kkm = null): Mapel
    {
        return Mapel::create(['kode' => $code, 'nama' => $name, 'kkm' => $kkm]);
    }

    private function schedule(Mapel $subject): JadwalPelajaran
    {
        $teacher = $this->loginAs('GURU');
        $guru = Guru::create(['user_id' => $teacher->id, 'nip' => 'NIP-TEST', 'nama_lengkap' => 'Guru Test']);
        $year = TahunAkademik::create(['tahun_mulai' => 2025, 'tahun_selesai' => 2026, 'semester' => 'GENAP', 'is_active' => false]);
        $class = Kelas::create(['tahun_akademik_id' => $year->id, 'nama' => 'XI Test', 'tingkat' => 11]);
        $schedule = JadwalPelajaran::create(['tahun_akademik_id' => $year->id, 'kelas_id' => $class->id, 'guru_id' => $guru->id, 'mapel_id' => $subject->id, 'hari' => 'SENIN', 'jam_mulai' => '07:00', 'jam_selesai' => '08:00']);
        $this->loginAs();
        return $schedule;
    }

    public function test_search_and_sort_run_before_pagination_with_literal_wildcards(): void
    {
        $this->loginAs();
        $this->subject('B', 'Zoologi');
        $this->subject('A', 'Biologi');
        $this->subject('X_1', 'Biologi Lanjutan');
        $this->getJson('/api/subjects?search=biologi&page_size=1&sort_order=desc')->assertOk()->assertJsonPath('data.0.kode', 'X_1')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/subjects?search=_&page_size=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.kode', 'X_1');
        $this->getJson('/api/subjects?page_size=1&page=2&sort_order=asc')->assertOk()->assertJsonPath('data.0.kode', 'X_1');
    }

    public function test_zero_null_and_legacy_invalid_are_distinguished(): void
    {
        $this->loginAs();
        $this->subject('N', 'Null');
        $this->subject('Z', 'Zero', 0);
        $this->subject('L', 'Legacy', 130);
        $this->getJson('/api/subjects?kkm_state=set')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.kkm', '0.00')->assertJsonPath('meta.summary.total', 3)->assertJsonPath('meta.summary.unset', 1)->assertJsonPath('meta.summary.invalid', 1);
        $this->getJson('/api/subjects?kkm_state=unset')->assertOk()->assertJsonPath('data.0.kkm', null);
        $this->getJson('/api/subjects?kkm_state=invalid')->assertOk()->assertJsonPath('data.0.kkm', '130.00');
    }

    public function test_kkm_validation_and_case_insensitive_code(): void
    {
        $this->loginAs();
        foreach ([-1, 101, '75.125', 'abc'] as $kkm) {
            $this->postJson('/api/subjects', ['kode' => 'KKA', 'nama' => 'Koding', 'kkm' => $kkm])->assertUnprocessable()->assertJsonValidationErrors('kkm');
        }
        $this->postJson('/api/subjects', ['kode' => '  00kka ', 'nama' => 'Koding', 'kkm' => '75.25'])->assertCreated()->assertJsonPath('data.kode', '00KKA')->assertJsonPath('data.kkm', '75.25');
        $this->postJson('/api/subjects', ['kode' => '00kka', 'nama' => 'Koding lain'])->assertUnprocessable()->assertJsonValidationErrors('kode');
        $this->assertDatabaseCount('mapel', 1);
    }

    public function test_history_protects_all_fields_and_deletion_even_in_inactive_period(): void
    {
        $subject = $this->subject('BIO', 'Biologi', 75);
        $schedule = $this->schedule($subject);
        $version = $this->getJson("/api/subjects/{$subject->id}")->assertOk()->assertJsonPath('data.schedule_count', 1)->assertJsonPath('data.usage_context.periods', 1)->assertJsonPath('data.usage_context.classes', 1)->assertJsonPath('data.usage_context.teachers', 1)->json('data.version');
        foreach ([['kode' => 'BIO-L'], ['nama' => 'Biologi Lanjutan'], ['kkm' => 80]] as $change) {
            $this->putJson("/api/subjects/{$subject->id}", array_merge(['kode' => 'BIO', 'nama' => 'Biologi', 'kkm' => 75, 'version' => $version], $change))->assertConflict();
        }
        $this->deleteJson("/api/subjects/{$subject->id}", ['version' => $version])->assertConflict();
        $this->assertDatabaseHas('mapel', ['id' => $subject->id, 'kode' => 'BIO', 'nama' => 'Biologi', 'kkm' => 75]);
        $this->assertDatabaseHas('jadwal_pelajaran', ['id' => $schedule->id, 'mapel_id' => $subject->id]);
        $this->getJson('/api/subjects?usage=used')->assertOk()->assertJsonPath('meta.summary.used', 1)->assertJsonPath('meta.total', 1);
        $this->getJson('/api/subjects?usage=unused')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_stale_version_rejects_update_and_delete(): void
    {
        $this->loginAs();
        $subject = $this->subject('MTK', 'Matematika');
        $version = $this->getJson("/api/subjects/{$subject->id}")->assertOk()->json('data.version');
        $this->putJson("/api/subjects/{$subject->id}", ['kode' => 'MTK', 'nama' => 'Matematika Baru', 'kkm' => 0, 'version' => $version])->assertOk()->assertJsonPath('data.kkm', '0.00');
        $this->putJson("/api/subjects/{$subject->id}", ['kode' => 'MTK', 'nama' => 'Stale', 'version' => $version])->assertConflict();
        $this->deleteJson("/api/subjects/{$subject->id}", ['version' => $version])->assertConflict();
        $this->assertDatabaseHas('mapel', ['id' => $subject->id, 'nama' => 'Matematika Baru']);
    }

    public function test_usage_metadata_is_tu_only_and_query_limits_are_validated(): void
    {
        $subject = $this->subject('BK', 'Bimbingan dan Konseling');
        foreach (['KEPALA_SEKOLAH', 'GURU', 'SISWA'] as $role) {
            $this->loginAs($role);
            $this->getJson('/api/subjects')->assertOk()->assertJsonMissingPath('meta.summary')->assertJsonMissingPath('data.0.schedule_count')->assertJsonMissingPath('data.0.version');
            $this->getJson("/api/subjects/{$subject->id}")->assertOk()->assertJsonMissingPath('data.usage_context');
            $this->getJson('/api/subjects?usage=used')->assertForbidden();
        }
        $this->loginAs();
        foreach (['page_size=101', 'per_page=0', 'page=-1', 'sort_order=bad', 'kkm_state=bad'] as $query) {
            $this->getJson('/api/subjects?'.$query)->assertUnprocessable();
        }
    }
}
