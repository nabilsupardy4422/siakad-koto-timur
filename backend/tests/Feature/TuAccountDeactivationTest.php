<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureUserHasPermission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TuAccountDeactivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_last_active_tu_cannot_deactivate_itself(): void
    {
        // Fokus pengujian adalah aturan penonaktifan akun.
        // Middleware permission sudah diuji terpisah.
        $this->withoutMiddleware(
            EnsureUserHasPermission::class
        );

        // ==========================================
        // 1. SIAPKAN ROLE TU
        // ==========================================

        $tuRole = Role::query()->firstOrCreate(
            ['code' => 'TU'],
            ['name' => 'Tata Usaha']
        );

        // ==========================================
        // 2. BUAT DUA AKUN TU DUMMY
        // ==========================================

        $tuUtama = new User();

        $tuUtama->forceFill([
            'username' => 'tu.audit.utama',
            'name' => 'TU Audit Utama',
            'email' => null,
            'role_id' => $tuRole->id,
            'password' => 'TestPassword#2026',
            'account_status' => 'active',
            'requires_activation' => false,
        ])->save();

        $tuKedua = new User();

        $tuKedua->forceFill([
            'username' => 'tu.audit.kedua',
            'name' => 'TU Audit Kedua',
            'email' => null,
            'role_id' => $tuRole->id,
            'password' => 'TestPassword#2026',
            'account_status' => 'active',
            'requires_activation' => false,
        ])->save();

        // Berikan sesi lama pada TU kedua.
        $tuKedua->createToken('audit-token-lama');

        $this->assertSame(
            1,
            $tuKedua->tokens()->count()
        );

        // ==========================================
        // 3. LOGIN SEBAGAI TU UTAMA
        // ==========================================

        Sanctum::actingAs($tuUtama);

        // ==========================================
        // 4. NONAKTIFKAN TU KEDUA
        // ==========================================

        $response = $this->postJson(
            "/api/users/{$tuKedua->id}/deactivate"
        );

        $response->assertStatus(200);

        $tuKedua->refresh();

        $this->assertSame(
            'inactive',
            $tuKedua->account_status
        );

        // Token TU kedua harus dicabut.
        $this->assertSame(
            0,
            $tuKedua->tokens()->count()
        );

        // ==========================================
        // 5. PASTIKAN TERSISA SATU TU AKTIF
        // ==========================================

        $jumlahTuAktif = User::query()
            ->where('account_status', 'active')
            ->whereHas('role', function ($query) {
                $query->where('code', 'TU');
            })
            ->count();

        $this->assertSame(1, $jumlahTuAktif);

        // ==========================================
        // 6. TU TERAKHIR MENCOBA NONAKTIFKAN DIRI
        // ==========================================

        $response = $this->postJson(
            "/api/users/{$tuUtama->id}/deactivate"
        );

        // Permintaan harus ditolak.
        $response->assertStatus(422);

        // ==========================================
        // 7. PASTIKAN TU TERAKHIR TETAP AKTIF
        // ==========================================

        $tuUtama->refresh();

        $this->assertSame(
            'active',
            $tuUtama->account_status
        );

        $this->assertDatabaseHas('users', [
            'id' => $tuUtama->id,
            'role_id' => $tuRole->id,
            'account_status' => 'active',
        ]);
    }
}
