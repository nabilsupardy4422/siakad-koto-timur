<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\GoogleIdentityVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GoogleAccountLinkTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(
        string $username,
        string $roleCode = 'GURU',
        bool $requiresActivation = false
    ): User {
        $role = Role::query()->firstOrCreate(
            ['code' => $roleCode],
            ['name' => $roleCode]
        );

        $user = new User();

        $user->forceFill([
            'username' => $username,
            'name' => 'Pengguna Uji Google',
            'email' => null,
            'password' => 'PasswordUji#2026',
            'role_id' => $role->id,
            'account_status' => 'active',
            'requires_activation' => $requiresActivation,
        ])->save();

        return $user;
    }

    private function mockIdentity(
        string $credential,
        string $subject,
        ?string $email
    ): void {
        $this->mock(GoogleIdentityVerifier::class)
            ->shouldReceive('verifiedIdentity')
            ->once()
            ->with($credential)
            ->andReturn([
                'subject' => $subject,
                'email' => $email,
            ]);
    }

    public function test_guest_cannot_access_google_account_endpoints(): void
    {
        $this->getJson('/api/profile/google/status')
            ->assertUnauthorized();

        $this->postJson('/api/profile/google/link', [
            'credential' => 'token',
        ])->assertUnauthorized();

        $this->postJson('/api/profile/google/unlink', [
            'current_password' => 'password',
        ])->assertUnauthorized();
    }

    public function test_status_is_unlinked_when_user_has_no_google_account(): void
    {
        $user = $this->makeUser('guru.google.no-link');
        Sanctum::actingAs($user);

        $this->getJson('/api/profile/google/status')
            ->assertOk()
            ->assertJsonPath('data.google_linked', false)
            ->assertJsonPath('data.google_email', null);
    }

    public function test_first_google_link_requires_current_password(): void
    {
        $guru = $this->makeUser('guru.google.no-password');
        Sanctum::actingAs($guru);

        $this->mockIdentity(
            'verified-no-password-token',
            'google-sub-no-password',
            'tanpa.password@gmail.com'
        );

        $this->postJson('/api/profile/google/link', [
            'credential' => 'verified-no-password-token',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);

        $this->assertDatabaseHas('users', [
            'id' => $guru->id,
            'google_id' => null,
            'google_email' => null,
        ]);
    }

    public function test_first_google_link_rejects_wrong_current_password(): void
    {
        $guru = $this->makeUser('guru.google.wrong-password');
        Sanctum::actingAs($guru);

        $this->mockIdentity(
            'verified-wrong-password-token',
            'google-sub-wrong-password',
            'password.salah@gmail.com'
        );

        $this->postJson('/api/profile/google/link', [
            'credential' => 'verified-wrong-password-token',
            'current_password' => 'PasswordSalah#2026',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);

        $this->assertDatabaseHas('users', [
            'id' => $guru->id,
            'google_id' => null,
            'google_email' => null,
        ]);
    }

    public function test_guru_can_link_verified_google_account(): void
    {
        $guru = $this->makeUser('guru.google.1');
        Sanctum::actingAs($guru);

        $this->mockIdentity(
            'verified-id-token',
            'google-sub-001',
            'guru.terverifikasi@gmail.com'
        );

        $this->postJson('/api/profile/google/link', [
            'credential' => 'verified-id-token',
            'current_password' => 'PasswordUji#2026',
        ])
            ->assertOk()
            ->assertJsonPath('data.google_linked', true)
            ->assertJsonPath('data.google_email', 'guru.terverifikasi@gmail.com');

        $this->assertDatabaseHas('users', [
            'id' => $guru->id,
            'google_id' => 'google-sub-001',
            'google_email' => 'guru.terverifikasi@gmail.com',
        ]);

        // Sanctum::actingAs menggunakan instance model yang sama.
        // Refresh agar endpoint status membaca data terbaru.
        $guru->refresh();

        $this->getJson('/api/profile/google/status')
            ->assertOk()
            ->assertJsonPath('data.google_linked', true)
            ->assertJsonPath('data.google_email', 'guru.terverifikasi@gmail.com');
    }

    public function test_google_account_cannot_be_linked_to_two_users(): void
    {
        $first = $this->makeUser('guru.google.2');
        $first->forceFill([
            'google_id' => 'google-sub-duplicate',
            'google_email' => 'pemilik@gmail.com',
        ])->save();

        $second = $this->makeUser('guru.google.3');
        Sanctum::actingAs($second);

        $this->mockIdentity(
            'verified-id-token',
            'google-sub-duplicate',
            'pemilik@gmail.com'
        );

        $this->postJson('/api/profile/google/link', [
            'credential' => 'verified-id-token',
            'current_password' => 'PasswordUji#2026',
        ])->assertStatus(409);

        $this->assertNull($second->fresh()->google_id);
        $this->assertNull($second->fresh()->google_email);
        $this->assertSame('google-sub-duplicate', $first->fresh()->google_id);
    }

    public function test_google_unlink_needs_current_password_and_clears_email(): void
    {
        $guru = $this->makeUser('guru.google.4');
        $guru->forceFill([
            'google_id' => 'google-sub-004',
            'google_email' => 'guru4@gmail.com',
        ])->save();

        Sanctum::actingAs($guru);

        $this->postJson('/api/profile/google/unlink', [
            'current_password' => 'salah-password',
        ])->assertStatus(422);

        $this->assertSame('google-sub-004', $guru->fresh()->google_id);
        $this->assertSame('guru4@gmail.com', $guru->fresh()->google_email);

        $this->postJson('/api/profile/google/unlink', [
            'current_password' => 'PasswordUji#2026',
        ])
            ->assertOk()
            ->assertJsonPath('data.google_linked', false)
            ->assertJsonPath('data.google_email', null);

        $guru->refresh();
        $this->assertNull($guru->google_id);
        $this->assertNull($guru->google_email);

        $this->getJson('/api/profile/google/status')
            ->assertOk()
            ->assertJsonPath('data.google_linked', false)
            ->assertJsonPath('data.google_email', null);
    }

    public function test_pending_activation_cannot_link_google(): void
    {
        $guru = $this->makeUser(
            'guru.google.pending',
            'GURU',
            true
        );

        Sanctum::actingAs($guru);

        $this->postJson('/api/profile/google/link', [
            'credential' => 'verified-id-token',
        ])->assertStatus(403);

        $this->assertNull($guru->fresh()->google_id);
        $this->assertNull($guru->fresh()->google_email);
    }

    public function test_tu_can_link_google(): void
    {
        $tu = $this->makeUser('tu.google.test', 'TU');
        Sanctum::actingAs($tu);

        $this->mockIdentity(
            'verified-tu-token',
            'google-sub-tu-001',
            'tu.terverifikasi@gmail.com'
        );

        $this->postJson('/api/profile/google/link', [
            'credential' => 'verified-tu-token',
            'current_password' => 'PasswordUji#2026',
        ])
            ->assertOk()
            ->assertJsonPath('data.google_linked', true)
            ->assertJsonPath('data.google_email', 'tu.terverifikasi@gmail.com');

        $this->assertDatabaseHas('users', [
            'id' => $tu->id,
            'google_id' => 'google-sub-tu-001',
            'google_email' => 'tu.terverifikasi@gmail.com',
        ]);
    }

    public function test_kepala_sekolah_can_link_google(): void
    {
        $kepala = $this->makeUser('kepala.google.test', 'KEPALA_SEKOLAH');
        Sanctum::actingAs($kepala);

        $this->mockIdentity(
            'verified-kepala-token',
            'google-sub-kepala-001',
            'kepala.terverifikasi@gmail.com'
        );

        $this->postJson('/api/profile/google/link', [
            'credential' => 'verified-kepala-token',
            'current_password' => 'PasswordUji#2026',
        ])
            ->assertOk()
            ->assertJsonPath('data.google_linked', true)
            ->assertJsonPath('data.google_email', 'kepala.terverifikasi@gmail.com');

        $this->assertDatabaseHas('users', [
            'id' => $kepala->id,
            'google_id' => 'google-sub-kepala-001',
            'google_email' => 'kepala.terverifikasi@gmail.com',
        ]);
    }

    public function test_existing_google_link_can_be_reverified_to_fill_missing_email(): void
    {
        // Simulasikan akun yang ditautkan sebelum kolom google_email dibuat.
        $guru = $this->makeUser('guru.google.legacy');
        $guru->forceFill([
            'google_id' => 'google-sub-legacy',
            'google_email' => null,
        ])->save();
        Sanctum::actingAs($guru);

        $this->mockIdentity(
            'verified-same-google',
            'google-sub-legacy',
            'legacy@gmail.com'
        );

        $this->postJson('/api/profile/google/link', [
            'credential' => 'verified-same-google',
        ])
            ->assertOk()
            ->assertJsonPath('data.google_linked', true)
            ->assertJsonPath('data.google_email', 'legacy@gmail.com');

        $this->assertDatabaseHas('users', [
            'id' => $guru->id,
            'google_id' => 'google-sub-legacy',
            'google_email' => 'legacy@gmail.com',
        ]);
    }

    public function test_reverification_of_same_google_id_updates_email(): void
    {
        $guru = $this->makeUser('guru.google.update-email');
        $guru->forceFill([
            'google_id' => 'google-sub-update',
            'google_email' => 'alamat.lama@gmail.com',
        ])->save();
        Sanctum::actingAs($guru);

        $this->mockIdentity(
            'verified-same-google-new-email',
            'google-sub-update',
            'alamat.baru@gmail.com'
        );

        $this->postJson('/api/profile/google/link', [
            'credential' => 'verified-same-google-new-email',
        ])
            ->assertOk()
            ->assertJsonPath('data.google_email', 'alamat.baru@gmail.com');

        $this->assertDatabaseHas('users', [
            'id' => $guru->id,
            'google_id' => 'google-sub-update',
            'google_email' => 'alamat.baru@gmail.com',
        ]);
    }

    public function test_existing_google_link_cannot_be_replaced_with_different_google(): void
    {
        $guru = $this->makeUser('guru.google.different');
        $guru->forceFill([
            'google_id' => 'google-sub-original',
            'google_email' => 'asli@gmail.com',
        ])->save();
        Sanctum::actingAs($guru);

        $this->mockIdentity(
            'verified-different-google',
            'google-sub-other',
            'lain@gmail.com'
        );

        $this->postJson('/api/profile/google/link', [
            'credential' => 'verified-different-google',
        ])->assertStatus(409);

        $this->assertDatabaseHas('users', [
            'id' => $guru->id,
            'google_id' => 'google-sub-original',
            'google_email' => 'asli@gmail.com',
        ]);
    }

    public function test_google_can_link_without_verified_email_but_email_stays_null(): void
    {
        $siswa = $this->makeUser('siswa.google.no-email', 'SISWA');
        Sanctum::actingAs($siswa);

        // Verifier mengembalikan null jika email_verified tidak valid.
        $this->mockIdentity(
            'verified-google-without-email',
            'google-sub-no-email',
            null
        );

        $this->postJson('/api/profile/google/link', [
            'credential' => 'verified-google-without-email',
            'current_password' => 'PasswordUji#2026',
        ])
            ->assertOk()
            ->assertJsonPath('data.google_linked', true)
            ->assertJsonPath('data.google_email', null);

        $this->assertDatabaseHas('users', [
            'id' => $siswa->id,
            'google_id' => 'google-sub-no-email',
            'google_email' => null,
        ]);
    }
}
