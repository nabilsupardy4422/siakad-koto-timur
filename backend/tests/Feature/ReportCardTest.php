<?php

namespace Tests\Feature;

use App\Models\Rapor;
use App\Models\Role;
use App\Models\Siswa;
use App\Models\TahunAkademik;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReportCardTest extends TestCase
{
    use RefreshDatabase;

    private function createRole(string $code): Role
    {
        return Role::create([
            'code' => $code,
            'name' => match ($code) {
                'TU' => 'Tata Usaha',
                'KEPALA_SEKOLAH' => 'Kepala Sekolah',
                'GURU' => 'Guru',
                'SISWA' => 'Siswa',
                default => $code,
            },
        ]);
    }

    private function createUser(
        string $roleCode,
        string $suffix = ''
    ): User {
        $role = Role::firstOrCreate(
            ['code' => $roleCode],
            [
                'name' => $roleCode,
            ]
        );

        return User::create([
            'role_id' => $role->id,
            'username' => strtolower($roleCode) . $suffix,
            'name' => $roleCode . ' Test' . $suffix,
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

    private function createStudent(
        string $suffix = ''
    ): array {
        $user = $this->createUser(
            'SISWA',
            $suffix
        );

        $student = Siswa::create([
            'user_id' => $user->id,
            'nis' => 'NIS' . $user->id,
            'nisn' => 'NISN' . $user->id,
            'nama_lengkap' => 'Siswa Test' . $suffix,
        ]);

        return [
            'user' => $user,
            'student' => $student,
        ];
    }

    private function createReportCard(
        Siswa $student,
        TahunAkademik $academicYear,
        User $uploadedBy,
        string $type = 'MID_SEMESTER'
    ): Rapor {
        Storage::disk('local')->put(
            'report-cards/test/report-card.pdf',
            'dummy report card content'
        );

        return Rapor::create([
            'siswa_id' => $student->id,
            'tahun_akademik_id' => $academicYear->id,
            'jenis_rapor' => $type,
            'file_path' => 'report-cards/test/report-card.pdf',
            'file_name' => 'report-card.pdf',
            'file_mime_type' => 'application/pdf',
            'file_size' => 27,
            'uploaded_by' => $uploadedBy->id,
            'uploaded_at' => now(),
        ]);
    }

    public function test_guest_cannot_access_report_cards(): void
    {
        $response = $this->getJson(
            '/api/report-cards'
        );

        $response->assertUnauthorized();
    }

    public function test_tu_can_upload_report_card(): void
    {
        Storage::fake('local');

        $tu = $this->createUser('TU');
        $studentData = $this->createStudent();
        $academicYear = $this->createAcademicYear();

        $class = \App\Models\Kelas::create(['tahun_akademik_id'=>$academicYear->id,'nama'=>'IPA 1','tingkat'=>10]);
        \App\Models\AnggotaKelas::create(['kelas_id'=>$class->id,'siswa_id'=>$studentData['student']->id,'tanggal_mulai'=>now('Asia/Jakarta')->toDateString()]);
        $file = $this->realPdf();

        $response = $this
            ->actingAs($tu)
            ->post(
                '/api/report-cards',
                [
                    'class_id' => $class->id,
                    'siswa_id' => $studentData['student']->id,
                    'tahun_akademik_id' => $academicYear->id,
                    'jenis_rapor' => 'MID_SEMESTER',
                    'file' => $file,
                ]
            );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.student.id',
                $studentData['student']->id
            )
            ->assertJsonPath(
                'data.academic_year.id',
                $academicYear->id
            )
            ->assertJsonPath(
                'data.type',
                'MID_SEMESTER'
            )
            ->assertJsonPath(
                'data.file.name',
                'rapor.pdf'
            );

        $this->assertDatabaseHas(
            'rapor',
            [
                'siswa_id' => $studentData['student']->id,
                'tahun_akademik_id' => $academicYear->id,
                'jenis_rapor' => 'MID_SEMESTER',
                'uploaded_by' => $tu->id,
            ]
        );
    }

    public function test_non_tu_cannot_upload_report_card(): void
    {
        Storage::fake('local');

        $studentData = $this->createStudent();
        $academicYear = $this->createAcademicYear();

        $file = UploadedFile::fake()->create(
            'rapor.pdf',
            200,
            'application/pdf'
        );

        $response = $this
            ->actingAs($studentData['user'])
            ->post(
                '/api/report-cards',
                [
                    'siswa_id' => $studentData['student']->id,
                    'tahun_akademik_id' => $academicYear->id,
                    'jenis_rapor' => 'MID_SEMESTER',
                    'file' => $file,
                ]
            );

        $response->assertForbidden();
    }

    public function test_upload_requires_required_fields(): void
    {
        Storage::fake('local');

        $tu = $this->createUser('TU');

        $response = $this
            ->actingAs($tu)
            ->post(
                '/api/report-cards',
                []
            );

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'siswa_id',
                'tahun_akademik_id',
                'jenis_rapor',
                'file',
            ]);
    }

    public function test_upload_rejects_unsupported_file_type(): void
    {
        Storage::fake('local');

        $tu = $this->createUser('TU');
        $studentData = $this->createStudent();
        $academicYear = $this->createAcademicYear();

        $class = \App\Models\Kelas::create(['tahun_akademik_id'=>$academicYear->id,'nama'=>'IPA 1','tingkat'=>10]);
        \App\Models\AnggotaKelas::create(['kelas_id'=>$class->id,'siswa_id'=>$studentData['student']->id,'tanggal_mulai'=>now('Asia/Jakarta')->toDateString()]);
        $file = UploadedFile::fake()->create(
            'rapor.exe',
            200,
            'application/octet-stream'
        );

        $response = $this
            ->actingAs($tu)
            ->post(
                '/api/report-cards',
                [
                    'class_id'=>$class->id, 'siswa_id' => $studentData['student']->id,
                    'tahun_akademik_id' => $academicYear->id,
                    'jenis_rapor' => 'MID_SEMESTER',
                    'file' => $file,
                ]
            );

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_upload_rejects_unknown_student(): void
    {
        Storage::fake('local');

        $tu = $this->createUser('TU');
        $academicYear = $this->createAcademicYear();

        $file = UploadedFile::fake()->create(
            'rapor.pdf',
            200,
            'application/pdf'
        );

        $response = $this
            ->actingAs($tu)
            ->post(
                '/api/report-cards',
                [
                    'siswa_id' => 999999,
                    'tahun_akademik_id' => $academicYear->id,
                    'jenis_rapor' => 'MID_SEMESTER',
                    'file' => $file,
                ]
            );

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors('siswa_id');
    }

    public function test_upload_rejects_unknown_academic_year(): void
    {
        Storage::fake('local');

        $tu = $this->createUser('TU');
        $studentData = $this->createStudent();

        $file = UploadedFile::fake()->create(
            'rapor.pdf',
            200,
            'application/pdf'
        );

        $response = $this
            ->actingAs($tu)
            ->post(
                '/api/report-cards',
                [
                    'siswa_id' => $studentData['student']->id,
                    'tahun_akademik_id' => 999999,
                    'jenis_rapor' => 'MID_SEMESTER',
                    'file' => $file,
                ]
            );

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(
                'tahun_akademik_id'
            );
    }

    public function test_student_can_view_only_own_report_cards(): void
    {
        Storage::fake('local');

        $tu = $this->createUser('TU');

        $studentOne = $this->createStudent('One');
        $studentTwo = $this->createStudent('Two');

        $academicYear = $this->createAcademicYear();

        $reportCardOne = $this->createReportCard(
            $studentOne['student'],
            $academicYear,
            $tu
        );

        $reportCardTwo = $this->createReportCard(
            $studentTwo['student'],
            $academicYear,
            $tu,
            'AKHIR_SEMESTER'
        );

        $response = $this
            ->actingAs($studentOne['user'])
            ->getJson('/api/report-cards');

        $response
            ->assertOk()
            ->assertJsonCount(
                1,
                'data'
            )
            ->assertJsonPath(
                'data.0.id',
                $reportCardOne->id
            )
            ->assertJsonMissing([
                'id' => $reportCardTwo->id,
            ]);
    }

    public function test_student_can_view_own_report_card(): void
    {
        Storage::fake('local');

        $tu = $this->createUser('TU');

        $studentData = $this->createStudent();
        $academicYear = $this->createAcademicYear();

        $reportCard = $this->createReportCard(
            $studentData['student'],
            $academicYear,
            $tu
        );

        $response = $this
            ->actingAs($studentData['user'])
            ->getJson(
                '/api/report-cards/'
                . $reportCard->id
            );

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.id',
                $reportCard->id
            )
            ->assertJsonPath(
                'data.student.id',
                $studentData['student']->id
            )
            ->assertJsonPath(
                'data.academic_year.id',
                $academicYear->id
            );
    }

    public function test_student_cannot_view_another_students_report_card(): void
    {
        Storage::fake('local');

        $tu = $this->createUser('TU');

        $studentOne = $this->createStudent('One');
        $studentTwo = $this->createStudent('Two');

        $academicYear = $this->createAcademicYear();

        $reportCard = $this->createReportCard(
            $studentTwo['student'],
            $academicYear,
            $tu
        );

        $response = $this
            ->actingAs($studentOne['user'])
            ->getJson(
                '/api/report-cards/'
                . $reportCard->id
            );

        $response->assertForbidden();
    }

    public function test_student_can_download_own_report_card(): void
    {
        Storage::fake('local');

        $tu = $this->createUser('TU');

        $studentData = $this->createStudent();
        $academicYear = $this->createAcademicYear();

        $reportCard = $this->createReportCard(
            $studentData['student'],
            $academicYear,
            $tu
        );

        $response = $this
            ->actingAs($studentData['user'])
            ->get(
                '/api/report-cards/'
                . $reportCard->id
                . '/download'
            );

        $response
            ->assertOk()
            ->assertHeader(
                'Content-Disposition'
            );
    }

    public function test_student_cannot_download_another_students_report_card(): void
    {
        Storage::fake('local');

        $tu = $this->createUser('TU');

        $studentOne = $this->createStudent('One');
        $studentTwo = $this->createStudent('Two');

        $academicYear = $this->createAcademicYear();

        $reportCard = $this->createReportCard(
            $studentTwo['student'],
            $academicYear,
            $tu
        );

        $response = $this
            ->actingAs($studentOne['user'])
            ->get(
                '/api/report-cards/'
                . $reportCard->id
                . '/download'
            );

        $response->assertForbidden();
    }

    public function test_tu_can_view_report_cards(): void
    {
        Storage::fake('local');

        $tu = $this->createUser('TU');

        $studentData = $this->createStudent();
        $academicYear = $this->createAcademicYear();

        $reportCard = $this->createReportCard(
            $studentData['student'],
            $academicYear,
            $tu
        );

        $response = $this
            ->actingAs($tu)
            ->getJson('/api/report-cards');

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.0.id',
                $reportCard->id
            )
            ->assertJsonPath(
                'data.0.student.id',
                $studentData['student']->id
            );
    }

    public function test_report_cards_support_filters_and_pagination(): void
    {
        Storage::fake('local');

        $tu = $this->createUser('TU');

        $studentOne = $this->createStudent('One');
        $studentTwo = $this->createStudent('Two');

        $academicYearOne = $this->createAcademicYear('Ganjil');
        $academicYearTwo = TahunAkademik::create([
            'tahun_mulai' => 2027,
            'tahun_selesai' => 2028,
            'semester' => 'Genap',
            'aktif' => false,
        ]);

        $this->createReportCard(
            $studentOne['student'],
            $academicYearOne,
            $tu,
            'MID_SEMESTER'
        );

        $this->createReportCard(
            $studentTwo['student'],
            $academicYearOne,
            $tu,
            'AKHIR_SEMESTER'
        );

        $targetReportCard = $this->createReportCard(
            $studentOne['student'],
            $academicYearTwo,
            $tu,
            'AKHIR_SEMESTER'
        );

        $response = $this
            ->actingAs($tu)
            ->getJson(
                '/api/report-cards'
                . '?academic_year_id='
                . $academicYearTwo->id
                . '&semester=Genap'
                . '&student_id='
                . $studentOne['student']->id
                . '&type=AKHIR_SEMESTER'
                . '&per_page=1'
            );

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.0.id',
                $targetReportCard->id
            )
            ->assertJsonPath(
                'meta.per_page',
                1
            )
            ->assertJsonPath(
                'meta.total',
                1
            );
    }

    public function test_tu_can_download_report_card(): void
    {
        Storage::fake('local');

        $tu = $this->createUser('TU');

        $studentData = $this->createStudent();
        $academicYear = $this->createAcademicYear();

        $reportCard = $this->createReportCard(
            $studentData['student'],
            $academicYear,
            $tu
        );

        $response = $this
            ->actingAs($tu)
            ->get(
                '/api/report-cards/'
                . $reportCard->id
                . '/download'
            );

        $response
            ->assertOk()
            ->assertHeader(
                'Content-Disposition'
            );
    }

    public function test_download_returns_404_when_file_is_missing(): void
    {
        Storage::fake('local');

        $tu = $this->createUser('TU');

        $studentData = $this->createStudent();
        $academicYear = $this->createAcademicYear();

        $reportCard = Rapor::create([
            'siswa_id' => $studentData['student']->id,
            'tahun_akademik_id' => $academicYear->id,
            'jenis_rapor' => 'MID_SEMESTER',
            'file_path' => 'report-cards/missing/report-card.pdf',
            'file_name' => 'report-card.pdf',
            'file_mime_type' => 'application/pdf',
            'file_size' => 100,
            'uploaded_by' => $tu->id,
            'uploaded_at' => now(),
        ]);

        $response = $this
            ->actingAs($tu)
            ->get(
                '/api/report-cards/'
                . $reportCard->id
                . '/download'
            );

        $response->assertNotFound();
    }

    public function test_tu_can_revoke_report_card_without_deleting_history(): void
    {
        Storage::fake('local');

        $tu = $this->createUser('TU');

        $studentData = $this->createStudent();
        $academicYear = $this->createAcademicYear();

        $reportCard = $this->createReportCard(
            $studentData['student'],
            $academicYear,
            $tu
        );

        $this->assertDatabaseHas(
            'rapor',
            [
                'id' => $reportCard->id,
            ]
        );

        $response = $this
            ->actingAs($tu)
            ->deleteJson(
                '/api/report-cards/'
                . $reportCard->id, ['version'=>1,'reason'=>'Perbaikan rapor']
            );

        $response
            ->assertOk()
            ->assertJson([
                'message' => 'Distribusi rapor dicabut; arsip tetap tersimpan.',
            ]);

        $this->assertDatabaseHas(
            'rapor',
            [
                'id' => $reportCard->id, 'distribution_status'=>'revoked',
            ]
        );
        Storage::disk('local')->assertExists($reportCard->file_path);
        $this->assertDatabaseHas('rapor_events',['rapor_id'=>$reportCard->id,'action'=>'revoke','actor_id'=>$tu->id]);
        $this->actingAs($studentData['user'])->getJson('/api/report-cards/'.$reportCard->id)->assertForbidden();
    }

    public function test_student_cannot_delete_report_card(): void
    {
        Storage::fake('local');

        $tu = $this->createUser('TU');

        $studentData = $this->createStudent();
        $academicYear = $this->createAcademicYear();

        $reportCard = $this->createReportCard(
            $studentData['student'],
            $academicYear,
            $tu
        );

        $response = $this
            ->actingAs($studentData['user'])
            ->deleteJson(
                '/api/report-cards/'
                . $reportCard->id
            );

        $response->assertForbidden();

        $this->assertDatabaseHas(
            'rapor',
            [
                'id' => $reportCard->id,
            ]
        );
    }

    public function test_show_unknown_report_card_returns_404(): void
    {
        $tu = $this->createUser('TU');

        $response = $this
            ->actingAs($tu)
            ->getJson(
                '/api/report-cards/999999'
            );

        $response->assertNotFound();
    }

    public function test_delete_unknown_report_card_returns_404(): void
    {
        $tu = $this->createUser('TU');

        $response = $this
            ->actingAs($tu)
            ->deleteJson(
                '/api/report-cards/999999'
            );

        $response->assertNotFound();
    }
    private function realPdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('rapor.pdf', \Barryvdh\DomPDF\Facade\Pdf::loadHTML('<html><body>Rapor uji</body></html>')->output());
    }

    private function distributionScenario(): array
    {
        Storage::fake('local');$tu=$this->createUser('TU');$studentData=$this->createStudent();$year=$this->createAcademicYear('GANJIL');
        $class=\App\Models\Kelas::create(['tahun_akademik_id'=>$year->id,'nama'=>'IPA 1','tingkat'=>10]);
        \App\Models\AnggotaKelas::create(['kelas_id'=>$class->id,'siswa_id'=>$studentData['student']->id,'tanggal_mulai'=>now('Asia/Jakarta')->toDateString()]);
        $payload=['class_id'=>$class->id,'siswa_id'=>$studentData['student']->id,'tahun_akademik_id'=>$year->id,'jenis_rapor'=>'AKHIR_SEMESTER'];
        return [$tu,$studentData,$year,$class,$payload];
    }
    public function test_revision_stale_revoke_and_reissue_keep_all_files(): void
    {
        [$tu,$s,$year,$class,$payload]=$this->distributionScenario();
        $this->actingAs($tu)->post('/api/report-cards',$payload+['file'=>$this->realPdf()])->assertCreated();
        $r=Rapor::firstOrFail();$old=$r->file_path;$url='/api/report-cards/'.$r->id;
        $this->post('/api/report-cards',$payload+['file'=>$this->realPdf()])->assertConflict();
        $this->post($url.'/revision',['version'=>1,'action'=>'revise','reason'=>'Perbaikan','file'=>$this->realPdf()])->assertOk()->assertJsonPath('data.version',2);
        $second=$r->fresh()->file_path;
        Storage::disk('local')->assertExists($old);Storage::disk('local')->assertExists($second);
        $this->post($url.'/revision',['version'=>1,'action'=>'revise','reason'=>'Stale','file'=>$this->realPdf()])->assertConflict();
        $this->deleteJson($url)->assertUnprocessable();
        $this->deleteJson($url,['version'=>2,'reason'=>'Tarik dokumen'])->assertOk();
        $this->actingAs($s['user'])->getJson('/api/report-cards')->assertOk()->assertJsonCount(0,'data');
        $this->getJson($url.'/download')->assertForbidden();$this->getJson($url.'/history')->assertForbidden();
        $this->actingAs($tu)->post($url.'/revision',['version'=>2,'action'=>'reissue','reason'=>'Terbit ulang','file'=>$this->realPdf()])->assertOk()->assertJsonPath('data.version',3);
        $event=\Illuminate\Support\Facades\DB::table('rapor_events')->where('rapor_id',$r->id)->where('action','archive')->first();
        $this->get($url.'/history/'.$event->id.'/download')->assertOk();
        $this->actingAs($s['user'])->get($url.'/download')->assertOk();
        $this->assertDatabaseCount('rapor',1);$this->assertDatabaseCount('rapor_events',6);
        $this->assertCount(3,Storage::disk('local')->allFiles('report-cards'));
    }
    public function test_workspace_effective_roster_missing_file_and_role_scope(): void
    {
        [$tu,$s,$year,$class,$payload]=$this->distributionScenario();$other=$this->createStudent('future');
        \App\Models\AnggotaKelas::create(['kelas_id'=>$class->id,'siswa_id'=>$other['student']->id,'tanggal_mulai'=>now('Asia/Jakarta')->addDay()->toDateString()]);
        $url='/api/tu/report-card-workspace?'.http_build_query(['academic_year_id'=>$year->id,'class_id'=>$class->id,'type'=>'AKHIR_SEMESTER','view'=>'coverage']);
        $this->actingAs($tu)->getJson($url)->assertOk()->assertJsonPath('data.summary.Target siswa',1)->assertJsonPath('data.summary.Belum diunggah',1);
        $this->post('/api/report-cards',$payload+['file'=>$this->realPdf()])->assertCreated();
        $this->getJson($url)->assertOk()->assertJsonPath('data.summary.Tersedia',1);
        Storage::disk('local')->delete(Rapor::firstOrFail()->file_path);
        $this->getJson($url)->assertOk()->assertJsonPath('data.summary.File hilang',1);
        $this->actingAs($s['user'])->getJson($url)->assertForbidden();
    }
    public function test_upload_rejects_future_member_and_fake_pdf(): void
    {
        [$tu,$s,$year,$class,$payload]=$this->distributionScenario();$other=$this->createStudent('future');
        \App\Models\AnggotaKelas::create(['kelas_id'=>$class->id,'siswa_id'=>$other['student']->id,'tanggal_mulai'=>now('Asia/Jakarta')->addDay()->toDateString()]);
        $this->actingAs($tu)->post('/api/report-cards',array_merge($payload,['siswa_id'=>$other['student']->id,'file'=>$this->realPdf()]))->assertUnprocessable();
        $this->post('/api/report-cards',$payload+['file'=>UploadedFile::fake()->createWithContent('rapor.pdf','not a pdf')])->assertUnprocessable();
        $this->assertDatabaseCount('rapor',0);$this->assertCount(0,Storage::disk('local')->allFiles('report-cards'));
    }
    public function test_historical_member_and_legacy_document_outside_roster_remain_visible(): void
    {
        [$tu,$s,$year,$class,$payload]=$this->distributionScenario();
        $former=$this->createStudent('former');$legacy=$this->createStudent('legacy');
        \App\Models\AnggotaKelas::create(['kelas_id'=>$class->id,'siswa_id'=>$former['student']->id,'tanggal_mulai'=>now('Asia/Jakarta')->subDays(3)->toDateString(),'tanggal_selesai'=>now('Asia/Jakarta')->subDay()->toDateString()]);
        $this->createReportCard($legacy['student'],$year,$tu,'AKHIR_SEMESTER');
        $base=['academic_year_id'=>$year->id,'type'=>'AKHIR_SEMESTER'];
        $this->actingAs($tu)->getJson('/api/tu/report-card-workspace?'.http_build_query($base+['view'=>'coverage']))->assertOk()->assertJsonPath('data.summary.Target siswa',1)->assertJsonPath('data.summary.Dokumen di luar target',1);
        $this->getJson('/api/tu/report-card-workspace?'.http_build_query($base+['view'=>'documents']))->assertOk()->assertJsonPath('data.rows.0.member_state','Di luar roster');
        $this->getJson('/api/tu/report-card-workspace?'.http_build_query($base+['view'=>'candidates','class_id'=>$class->id]))->assertOk()->assertJsonPath('meta.total',2);
        $this->post('/api/report-cards',array_merge($payload,['siswa_id'=>$former['student']->id,'file'=>$this->realPdf()]))->assertCreated();
    }
}