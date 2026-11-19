<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\GoogleIdentityVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(
        string $username,
        string $roleCode = 'GURU',
        ?string $googleId = 'google-sub-001',
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
            'name' => 'Pengguna Uji Google Login',
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

    public function test_google_login_requires_credential(): void
    {
        $this->postJson('/api/login/google', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('credential');
    }

    public function test_linked_active_guru_can_login_with_google(): void
    {
        $guru = $this->makeUser('guru.google.login');

        $this->mockSubject(
            'valid-google-id-token',
            'google-sub-001'
        );

        $response = $this->postJson('/api/login/google', [
            'credential' => 'valid-google-id-token',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.id', $guru->id)
            ->assertJsonPath('data.user.username', 'guru.google.login')
            ->assertJsonPath('data.user.role.code', 'GURU')
            ->assertJsonStructure([
                'data' => [
                    'token',
                    'token_type',
                    'user' => ['id', 'username', 'name', 'role'],
                    'permissions',
                    'assignments',
                ],
            ]);

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertSame(1, $guru->tokens()->count());
        $this->assertNotNull($guru->fresh()->last_login_at);
    }

    public function test_unlinked_google_cannot_login_or_create_account(): void
    {
        $this->mockSubject(
            'unlinked-google-token',
            'google-sub-not-registered'
        );

        $this->postJson('/api/login/google', [
            'credential' => 'unlinked-google-token',
        ])->assertUnauthorized();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_google_login_never_matches_by_email_only(): void
    {
        $guru = $this->makeUser(
            'guru.google.email',
            'GURU',
            'different-google-sub'
        );

        $guru->forceFill([
            'google_email' => 'same@gmail.com',
        ])->save();

        // Google subject berbeda: alamat email yang mirip
        // tidak boleh menjadi dasar masuk ke akun SIAKAD.
        $this->mockSubject(
            'different-id-token',
            'google-sub-not-registered'
        );

        $this->postJson('/api/login/google', [
            'credential' => 'different-id-token',
        ])->assertUnauthorized();

        $this->assertSame(0, $guru->tokens()->count());
    }

    public function test_google_login_rejects_inactive_account(): void
    {
        $guru = $this->makeUser(
            'guru.google.inactive',
            'GURU',
            'google-sub-inactive',
            'inactive'
        );

        $this->mockSubject(
            'inactive-google-token',
            'google-sub-inactive'
        );

        $this->postJson('/api/login/google', [
            'credential' => 'inactive-google-token',
        ])->assertForbidden();

        $this->assertSame(0, $guru->tokens()->count());
    }

    public function test_google_login_rejects_pending_activation(): void
    {
        $guru = $this->makeUser(
            'guru.google.pending',
            'GURU',
            'google-sub-pending',
            'active',
            true
        );

        $this->mockSubject(
            'pending-google-token',
            'google-sub-pending'
        );

        $this->postJson('/api/login/google', [
            'credential' => 'pending-google-token',
        ])->assertForbidden();

        $this->assertSame(0, $guru->tokens()->count());
    }

    public function test_google_login_rejects_unsupported_role(): void
    {
        $user = $this->makeUser(
            'google.unsupported',
            'ROLE_TIDAK_DIKENAL',
            'google-sub-unknown-role'
        );

        $this->mockSubject(
            'unknown-role-token',
            'google-sub-unknown-role'
        );

        $this->postJson('/api/login/google', [
            'credential' => 'unknown-role-token',
        ])->assertForbidden();

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_google_login_rejects_invalid_google_token(): void
    {
        $this->mock(GoogleIdentityVerifier::class)
            ->shouldReceive('subject')
            ->once()
            ->with('invalid-google-token')
            ->andThrow(
                ValidationException::withMessages([
                    'credential' => 'Token Google tidak valid.',
                ])
            );

        $this->postJson('/api/login/google', [
            'credential' => 'invalid-google-token',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('credential');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_tu_can_login_with_linked_google(): void
    {
        $tu = $this->makeUser(
            'tu.google.login',
            'TU',
            'google-sub-tu-login'
        );

        $this->mockSubject('tu-google-token', 'google-sub-tu-login');

        $this->postJson('/api/login/google', [
            'credential' => 'tu-google-token',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.id', $tu->id)
            ->assertJsonPath('data.user.role.code', 'TU');
    }

    public function test_kepala_sekolah_can_login_with_linked_google(): void
    {
        $kepalaSekolah = $this->makeUser(
            'kepala.google.login',
            'KEPALA_SEKOLAH',
            'google-sub-kepala-login'
        );

        $this->mockSubject(
            'kepala-google-token',
            'google-sub-kepala-login'
        );

        $this->postJson('/api/login/google', [
            'credential' => 'kepala-google-token',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.id', $kepalaSekolah->id)
            ->assertJsonPath('data.user.role.code', 'KEPALA_SEKOLAH');
    }

    public function test_siswa_can_login_with_linked_google(): void
    {
        $siswa = $this->makeUser(
            'siswa.google.login',
            'SISWA',
            'google-sub-siswa-login'
        );

        $this->mockSubject(
            'siswa-google-token',
            'google-sub-siswa-login'
        );

        $this->postJson('/api/login/google', [
            'credential' => 'siswa-google-token',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.id', $siswa->id)
            ->assertJsonPath('data.user.role.code', 'SISWA');
    }
}
