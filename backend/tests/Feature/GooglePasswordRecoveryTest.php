<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\GoogleIdentityVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Google password recovery: Google + nonce -> one-time grant -> new password.
 * Hanya verifikasi Google eksternal yang di-mock; challenge, grant,
 * cache, route, controller, dan database tetap berjalan sungguhan.
 */
class GooglePasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_PASSWORD = 'PasswordLama#2026';
    private const NEW_PASSWORD = 'PasswordBaru#2026';
    private const SUBJECT = 'google-sub-password-001';

    private function makeUser(
        string $username,
        string $roleCode = 'GURU',
        ?string $googleId = self::SUBJECT,
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
            'name' => 'Pengguna Uji Reset Password',
            'email' => null,
            'password' => self::OLD_PASSWORD,
            'role_id' => $role->id,
            'account_status' => $accountStatus,
            'requires_activation' => $requiresActivation,
            'google_id' => $googleId,
        ])->save();

        return $user;
    }

    /** @return array{challenge_id: string, nonce: string, expires_in: int} */
    private function issueChallenge(): array
    {
        $response = $this->postJson('/api/recovery/google/password/challenge')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonStructure(['data' => ['challenge_id', 'nonce', 'expires_in']]);

        return $response->json('data');
    }

    private function mockIdentity(
        string $credential,
        string $subject,
        ?string $nonce,
        int $times = 1
    ): void {
        $this->mock(GoogleIdentityVerifier::class)
            ->shouldReceive('verifiedIdentity')
            ->times($times)
            ->with($credential)
            ->andReturn([
                'subject' => $subject,
                'email' => null,
                'nonce' => $nonce,
            ]);
    }

    private function verify(
        array $challenge,
        string $credential = 'valid-google-id-token'
    ): TestResponse {
        return $this->postJson('/api/recovery/google/password/verify', [
            'challenge_id' => $challenge['challenge_id'],
            'credential' => $credential,
        ]);
    }

    /**
     * Lakukan alur verifikasi Google lengkap dan kembalikan reset_token.
     * Hanya identitas dari provider Google yang disimulasikan.
     */
    private function verifiedGrant(
        string $subject = self::SUBJECT,
        string $credential = 'valid-google-id-token'
    ): string {
        $challenge = $this->issueChallenge();
        $this->mockIdentity($credential, $subject, $challenge['nonce']);

        $response = $this->verify($challenge, $credential)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonStructure(['data' => ['username', 'reset_token', 'expires_in']]);

        $token = $response->json('data.reset_token');
        $this->assertIsString($token);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $token);
        $this->assertSame(300, $response->json('data.expires_in'));

        return $token;
    }

    private function resetPassword(
        string $resetToken,
        string $password = self::NEW_PASSWORD
    ): TestResponse {
        return $this->postJson('/api/recovery/google/password', [
            'reset_token' => $resetToken,
            'password' => $password,
            'password_confirmation' => $password,
        ]);
    }

    public function test_challenge_endpoint_returns_fresh_nonce_and_five_minute_expiry(): void
    {
        $first = $this->issueChallenge();
        $second = $this->issueChallenge();

        $this->assertMatchesRegularExpression('/\A[a-f0-9]{48}\z/D', $first['challenge_id']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $first['nonce']);
        $this->assertSame(300, $first['expires_in']);
        $this->assertNotSame($first['challenge_id'], $second['challenge_id']);
        $this->assertNotSame($first['nonce'], $second['nonce']);
    }

    public function test_verify_requires_challenge_id(): void
    {
        $this->postJson('/api/recovery/google/password/verify', [
            'credential' => 'some-token',
        ])->assertUnprocessable()->assertJsonValidationErrors('challenge_id');
    }

    public function test_verify_rejects_malformed_challenge_id(): void
    {
        $this->postJson('/api/recovery/google/password/verify', [
            'challenge_id' => 'invalid',
            'credential' => 'some-token',
        ])->assertUnprocessable()->assertJsonValidationErrors('challenge_id');
    }

    public function test_verify_requires_google_credential(): void
    {
        $challenge = $this->issueChallenge();

        $this->postJson('/api/recovery/google/password/verify', [
            'challenge_id' => $challenge['challenge_id'],
        ])->assertUnprocessable()->assertJsonValidationErrors('credential');
    }

    public function test_linked_active_user_can_verify_and_receive_grant_without_logging_in(): void
    {
        $guru = $this->makeUser('guru.verify.first');
        $challenge = $this->issueChallenge();
        $this->mockIdentity('google-id-token', self::SUBJECT, $challenge['nonce']);

        $response = $this->verify($challenge, 'google-id-token')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.username', $guru->username)
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.google_id');

        $this->assertMatchesRegularExpression(
            '/\A[a-f0-9]{64}\z/D',
            $response->json('data.reset_token')
        );
        $this->assertSame(300, $response->json('data.expires_in'));
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $guru->fresh()->password));
        $this->assertSame(0, $guru->tokens()->count());
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_reset_requires_one_time_grant_not_legacy_google_credential(): void
    {
        $this->postJson('/api/recovery/google/password', [
            'challenge_id' => str_repeat('a', 48),
            'credential' => 'old-style-google-id-token',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertUnprocessable()->assertJsonValidationErrors('reset_token');
    }

    public function test_reset_rejects_malformed_grant(): void
    {
        $this->resetPassword('invalid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reset_token');
    }

    public function test_reset_rejects_unknown_grant(): void
    {
        $this->resetPassword(str_repeat('a', 64))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reset_token');
    }

    public function test_reset_requires_password_confirmation(): void
    {
        $this->postJson('/api/recovery/google/password', [
            'reset_token' => str_repeat('a', 64),
            'password' => self::NEW_PASSWORD,
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_reset_rejects_weak_password(): void
    {
        $this->resetPassword(str_repeat('a', 64), 'password123')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_reset_rejects_mismatched_confirmation(): void
    {
        $this->postJson('/api/recovery/google/password', [
            'reset_token' => str_repeat('a', 64),
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => 'PasswordLain#2026',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_linked_guru_can_reset_and_revoke_all_old_sanctum_tokens(): void
    {
        $guru = $this->makeUser('guru.reset.google');
        $oldToken = $guru->createToken('device-a')->plainTextToken;
        $guru->createToken('device-b');
        $this->assertSame(2, $guru->tokens()->count());

        $grant = $this->verifiedGrant();
        $this->resetPassword($grant)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonMissingPath('data.token');

        $fresh = $guru->fresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $fresh->password));
        $this->assertFalse(Hash::check(self::OLD_PASSWORD, $fresh->password));
        $this->assertNotSame(self::NEW_PASSWORD, $fresh->getRawOriginal('password'));
        $this->assertSame(self::SUBJECT, $fresh->google_id);
        $this->assertSame(0, $fresh->tokens()->count());
        $this->assertNull($fresh->last_login_at);

        $this->withToken($oldToken)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_old_password_fails_and_new_password_can_login(): void
    {
        $guru = $this->makeUser('guru.password.changed');
        $grant = $this->verifiedGrant();

        $this->resetPassword($grant)->assertOk();
        $this->postJson('/api/login', [
            'username' => $guru->username,
            'password' => self::OLD_PASSWORD,
        ])->assertUnauthorized();

        $this->postJson('/api/login', [
            'username' => $guru->username,
            'password' => self::NEW_PASSWORD,
        ])
            ->assertOk()
            ->assertJsonPath('data.user.id', $guru->id)
            ->assertJsonStructure(['data' => ['token', 'token_type']]);
    }

    public function test_unlinked_google_cannot_verify_or_create_account(): void
    {
        $challenge = $this->issueChallenge();
        $this->mockIdentity('unlinked-token', 'google-sub-not-registered', $challenge['nonce']);

        $this->verify($challenge, 'unlinked-token')->assertUnauthorized();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_verify_does_not_match_an_account_by_google_email(): void
    {
        $guru = $this->makeUser('guru.email.same', 'GURU', 'google-sub-original');
        $guru->forceFill(['google_email' => 'akun.yang.sama@gmail.com'])->save();
        $challenge = $this->issueChallenge();

        $this->mock(GoogleIdentityVerifier::class)
            ->shouldReceive('verifiedIdentity')
            ->once()
            ->with('other-google')
            ->andReturn([
                'subject' => 'google-sub-different',
                'email' => 'akun.yang.sama@gmail.com',
                'nonce' => $challenge['nonce'],
            ]);

        $this->verify($challenge, 'other-google')->assertUnauthorized();
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $guru->fresh()->password));
    }

    public function test_inactive_account_cannot_verify_or_lose_existing_tokens(): void
    {
        $guru = $this->makeUser('guru.reset.inactive', 'GURU', 'google-sub-inactive', 'inactive');
        $guru->createToken('old-device');
        $challenge = $this->issueChallenge();
        $this->mockIdentity('inactive-token', 'google-sub-inactive', $challenge['nonce']);

        $this->verify($challenge, 'inactive-token')->assertForbidden();
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $guru->fresh()->password));
        $this->assertSame(1, $guru->tokens()->count());
    }

    public function test_pending_activation_cannot_verify(): void
    {
        $guru = $this->makeUser('guru.reset.pending', 'GURU', 'google-sub-pending', 'active', true);
        $challenge = $this->issueChallenge();
        $this->mockIdentity('pending-token', 'google-sub-pending', $challenge['nonce']);

        $this->verify($challenge, 'pending-token')->assertForbidden();
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $guru->fresh()->password));
    }

    public function test_unsupported_role_cannot_verify(): void
    {
        $user = $this->makeUser('other.reset.role', 'UNSUPPORTED_ROLE', 'google-sub-unsupported');
        $challenge = $this->issueChallenge();
        $this->mockIdentity('unsupported-token', 'google-sub-unsupported', $challenge['nonce']);

        $this->verify($challenge, 'unsupported-token')->assertForbidden();
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $user->fresh()->password));
    }

    public function test_invalid_google_id_token_is_rejected_during_verify(): void
    {
        $guru = $this->makeUser('guru.bad.google.token');
        $challenge = $this->issueChallenge();

        $this->mock(GoogleIdentityVerifier::class)
            ->shouldReceive('verifiedIdentity')
            ->once()
            ->with('invalid-id-token')
            ->andThrow(ValidationException::withMessages([
                'credential' => 'Token Google tidak valid.',
            ]));

        $this->verify($challenge, 'invalid-id-token')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('credential');
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $guru->fresh()->password));
    }

    public function test_google_token_without_nonce_is_rejected(): void
    {
        $guru = $this->makeUser('guru.missing.nonce');
        $challenge = $this->issueChallenge();
        $this->mockIdentity('token-without-nonce', self::SUBJECT, null);

        $this->verify($challenge, 'token-without-nonce')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('credential');
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $guru->fresh()->password));
    }

    public function test_wrong_nonce_fails_and_consumes_challenge(): void
    {
        $guru = $this->makeUser('guru.wrong.nonce');
        $challenge = $this->issueChallenge();
        $wrongNonce = $challenge['nonce'] === str_repeat('a', 64)
            ? str_repeat('b', 64)
            : str_repeat('a', 64);

        $mock = $this->mock(GoogleIdentityVerifier::class);
        $mock->shouldReceive('verifiedIdentity')
            ->once()->with('wrong-token')
            ->andReturn(['subject' => self::SUBJECT, 'email' => null, 'nonce' => $wrongNonce]);
        $mock->shouldReceive('verifiedIdentity')
            ->once()->with('right-token')
            ->andReturn(['subject' => self::SUBJECT, 'email' => null, 'nonce' => $challenge['nonce']]);

        $this->verify($challenge, 'wrong-token')
            ->assertUnprocessable()->assertJsonValidationErrors('credential');
        $this->verify($challenge, 'right-token')
            ->assertUnprocessable()->assertJsonValidationErrors('credential');
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $guru->fresh()->password));
    }

    public function test_challenge_cannot_be_reused_after_successful_verification(): void
    {
        $this->makeUser('guru.replay.nonce');
        $challenge = $this->issueChallenge();
        $this->mockIdentity('replay-google-token', self::SUBJECT, $challenge['nonce'], 2);

        $this->verify($challenge, 'replay-google-token')->assertOk();
        $this->verify($challenge, 'replay-google-token')
            ->assertUnprocessable()->assertJsonValidationErrors('credential');
    }

    public function test_expired_challenge_cannot_verify_google(): void
    {
        $this->makeUser('guru.expired.nonce');
        $challenge = $this->issueChallenge();
        $this->mockIdentity('expired-token', self::SUBJECT, $challenge['nonce']);

        $this->travel(301)->seconds();
        try {
            $this->verify($challenge, 'expired-token')
                ->assertUnprocessable()->assertJsonValidationErrors('credential');
        } finally {
            $this->travelBack();
        }
    }

    public function test_nonce_from_one_challenge_cannot_verify_another(): void
    {
        $this->makeUser('guru.cross.challenge');
        $challengeA = $this->issueChallenge();
        $challengeB = $this->issueChallenge();
        $this->mockIdentity('token-for-a', self::SUBJECT, $challengeA['nonce']);

        $this->verify($challengeB, 'token-for-a')
            ->assertUnprocessable()->assertJsonValidationErrors('credential');
    }

    public function test_reset_grant_cannot_be_used_twice(): void
    {
        $guru = $this->makeUser('guru.replay.grant');
        $grant = $this->verifiedGrant();

        $this->resetPassword($grant)->assertOk();
        $this->resetPassword($grant, 'PasswordKedua#2026')
            ->assertUnprocessable()->assertJsonValidationErrors('reset_token');
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $guru->fresh()->password));
        $this->assertFalse(Hash::check('PasswordKedua#2026', $guru->fresh()->password));
    }

    public function test_expired_reset_grant_cannot_change_password(): void
    {
        $guru = $this->makeUser('guru.expired.grant');
        $grant = $this->verifiedGrant();

        $this->travel(301)->seconds();
        try {
            $this->resetPassword($grant)
                ->assertUnprocessable()->assertJsonValidationErrors('reset_token');
        } finally {
            $this->travelBack();
        }
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $guru->fresh()->password));
    }

    public function test_account_disabled_after_verify_cannot_reset_password(): void
    {
        $guru = $this->makeUser('guru.disabled.after.verify');
        $grant = $this->verifiedGrant();
        $guru->forceFill(['account_status' => 'inactive'])->save();

        $this->resetPassword($grant)->assertForbidden();
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $guru->fresh()->password));
    }

    public function test_account_requiring_activation_after_verify_cannot_reset(): void
    {
        $guru = $this->makeUser('guru.pending.after.verify');
        $grant = $this->verifiedGrant();
        $guru->forceFill(['requires_activation' => true])->save();

        $this->resetPassword($grant)->assertForbidden();
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $guru->fresh()->password));
    }

    public function test_google_unlinked_after_verify_cannot_reset(): void
    {
        $guru = $this->makeUser('guru.unlinked.after.verify');
        $grant = $this->verifiedGrant();
        $guru->forceFill(['google_id' => null])->save();

        $this->resetPassword($grant)->assertForbidden();
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $guru->fresh()->password));
    }

    public function test_google_link_changed_after_verify_cannot_reset(): void
    {
        $guru = $this->makeUser('guru.changed.google.link');
        $grant = $this->verifiedGrant();
        $guru->forceFill(['google_id' => 'different-google-sub'])->save();

        $this->resetPassword($grant)->assertForbidden();
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $guru->fresh()->password));
    }

    public function test_role_changed_to_unsupported_after_verify_cannot_reset(): void
    {
        $guru = $this->makeUser('guru.changed.role.after.verify');
        $grant = $this->verifiedGrant();
        $unsupported = Role::query()->firstOrCreate(
            ['code' => 'UNSUPPORTED_ROLE'],
            ['name' => 'UNSUPPORTED_ROLE']
        );
        $guru->forceFill(['role_id' => $unsupported->id])->save();

        $this->resetPassword($grant)->assertForbidden();
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $guru->fresh()->password));
    }

    public function test_grant_is_not_consumed_when_password_confirmation_fails(): void
    {
        $guru = $this->makeUser('guru.retry.password.validation');
        $grant = $this->verifiedGrant();

        $this->postJson('/api/recovery/google/password', [
            'reset_token' => $grant,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => 'PasswordBerbeda#2026',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->resetPassword($grant)->assertOk();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $guru->fresh()->password));
    }

    public function test_tu_can_verify_and_reset_password(): void
    {
        $user = $this->makeUser('tu.reset.google', 'TU', 'google-sub-tu');
        $grant = $this->verifiedGrant('google-sub-tu', 'tu-id-token');

        $this->resetPassword($grant)->assertOk();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
    }

    public function test_kepala_sekolah_can_verify_and_reset_password(): void
    {
        $user = $this->makeUser('kepala.reset.google', 'KEPALA_SEKOLAH', 'google-sub-kepala');
        $grant = $this->verifiedGrant('google-sub-kepala', 'kepala-id-token');

        $this->resetPassword($grant)->assertOk();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
    }

    public function test_siswa_can_verify_and_reset_password(): void
    {
        $user = $this->makeUser('siswa.reset.google', 'SISWA', 'google-sub-siswa');
        $grant = $this->verifiedGrant('google-sub-siswa', 'siswa-id-token');

        $this->resetPassword($grant)->assertOk();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
    }
}
