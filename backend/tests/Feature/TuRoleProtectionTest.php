<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureUserHasPermission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TuRoleProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_tu_cannot_change_own_role(): void
    {
        /*
         * Fokus pengujian:
         * perlindungan perubahan role milik sendiri.
         *
         * Middleware permission diuji terpisah,
         * sehingga kita nonaktifkan hanya middleware
         * tersebut untuk pengujian ini.
         *
         * Middleware authentication dan role
         * tetap berjalan.
         */
        $this->withoutMiddleware(
            EnsureUserHasPermission::class
        );

        // =====================================
        // 1. SIAPKAN ROLE DI DATABASE TEST
        // =====================================

        $tuRole = Role::query()
            ->where('code', 'TU')
            ->first();

        if ($tuRole === null) {
            $tuRole = new Role();

            $tuRole->forceFill([
                'code' => 'TU',
                'name' => 'Tata Usaha',
            ])->save();
        }

        $guruRole = Role::query()
            ->where('code', 'GURU')
            ->first();

        if ($guruRole === null) {
            $guruRole = new Role();

            $guruRole->forceFill([
                'code' => 'GURU',
                'name' => 'Guru',
            ])->save();
        }

        // =====================================
        // 2. BUAT AKUN TU DUMMY
        // =====================================

        $tu = new User();

        $tu->forceFill([
            'username' => 'tu.test.role.protection',
            'name' => 'TU Pengujian',
            'email' => 'tu.role.test@example.com',
            'password' => 'TestPassword#2026',
            'role_id' => $tuRole->id,
            'account_status' => 'active',
            'requires_activation' => false,
        ])->save();

        $originalRoleId = $tu->role_id;

        // =====================================
        // 3. LOGIN SEBAGAI TU DUMMY
        // =====================================

        Sanctum::actingAs($tu);

        // =====================================
        // 4. COBA UBAH ROLE SENDIRI
        // =====================================

        $response = $this->putJson(
            "/api/users/{$tu->id}",
            [
                'role_id' => $guruRole->id,
            ]
        );

        // =====================================
        // 5. VALIDASI PENOLAKAN
        // =====================================

        $response->assertStatus(422);

        $response->assertJsonPath(
            'message',
            'Role akun sendiri tidak dapat diubah melalui Manajemen Pengguna.'
        );

        // =====================================
        // 6. PASTIKAN ROLE TIDAK BERUBAH
        // =====================================

        $this->assertDatabaseHas('users', [
            'id' => $tu->id,
            'role_id' => $originalRoleId,
            'account_status' => 'active',
        ]);

        $tu->refresh();

        $this->assertSame(
            (int) $originalRoleId,
            (int) $tu->role_id
        );
    }
}
