<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureUserHasPermission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoleChangeRevokesTokensTest extends TestCase
{
    use RefreshDatabase;

    public function test_changing_user_role_revokes_old_tokens(): void
    {
        // 1. Siapkan role pengujian.
        $tuRole = new Role();

        $tuRole->forceFill([
            'code' => 'TU',
            'name' => 'Tata Usaha',
        ])->save();

        $guruRole = new Role();

        $guruRole->forceFill([
            'code' => 'GURU',
            'name' => 'Guru',
        ])->save();

        $principalRole = new Role();

        $principalRole->forceFill([
            'code' => 'KEPALA_SEKOLAH',
            'name' => 'Kepala Sekolah',
        ])->save();

        // 2. Buat akun TU penguji.
        $tu = new User();

        $tu->forceFill([
            'username' => 'tu.test.role.change',
            'name' => 'TU Pengujian',
            'email' => null,
            'role_id' => $tuRole->id,
            'password' => 'TestPassword#2026',
            'account_status' => 'active',
            'requires_activation' => false,
        ])->save();

        // 3. Buat akun Guru dummy
        // tanpa profil akademik terkait.
        $guru = new User();

        $guru->forceFill([
            'username' => 'guru.test.role.change',
            'name' => 'Guru Pengujian',
            'email' => null,
            'role_id' => $guruRole->id,
            'password' => 'TestPassword#2026',
            'account_status' => 'active',
            'requires_activation' => false,
        ])->save();

        // 4. Buat token lama untuk Guru.
        $guru->createToken('audit-role-before');

        $this->assertSame(
            1,
            $guru->tokens()->count()
        );

        // 5. Permission middleware telah diuji
        // secara terpisah.
        // Tes ini fokus pada perubahan role
        // dan pencabutan token.
        $this->withoutMiddleware(
            EnsureUserHasPermission::class
        );

        Sanctum::actingAs($tu);

        // 6. TU mengubah role Guru menjadi Kepala Sekolah.
        $response = $this->putJson(
            "/api/users/{$guru->id}",
            [
                'role_id' => $principalRole->id,
            ]
        );

        // Perubahan role harus berhasil.
        $response->assertStatus(200);

        // 7. Role baru harus tersimpan.
        $guru->refresh();

        $this->assertSame(
            (int) $principalRole->id,
            (int) $guru->role_id
        );

        // 8. Token lama wajib terhapus.
        $this->assertSame(
            0,
            $guru->tokens()->count()
        );

        // 9. Pastikan database konsisten.
        $this->assertDatabaseHas('users', [
            'id' => $guru->id,
            'role_id' => $principalRole->id,
            'account_status' => 'active',
        ]);
    }
}
