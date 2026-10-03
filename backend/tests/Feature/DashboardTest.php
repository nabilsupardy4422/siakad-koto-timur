<?php

namespace Tests\Feature;

use App\Models\AnggotaKelas;
use App\Models\Assignment;
use App\Models\BahanAjar;
use App\Models\Guru;
use App\Models\JadwalPelajaran;
use App\Models\Kelas;
use App\Models\KomponenNilai;
use App\Models\Nilai;
use App\Models\Presensi;
use App\Models\Role;
use App\Models\Siswa;
use App\Models\TahunAkademik;
use App\Models\User;
use App\Models\WaliKelas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_dashboard(): void
    {
        $response = $this->getJson('/api/dashboard');

        $response->assertStatus(401);
    }

    public function test_tu_can_access_dashboard(): void
    {
        $user = $this->createUser('TU');

        $academicYear = TahunAkademik::create([
            'tahun_mulai' => 2026,
            'tahun_selesai' => 2027,
            'semester' => 1,
            'is_active' => true,
        ]);

        Kelas::create([
            'tahun_akademik_id' => $academicYear->id,
            'nama' => 'X IPA 1',
            'tingkat' => 10,
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/dashboard');

        $response
            ->assertOk()
            ->assertJsonPath('data.role', 'TU')
            ->assertJsonStructure([
                'data' => [
                    'role',
                    'summary' => [
                        'total_guru',
                        'total_siswa',
                        'total_kelas',
                        'total_mata_pelajaran',
                        'active_academic_year',
                    ],
                    'charts' => [
                        'students_by_class',
                    ],
                    'recent' => [
                        'academic_years',
                    ],
                ],
            ]);
}

public function test_kepala_sekolah_can_access_dashboard(): void
    {
        $user = $this->createUser('KEPALA_SEKOLAH');

        TahunAkademik::create([
            'tahun_mulai' => 2026,
            'tahun_selesai' => 2027,
            'semester' => 1,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/dashboard');

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.role',
                'KEPALA_SEKOLAH'
            )
            ->assertJsonStructure([
                'data' => [
                    'role',
                    'summary' => [
                        'total_guru',
                        'total_siswa',
                        'total_kelas',
                        'total_jadwal',
                        'active_academic_year',
                    ],
                    'charts',
                    'recent',
                ],
            ]);
    }

    public function test_guru_can_access_dashboard_with_teaching_context(): void
    {
        $user = $this->createUser('GURU');

        $guru = Guru::create([
            'user_id' => $user->id,
            'nip' => '198001010001',
            'nama_lengkap' => 'Guru Dashboard',
            'jenis_kelamin' => 'L',
            'no_telepon' => null,
            'alamat' => null,
        ]);

        $academicYear = TahunAkademik::create([
            'tahun_mulai' => 2026,
            'tahun_selesai' => 2027,
            'semester' => 1,
            'is_active' => true,
        ]);

        $kelas = Kelas::create([
            'tahun_akademik_id' => $academicYear->id,
            'nama' => 'X IPA 1',
            'tingkat' => 10,
        ]);

        $mapel = \App\Models\Mapel::create([
            'kode' => 'MTK',
            'nama' => 'Matematika',
        ]);

        JadwalPelajaran::create([
            'tahun_akademik_id' => $academicYear->id,
            'kelas_id' => $kelas->id,
            'mapel_id' => $mapel->id,
            'guru_id' => $guru->id,
            'hari' => 'Senin',
            'jam_mulai' => '07:00',
            'jam_selesai' => '08:30',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/dashboard');

        $response
            ->assertOk()
            ->assertJsonPath('data.role', 'GURU')
            ->assertJsonPath('data.is_wali_kelas', false)
            ->assertJsonStructure([
                'data' => [
                    'role',
                    'is_wali_kelas',
                    'summary' => [
                        'today_schedule_count',
                        'teaching_schedule_count',
                        'material_count',
                        'assignment_count',
                        'grade_component_count',
                        'attendance_record_count',
                    ],
                    'wali_kelas',
                    'today_schedule',
                    'recent',
                ],
            ]);
    }

    public function test_guru_dashboard_includes_wali_kelas_context(): void
    {
        $user = $this->createUser('GURU');

        $guru = Guru::create([
            'user_id' => $user->id,
            'nip' => '198001010002',
            'nama_lengkap' => 'Guru Wali',
            'jenis_kelamin' => 'L',
            'no_telepon' => null,
            'alamat' => null,
        ]);

        $academicYear = TahunAkademik::create([
            'tahun_mulai' => 2026,
            'tahun_selesai' => 2027,
            'semester' => 1,
            'is_active' => true,
        ]);

        $kelas = Kelas::create([
            'tahun_akademik_id' => $academicYear->id,
            'nama' => 'XI IPA 1',
            'tingkat' => 11,
        ]);

        WaliKelas::create([
            'guru_id' => $guru->id,
            'kelas_id' => $kelas->id,
            'tahun_akademik_id' => $academicYear->id,
            'tanggal_mulai' => '2026-07-01',
            'tanggal_selesai' => null,
        ]);

        $studentUser = $this->createUser(
            'SISWA',
            '-wali-student'
        );

        $student = Siswa::create([
            'user_id' => $studentUser->id,
            'nisn' => '0012345678',
            'nis' => '10001',
            'nama_lengkap' => 'Siswa Wali',
            'jenis_kelamin' => 'L',
            'tanggal_lahir' => '2010-01-01',
            'tempat_lahir' => 'Padang',
            'no_telepon' => null,
            'alamat' => null,
        ]);

        AnggotaKelas::create([
            'kelas_id' => $kelas->id,
            'siswa_id' => $student->id,
            'tanggal_mulai' => '2026-07-01',
            'tanggal_selesai' => null,
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/dashboard');

        $response
            ->assertOk()
            ->assertJsonPath('data.role', 'GURU')
            ->assertJsonPath('data.is_wali_kelas', true)
            ->assertJsonPath(
                'data.wali_kelas.class.id',
                $kelas->id
            )
            ->assertJsonPath(
                'data.wali_kelas.total_students',
                1
            );
    }

    public function test_siswa_can_access_dashboard_with_own_class_context(): void
    {
        $user = $this->createUser('SISWA');

        $student = Siswa::create([
            'user_id' => $user->id,
            'nisn' => '0012345679',
            'nis' => '10002',
            'nama_lengkap' => 'Siswa Dashboard',
            'jenis_kelamin' => 'L',
            'tanggal_lahir' => '2010-02-01',
            'tempat_lahir' => 'Padang',
            'no_telepon' => null,
            'alamat' => null,
        ]);

        $academicYear = TahunAkademik::create([
            'tahun_mulai' => 2026,
            'tahun_selesai' => 2027,
            'semester' => 1,
            'is_active' => true,
        ]);

        $kelas = Kelas::create([
            'tahun_akademik_id' => $academicYear->id,
            'nama' => 'X IPA 2',
            'tingkat' => 10,
        ]);

        AnggotaKelas::create([
            'kelas_id' => $kelas->id,
            'siswa_id' => $student->id,
            'tanggal_mulai' => '2026-07-01',
            'tanggal_selesai' => null,
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/dashboard');

        $response
            ->assertOk()
            ->assertJsonPath('data.role', 'SISWA')
            ->assertJsonPath(
                'data.class.id',
                $kelas->id
            )
            ->assertJsonStructure([
                'data' => [
                    'role',
                    'summary' => [
                        'attendance_count',
                        'grade_count',
                        'material_count',
                        'assignment_count',
                    ],
                    'class',
                    'today_schedule',
                    'recent',
                ],
            ]);
    }

    public function test_siswa_dashboard_does_not_use_another_students_class(): void
    {
        $user = $this->createUser('SISWA');

        Siswa::create([
            'user_id' => $user->id,
            'nisn' => '0012345680',
            'nis' => '10003',
            'nama_lengkap' => 'Siswa Tanpa Kelas',
            'jenis_kelamin' => 'L',
            'tanggal_lahir' => '2010-03-01',
            'tempat_lahir' => 'Padang',
            'no_telepon' => null,
            'alamat' => null,
        ]);

        TahunAkademik::create([
            'tahun_mulai' => 2026,
            'tahun_selesai' => 2027,
            'semester' => 1,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/dashboard');

        $response
            ->assertOk()
            ->assertJsonPath('data.role', 'SISWA')
            ->assertJsonPath('data.class', null);
    }

    public function test_dashboard_rejects_unsupported_role(): void
    {
        $user = $this->createUser('UNKNOWN');

        $response = $this->actingAs($user)
            ->getJson('/api/dashboard');

        $response->assertStatus(403);
    }

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
            'name' => $roleCode . ' Dashboard Test' . $suffix,
            'email' => strtolower($roleCode) . $suffix . '@example.test',
            'password' => Hash::make('password-test'),
            'account_status' => 'active',
        ]);
    }
}