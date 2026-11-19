<?php

namespace App\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

final class GooglePasswordRecoveryChallenge
{
    private const TTL_SECONDS = 300;

    private const CACHE_PREFIX = 'siakad:google-password-recovery:';

    /**
     * Terbitkan challenge untuk satu proses reset password.
     *
     * @return array{challenge_id: string, nonce: string, expires_in: int}
     */
    public function issue(): array
    {
        $challengeId = bin2hex(random_bytes(24));
        $nonce = bin2hex(random_bytes(32));

        // Hanya hash nonce yang disimpan di cache server.
        $stored = Cache::put(
            $this->cacheKey($challengeId),
            hash('sha256', $nonce),
            self::TTL_SECONDS
        );

        if (! $stored) {
            abort(503, 'Layanan pemulihan akun sementara tidak tersedia.');
        }

        return [
            'challenge_id' => $challengeId,
            'nonce' => $nonce,
            'expires_in' => self::TTL_SECONDS,
        ];
    }

    /**
     * Cocokkan nonce dari klaim ID token yang SUDAH diverifikasi.
     * Challenge dihapus pada percobaan pertama, termasuk jika nonce salah.
     */
    public function consume(
        string $challengeId,
        string $verifiedTokenNonce
    ): bool {
        if (
            preg_match('/\A[a-f0-9]{48}\z/D', $challengeId) !== 1 ||
            preg_match('/\A[a-f0-9]{64}\z/D', $verifiedTokenNonce) !== 1
        ) {
            return false;
        }

        $key = $this->cacheKey($challengeId);

        try {
            // Lock mencegah dua request memakai challenge yang sama.
            return Cache::lock($key . ':lock', 10)->block(
                2,
                static function () use ($key, $verifiedTokenNonce): bool {
                    $expectedHash = Cache::pull($key);

                    return is_string($expectedHash) &&
                        hash_equals(
                            $expectedHash,
                            hash('sha256', $verifiedTokenNonce)
                        );
                }
            );
        } catch (LockTimeoutException) {
            return false;
        }
    }

    private function cacheKey(string $challengeId): string
    {
        return self::CACHE_PREFIX . hash('sha256', $challengeId);
    }
}
