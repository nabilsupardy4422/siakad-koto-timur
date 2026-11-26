<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\TahunAkademik;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\Kelas;

class AcademicYearTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithRole(string $roleCode): User
    {
        $roleNames = [
            'TU' => 'Tata Usaha',
            'KEPALA_SEKOLAH' => 'Kepala Sekolah',
            'GURU' => 'Guru',
            'SISWA' => 'Siswa',
        ];

        $role = Role::create([
            'code' => $roleCode,
            'name' => $roleNames[$roleCode],
        ]);

        return User::create([
            'role_id' => $role->id,
            'username' => strtolower($roleCode) . '.test',
            'name' => $roleNames[$roleCode] . ' Test',
            'email' => strtolower($roleCode) . '.test@siakad.test',
            'password' => 'password-test',
            'account_status' => 'active',
        ]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test-token')->plainTextToken;
    }

    private function validPayload(): array
    {
        return [
            'tahun_mulai' => 2026,
            'tahun_selesai' => 2027,
            'semester' => 'GANJIL',
            'is_active' => true,
        ];
    }

    public function test_tu_can_list_academic_years(): void
    {
        $user = $this->createUserWithRole('TU');

        TahunAkademik::create($this->validPayload());

        $response = $this
            ->withToken($this->tokenFor($user))
            ->getJson('/api/academic-years');

        $response
            ->assertOk()
            ->assertJsonPath('data.0.tahun_mulai', 2026)
            ->assertJsonPath('data.0.tahun_selesai', 2027)
            ->assertJsonPath('data.0.semester', 'GANJIL')
            ->assertJsonPath('data.0.is_active', true);
    }

    public function test_kepala_sekolah_can_list_academic_years(): void
    {
        $user = $this->createUserWithRole('KEPALA_SEKOLAH');

        $response = $this
            ->withToken($this->tokenFor($user))
            ->getJson('/api/academic-years');

        $response->assertOk();
    }

    public function test_guru_can_list_academic_years(): void
    {
        $user = $this->createUserWithRole('GURU');

        $response = $this
            ->withToken($this->tokenFor($user))
            ->getJson('/api/academic-years');

        $response->assertOk();
    }

    public function test_siswa_can_list_academic_years(): void
    {
        $user = $this->createUserWithRole('SISWA');

        $response = $this
            ->withToken($this->tokenFor($user))
            ->getJson('/api/academic-years');

        $response->assertOk();
    }

    public function test_tu_can_show_academic_year(): void
    {
        $user = $this->createUserWithRole('TU');

        $academicYear = TahunAkademik::create($this->validPayload());

        $response = $this
            ->withToken($this->tokenFor($user))
            ->getJson("/api/academic-years/{$academicYear->id}");

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $academicYear->id)
            ->assertJsonPath('data.semester', 'GANJIL');
    }

    public function test_guru_can_show_academic_year(): void
    {
        $user = $this->createUserWithRole('GURU');

        $academicYear = TahunAkademik::create($this->validPayload());

        $response = $this
            ->withToken($this->tokenFor($user))
            ->getJson("/api/academic-years/{$academicYear->id}");

        $response->assertOk();
    }

    public function test_tu_can_create_academic_year(): void
    {
        $user = $this->createUserWithRole('TU');

        $response = $this
            ->withToken($this->tokenFor($user))
            ->postJson('/api/academic-years', $this->validPayload());

        $response
            ->assertCreated()
            ->assertJsonPath('data.tahun_mulai', 2026)
            ->assertJsonPath('data.tahun_selesai', 2027)
            ->assertJsonPath('data.semester', 'GANJIL')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('tahun_akademik', [
            'tahun_mulai' => 2026,
            'tahun_selesai' => 2027,
            'semester' => 'GANJIL',
            'is_active' => true,
        ]);
    }

    public function test_non_tu_cannot_create_academic_year(): void
    {
        $user = $this->createUserWithRole('GURU');

        $response = $this
            ->withToken($this->tokenFor($user))
            ->postJson('/api/academic-years', $this->validPayload());

        $response
            ->assertForbidden()
            ->assertJson([
                'message' => 'Anda tidak memiliki akses ke resource ini.',
            ]);
    }

    public function test_tu_can_update_academic_year(): void
    {
        $user = $this->createUserWithRole('TU');
        TahunAkademik::create($this->validPayload());
        $academicYear = TahunAkademik::create(array_merge($this->validPayload(), ['semester' => 'GENAP', 'is_active' => false]));

        $payload = [
            'tahun_mulai' => 2027,
            'tahun_selesai' => 2028,
            'semester' => 'GENAP',
            'is_active' => false,
        ];

        $response = $this
            ->withToken($this->tokenFor($user))
            ->putJson("/api/academic-years/{$academicYear->id}", $payload);

        $response
            ->assertOk()
            ->assertJsonPath('data.tahun_mulai', 2027)
            ->assertJsonPath('data.tahun_selesai', 2028)
            ->assertJsonPath('data.semester', 'GENAP')
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('tahun_akademik', [
            'id' => $academicYear->id,
            'tahun_mulai' => 2027,
            'tahun_selesai' => 2028,
            'semester' => 'GENAP',
            'is_active' => false,
        ]);
    }

    public function test_tu_cannot_deactivate_the_only_active_academic_year(): void
    {
        $user = $this->createUserWithRole('TU');
        $academicYear = TahunAkademik::create($this->validPayload());

        $response = $this
            ->withToken($this->tokenFor($user))
            ->putJson("/api/academic-years/{$academicYear->id}", array_merge(
                $this->validPayload(),
                ['is_active' => false]
            ));

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['is_active']);

        $this->assertDatabaseHas('tahun_akademik', [
            'id' => $academicYear->id,
            'is_active' => true,
        ]);
    }

    public function test_activating_another_academic_year_deactivates_the_previous_one(): void
    {
        $user = $this->createUserWithRole('TU');
        $activeYear = TahunAkademik::create($this->validPayload());
        $nextYear = TahunAkademik::create([
            'tahun_mulai' => 2027,
            'tahun_selesai' => 2028,
            'semester' => 'GENAP',
            'is_active' => false,
        ]);

        $response = $this
            ->withToken($this->tokenFor($user))
            ->putJson("/api/academic-years/{$nextYear->id}", [
                'tahun_mulai' => 2027,
                'tahun_selesai' => 2028,
                'semester' => 'GENAP',
                'is_active' => true,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $nextYear->id)
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('tahun_akademik', [
            'id' => $activeYear->id,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('tahun_akademik', [
            'id' => $nextYear->id,
            'is_active' => true,
        ]);
    }

    public function test_non_tu_cannot_update_academic_year(): void
    {
        $user = $this->createUserWithRole('GURU');

        $academicYear = TahunAkademik::create($this->validPayload());

        $response = $this
            ->withToken($this->tokenFor($user))
            ->putJson(
                "/api/academic-years/{$academicYear->id}",
                $this->validPayload()
            );

        $response
            ->assertForbidden()
            ->assertJson([
                'message' => 'Anda tidak memiliki akses ke resource ini.',
            ]);
    }

    public function test_tu_can_delete_academic_year(): void
    {
        $user = $this->createUserWithRole('TU');

        $academicYear = TahunAkademik::create(array_merge(
            $this->validPayload(),
            ['is_active' => false]
        ));

        $response = $this
            ->withToken($this->tokenFor($user))
            ->deleteJson("/api/academic-years/{$academicYear->id}");

        $response
            ->assertOk()
            ->assertJson([
                'message' => 'Tahun akademik berhasil dihapus.',
            ]);

        $this->assertDatabaseMissing('tahun_akademik', [
            'id' => $academicYear->id,
        ]);
    }

    public function test_non_tu_cannot_delete_academic_year(): void
    {
        $user = $this->createUserWithRole('GURU');

        $academicYear = TahunAkademik::create($this->validPayload());

        $response = $this
            ->withToken($this->tokenFor($user))
            ->deleteJson("/api/academic-years/{$academicYear->id}");

        $response
            ->assertForbidden()
            ->assertJson([
                'message' => 'Anda tidak memiliki akses ke resource ini.',
            ]);
    }

    public function test_tu_cannot_delete_active_academic_year(): void
    {
        $user = $this->createUserWithRole('TU');
        $academicYear = TahunAkademik::create($this->validPayload());

        $response = $this
            ->withToken($this->tokenFor($user))
            ->deleteJson("/api/academic-years/{$academicYear->id}");

        $response
            ->assertUnprocessable()
            ->assertJson([
                'message' => 'Tahun akademik aktif tidak dapat dihapus. Aktifkan periode pengganti terlebih dahulu.',
            ]);

        $this->assertDatabaseHas('tahun_akademik', [
            'id' => $academicYear->id,
            'is_active' => true,
        ]);
    }

    public function test_tu_cannot_delete_academic_year_used_by_class(): void
    {
        $user = $this->createUserWithRole('TU');

        $academicYear = TahunAkademik::create(array_merge(
            $this->validPayload(),
            ['is_active' => false]
        ));

        $class = Kelas::create([
            'tahun_akademik_id' => $academicYear->id,
            'nama' => 'IPA 1',
            'tingkat' => 10,
        ]);

        $response = $this
            ->withToken($this->tokenFor($user))
            ->deleteJson("/api/academic-years/{$academicYear->id}");

        $response
            ->assertUnprocessable()
            ->assertJson([
                'message' => 'Tahun akademik tidak dapat dihapus karena masih digunakan oleh data kelas atau data akademik lainnya.',
            ]);

        $this->assertDatabaseHas('tahun_akademik', [
            'id' => $academicYear->id,
        ]);

        $this->assertDatabaseHas('kelas', [
            'id' => $class->id,
            'tahun_akademik_id' => $academicYear->id,
            'nama' => 'IPA 1',
            'tingkat' => 10,
        ]);
    }

    public function test_unauthenticated_user_cannot_access_academic_years(): void
    {
        $response = $this->getJson('/api/academic-years');

        $response
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_academic_year_validation_failure_returns_422(): void
    {
        $user = $this->createUserWithRole('TU');

        $response = $this
            ->withToken($this->tokenFor($user))
            ->postJson('/api/academic-years', [
                'tahun_mulai' => 'abc',
                'tahun_selesai' => null,
                'semester' => 'SALAH',
                'is_active' => 'invalid',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonStructure([
                'message',
                'errors' => [
                    'tahun_mulai',
                    'tahun_selesai',
                    'semester',
                    'is_active',
                ],
            ]);
    }

    public function test_first_period_is_activated_even_when_create_requests_inactive(): void
    {
        $this->withToken($this->tokenFor($this->createUserWithRole('TU')))
            ->postJson('/api/academic-years', array_merge($this->validPayload(), ['is_active' => false]))
            ->assertCreated()->assertJsonPath('data.is_active', true)->assertJsonPath('data.identity_locked', true);
    }

    public function test_create_cannot_replace_active_period(): void
    {
        $active = TahunAkademik::create($this->validPayload());
        $this->withToken($this->tokenFor($this->createUserWithRole('TU')));
        $next = array_merge($this->validPayload(), ['semester' => 'GENAP']);
        $this->postJson('/api/academic-years', $next)->assertUnprocessable()->assertJsonValidationErrors('is_active');
        $this->postJson('/api/academic-years', array_merge($next, ['is_active' => false]))
            ->assertCreated()->assertJsonPath('data.is_active', false)->assertJsonPath('data.identity_locked', false);
        $this->assertTrue($active->fresh()->is_active);
    }

    public function test_years_must_be_consecutive_on_create_and_edit(): void
    {
        $this->withToken($this->tokenFor($this->createUserWithRole('TU')));
        $this->postJson('/api/academic-years', array_merge($this->validPayload(), ['tahun_selesai' => 2029]))
            ->assertUnprocessable()->assertJsonValidationErrors('tahun_selesai');
        $active = TahunAkademik::create($this->validPayload());
        $next = TahunAkademik::create(array_merge($this->validPayload(), ['semester' => 'GENAP', 'is_active' => false]));
        $this->putJson("/api/academic-years/{$next->id}", ['tahun_mulai' => 2028, 'tahun_selesai' => 2028, 'semester' => 'GENAP', 'is_active' => false])
            ->assertUnprocessable()->assertJsonValidationErrors('tahun_selesai');
        $this->assertTrue($active->fresh()->is_active);
    }

    public function test_active_and_used_period_identity_is_locked_but_activation_preserves_relations(): void
    {
        $this->withToken($this->tokenFor($this->createUserWithRole('TU')));
        $active = TahunAkademik::create($this->validPayload());
        $historical = TahunAkademik::create(array_merge($this->validPayload(), ['semester' => 'GENAP', 'is_active' => false]));
        $class = Kelas::create(['tahun_akademik_id' => $historical->id, 'nama' => 'IPA 1', 'tingkat' => 10]);
        $this->getJson("/api/academic-years/{$historical->id}")->assertOk()->assertJsonPath('data.identity_locked', true);
        foreach ([$active, $historical] as $period) {
            $this->putJson("/api/academic-years/{$period->id}", ['tahun_mulai' => 2028, 'tahun_selesai' => 2029, 'semester' => 'GANJIL', 'is_active' => $period->is_active])
                ->assertUnprocessable()->assertJsonValidationErrors('academic_year');
        }
        $this->putJson("/api/academic-years/{$historical->id}", array_merge($this->validPayload(), ['semester' => 'GENAP']))->assertOk();
        $this->assertFalse($active->fresh()->is_active);
        $this->assertTrue($historical->fresh()->is_active);
        $this->assertEquals($historical->id, $class->fresh()->tahun_akademik_id);
        $this->assertEquals('GENAP', $historical->fresh()->semester);
    }

    public function test_identity_edit_and_activation_cannot_be_combined(): void
    {
        $this->withToken($this->tokenFor($this->createUserWithRole('TU')));
        $active = TahunAkademik::create($this->validPayload());
        $next = TahunAkademik::create(array_merge($this->validPayload(), ['semester' => 'GENAP', 'is_active' => false]));
        $this->putJson("/api/academic-years/{$next->id}", ['tahun_mulai' => 2028, 'tahun_selesai' => 2029, 'semester' => 'GENAP', 'is_active' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('is_active');
        $this->assertTrue($active->fresh()->is_active);
        $this->assertEquals(2026, $next->fresh()->tahun_mulai);
    }

    public function test_existing_period_without_active_context_requires_explicit_recovery(): void
    {
        $this->withToken($this->tokenFor($this->createUserWithRole('TU')));
        $existing = TahunAkademik::create(array_merge($this->validPayload(), ['is_active' => false]));
        $this->postJson('/api/academic-years', array_merge($this->validPayload(), ['semester' => 'GENAP']))->assertUnprocessable();
        $this->putJson("/api/academic-years/{$existing->id}", $this->validPayload())->assertOk();
        $this->assertTrue($existing->fresh()->is_active);
    }

    public function test_non_tu_does_not_receive_administrative_lock_metadata(): void
    {
        $this->withToken($this->tokenFor($this->createUserWithRole('SISWA')));
        $period = TahunAkademik::create($this->validPayload());
        $this->getJson('/api/academic-years')->assertOk()->assertJsonMissingPath('data.0.identity_locked');
        $this->getJson("/api/academic-years/{$period->id}")->assertOk()->assertJsonMissingPath('data.identity_locked');
        $this->postJson('/api/academic-years/import/preview', [])->assertForbidden();
        $this->postJson('/api/academic-years/import', [])->assertForbidden();
        $this->getJson('/api/academic-years/template')->assertForbidden();
    }

    private function excelFile(array $rows): \Illuminate\Http\UploadedFile
    {
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $book->getActiveSheet()->fromArray(array_merge([['tahun_mulai','tahun_selesai','semester','is_active']], $rows), null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'academic-year-');
        try {
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);
            return \Illuminate\Http\UploadedFile::fake()->createWithContent('periods.xlsx', file_get_contents($path));
        } finally {
            $book->disconnectWorksheets();
            unlink($path);
        }
    }

    public function test_excel_preview_rejects_active_replacement_invalid_year_and_formula_without_writing(): void
    {
        $this->withToken($this->tokenFor($this->createUserWithRole('TU')));
        $active = TahunAkademik::create($this->validPayload());
        $rows = [[2027, 2028, 'GANJIL', 1], [2028, 2030, 'GENAP', 0], [2029, '=2029+1', 'GENAP', 0], [2030, 2031, 'GANJIL', 0]];
        $this->postJson('/api/academic-years/import/preview', ['file' => $this->excelFile($rows)])
            ->assertOk()->assertJsonPath('data.summary.invalid_rows', 3)->assertJsonPath('data.summary.valid_rows', 1);
        $this->postJson('/api/academic-years/import', ['file' => $this->excelFile($rows)])->assertUnprocessable();
        $this->assertDatabaseCount('tahun_akademik', 1);
        $this->assertTrue($active->fresh()->is_active);
    }

    public function test_excel_bootstrap_requires_one_active_then_import_is_inactive(): void
    {
        $this->withToken($this->tokenFor($this->createUserWithRole('TU')));
        $this->postJson('/api/academic-years/import/preview', ['file' => $this->excelFile([[2026,2027,'GANJIL',0]])])
            ->assertOk()->assertJsonPath('data.summary.invalid_rows', 1);
        $this->postJson('/api/academic-years/import', ['file' => $this->excelFile([[2026,2027,'GANJIL',1],[2026,2027,'GENAP',0]])])
            ->assertOk()->assertJsonPath('data.imported_rows', 2);
        $this->assertDatabaseCount('tahun_akademik', 2);
        $this->assertSame(1, TahunAkademik::where('is_active', true)->count());
        $this->postJson('/api/academic-years/import', ['file' => $this->excelFile([[2027,2028,'GANJIL',0]])])->assertOk();
        $this->assertDatabaseHas('tahun_akademik', ['tahun_mulai'=>2027,'is_active'=>false]);
        $this->assertSame(1, TahunAkademik::where('is_active', true)->count());
    }

    public function test_excel_revalidates_database_after_preview(): void
    {
        $this->withToken($this->tokenFor($this->createUserWithRole('TU')));
        TahunAkademik::create($this->validPayload());
        $rows = [[2027,2028,'GANJIL',0],[2027,2028,'GENAP',0]];
        $this->postJson('/api/academic-years/import/preview', ['file'=>$this->excelFile($rows)])->assertOk()->assertJsonPath('data.summary.valid_rows',2);
        TahunAkademik::create(['tahun_mulai'=>2027,'tahun_selesai'=>2028,'semester'=>'GANJIL','is_active'=>false]);
        $this->postJson('/api/academic-years/import', ['file'=>$this->excelFile($rows)])->assertUnprocessable();
        $this->assertDatabaseCount('tahun_akademik',2);
    }

    public function test_excel_template_preserves_headers_and_safe_active_default(): void
    {
        $this->withToken($this->tokenFor($this->createUserWithRole('TU')));
        TahunAkademik::create($this->validPayload());
        $response = $this->get('/api/academic-years/template')->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'academic-template-');
        try {
            file_put_contents($path, $response->streamedContent());
            $book = (new \PhpOffice\PhpSpreadsheet\Reader\Xlsx())->load($path);
            $this->assertSame([['tahun_mulai','tahun_selesai','semester','is_active']], $book->getActiveSheet()->rangeToArray('A1:D1'));
            $this->assertEquals(0, $book->getActiveSheet()->getCell('D2')->getValue());
            $book->disconnectWorksheets();
        } finally { unlink($path); }
    }

    public function test_activation_with_stale_confirmation_returns_conflict(): void
    {
        $this->withToken($this->tokenFor($this->createUserWithRole('TU')));
        $active = TahunAkademik::create($this->validPayload());
        $target = TahunAkademik::create(array_merge($this->validPayload(), ['semester' => 'GENAP', 'is_active' => false]));
        $this->putJson("/api/academic-years/{$target->id}", array_merge($this->validPayload(), ['semester' => 'GENAP', 'expected_active_ids' => []]))->assertStatus(409);
        $this->assertTrue($active->fresh()->is_active);
        $this->assertFalse($target->fresh()->is_active);
        $this->putJson("/api/academic-years/{$target->id}", array_merge($this->validPayload(), ['semester' => 'GENAP', 'expected_active_ids' => [$active->id]]))->assertOk();
        $this->assertTrue($target->fresh()->is_active);
    }
}
