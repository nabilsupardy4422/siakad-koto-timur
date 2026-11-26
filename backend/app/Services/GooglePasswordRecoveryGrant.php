<?php

namespace App\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Izin sementara untuk menyelesaikan reset password SETELAH
 * Google ID token (termasuk nonce challenge) diverifikasi di backend.
 *
 * Token izin bersifat acak, berlaku singkat, dan hanya dapat dipakai sekali.
 * Ini BUKAN token autentikasi Sanctum.
 */
final class GooglePasswordRecoveryGrant
{
    private const TTL_SECONDS = 300;

    private const CACHE_PREFIX = 'siakad:google-password-reset-grant:';

    /**
     * Terbitkan izin setelah controller memastikan akun Google terhubung,
     * akun aktif, telah diaktivasi, dan role diperbolehkan.
     *
     * @return array{reset_token: string, expires_in: int}
     */
    public function issue(int $userId, string $googleSubject, string $passwordHash): array
    {
        if ($userId < 1 || $googleSubject === '' || $passwordHash === '') {
            throw new InvalidArgumentException('Identitas pemulihan tidak valid.');
        }

        $resetToken = bin2hex(random_bytes(32));

        // Jangan menyimpan token mentah ataupun Google Subject ID mentah.
        // Key cache berasal dari hash token; nilai cache diikat ke akun
        // dan Google Subject ID yang telah diverifikasi.
        $stored = Cache::put(
            $this->cacheKey($resetToken),
            [
                'user_id' => $userId,
                'google_subject_hash' => hash('sha256', $googleSubject),
                'credential_version' => hash_hmac('sha256', $passwordHash, (string) config('app.key')),
            ],
            self::TTL_SECONDS
        );

        if (! $stored) {
            abort(503, 'Layanan pemulihan akun sementara tidak tersedia.');
        }

        return [
            'reset_token' => $resetToken,
            'expires_in' => self::TTL_SECONDS,
        ];
    }

    /**
     * Habiskan izin secara atomik, termasuk bila data cache tidak valid.
     * Controller reset perlu memeriksa ulang akun, role, status, dan
     * kecocokan google_subject_hash DI DALAM transaksi database.
     *
     * @return array{user_id: int, google_subject_hash: string, credential_version: string}|null
     */
    public function consume(string $resetToken): ?array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $resetToken) !== 1) {
            return null;
        }

        $key = $this->cacheKey($resetToken);

        try {
            return Cache::lock($key . ':lock', 10)->block(
                2,
                static function () use ($key): ?array {
                    $data = Cache::pull($key);

                    if (! is_array($data)) {
                        return null;
                    }

                    $userId = $data['user_id'] ?? null;
                    $subjectHash = $data['google_subject_hash'] ?? null;
                    $version = $data['credential_version'] ?? null;

                    if (
                        ! is_string($version) || preg_match('/\A[a-f0-9]{64}\z/D', $version) !== 1 ||
                        ! is_int($userId) ||
                        $userId < 1 ||
                        ! is_string($subjectHash) ||
                        preg_match('/\A[a-f0-9]{64}\z/D', $subjectHash) !== 1
                    ) {
                        return null;
                    }

                    return [
                        'user_id' => $userId,
                        'google_subject_hash' => $subjectHash,
                        'credential_version' => $version,
                    ];
                }
            );
        } catch (LockTimeoutException) {
            return null;
        }
    }

    private function cacheKey(string $resetToken): string
    {
        return self::CACHE_PREFIX . hash('sha256', $resetToken);
    }
}
