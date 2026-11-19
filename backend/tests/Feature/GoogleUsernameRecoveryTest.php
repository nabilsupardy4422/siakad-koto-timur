<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\GoogleIdentityVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GoogleUsernameRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(
        string $username,
        string $roleCode = 'GURU',
        ?string $googleId = 'google-sub-recovery-001',
        string $accountStatus = 'active',
        bool $requiresActivation = false
    ): User {
        $role = Role::query()->firstOrCreate(
            ['code' => $roleCode],
            ['name' => $roleCode]
        );

        $user = new User();

        $user->forceFill([
            'username' => $username,
            'name' => 'Pengguna Uji Pemulihan',
            'email' => null,
            'password' => 'PasswordUji#2026',
            'role_id' => $role->id,
            'account_status' => $accountStatus,
            'requires_activation' => $requiresActivation,
            'google_id' => $googleId,
        ])->save();

        return $user;
    }

    private function mockSubject(
        string $credential,
        string $googleSubject
    ): void {
        $this->mock(GoogleIdentityVerifier::class)
            ->shouldReceive('subject')
            ->once()
            ->with($credential)
            ->andReturn($googleSubject);
    }

    public function test_recovery_requires_google_credential(): void
    {
        $this->postJson('/api/recovery/google/username', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('credential');
    }

    public function test_linked_active_guru_can_recover_username(): void
    {
        $guru = $this->makeUser('guru.username.recovery');

        $this->mockSubject(
            'valid-recovery-token',
            'google-sub-recovery-001'
        );

        $this->postJson('/api/recovery/google/username', [
            'credential' => 'valid-recovery-token',
        ])
            ->assertOk()
            ->assertJsonPath(
                'data.username',
                'guru.username.recovery'
            )
            ->assertJsonMissingPath('data.token');

        // Pemulihan username tidak boleh membuat sesi login
        // maupun mengubah waktu login terakhir.
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNull($guru->fresh()->last_login_at);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_unlinked_google_cannot_recover_or_create_account(): void
    {
        $this->mockSubject(
            'unknown-recovery-token',
            'google-sub-does-not-exist'
        );

        $this->postJson('/api/recovery/google/username', [
            'credential' => 'unknown-recovery-token',
        ])->assertUnauthorized();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_recovery_never_matches_an_account_by_google_email(): void
    {
        $guru = $this->makeUser(
            'guru.email.not-identity',
            'GURU',
            'google-sub-original'
        );

        $guru->forceFill([
            'google_email' => 'google.sama@gmail.com',
        ])->save();

        // Email yang sama bukan bukti Google subject yang sama.
        $this->mockSubject(
            'different-google-token',
            'google-sub-different'
        );

        $this->postJson('/api/recovery/google/username', [
            'credential' => 'different-google-token',
        ])->assertUnauthorized();

        $this->assertSame('google-sub-original', $guru->fresh()->google_id);
    }

    public function test_inactive_account_cannot_recover_username(): void
    {
        $this->makeUser(
            'guru.recovery.inactive',
            'GURU',
            'google-sub-inactive',
            'inactive'
        );

        $this->mockSubject(
            'inactive-recovery-token',
            'google-sub-inactive'
        );

        $this->postJson('/api/recovery/google/username', [
            'credential' => 'inactive-recovery-token',
        ])->assertForbidden();
    }

    public function test_pending_activation_cannot_recover_username(): void
    {
        $this->makeUser(
            'guru.recovery.pending',
            'GURU',
            'google-sub-pending',
            'active',
            true
        );

        $this->mockSubject(
            'pending-recovery-token',
            'google-sub-pending'
        );

        $this->postJson('/api/recovery/google/username', [
            'credential' => 'pending-recovery-token',
        ])->assertForbidden();
    }

    public function test_unsupported_role_cannot_recover_username(): void
    {
        $this->makeUser(
            'unsupported.recovery.role',
            'ROLE_TIDAK_DIKENAL',
            'google-sub-unsupported'
        );

        $this->mockSubject(
            'unsupported-role-token',
            'google-sub-unsupported'
        );

        $this->postJson('/api/recovery/google/username', [
            'credential' => 'unsupported-role-token',
        ])->assertForbidden();
    }

    public function test_invalid_google_id_token_is_rejected(): void
    {
        $this->mock(GoogleIdentityVerifier::class)
            ->shouldReceive('subject')
            ->once()
            ->with('invalid-recovery-token')
            ->andThrow(
                ValidationException::withMessages([
                    'credential' => 'Token Google tidak valid.',
                ])
            );

        $this->postJson('/api/recovery/google/username', [
            'credential' => 'invalid-recovery-token',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('credential');
    }

    public function test_tu_can_recover_username(): void
    {
        $this->makeUser(
            'tu.username.recovery',
            'TU',
            'google-sub-tu-recovery'
        );

        $this->mockSubject('tu-recovery-token', 'google-sub-tu-recovery');

        $this->postJson('/api/recovery/google/username', [
            'credential' => 'tu-recovery-token',
        ])
            ->assertOk()
            ->assertJsonPath('data.username', 'tu.username.recovery');
    }

    public function test_kepala_sekolah_can_recover_username(): void
    {
        $this->makeUser(
            'kepala.username.recovery',
            'KEPALA_SEKOLAH',
            'google-sub-kepala-recovery'
        );

        $this->mockSubject(
            'kepala-recovery-token',
            'google-sub-kepala-recovery'
        );

        $this->postJson('/api/recovery/google/username', [
            'credential' => 'kepala-recovery-token',
        ])
            ->assertOk()
            ->assertJsonPath('data.username', 'kepala.username.recovery');
    }

    public function test_siswa_can_recover_username(): void
    {
        $this->makeUser(
            'siswa.username.recovery',
            'SISWA',
            'google-sub-siswa-recovery'
        );

        $this->mockSubject(
            'siswa-recovery-token',
            'google-sub-siswa-recovery'
        );

        $this->postJson('/api/recovery/google/username', [
            'credential' => 'siswa-recovery-token',
        ])
            ->assertOk()
            ->assertJsonPath('data.username', 'siswa.username.recovery');
    }
}
