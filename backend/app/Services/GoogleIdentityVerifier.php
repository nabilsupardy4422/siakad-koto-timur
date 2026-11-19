<?php

namespace App\Services;

use Google\Auth\AccessToken;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class GoogleIdentityVerifier
{
    /**
     * Kompatibilitas dengan penautan, login, dan pemulihan username.
     *
     * Mengembalikan Google Subject ID (sub), bukan alamat email.
     */
    public function subject(string $credential): string
    {
        return $this->verifiedIdentity($credential)['subject'];
    }

    /**
     * Verifikasi Google ID token melalui library Google Auth.
     *
     * Nonce hanya DIBACA dari token yang telah diverifikasi. Untuk reset
     * password, nonce wajib dicocokkan dengan challenge sekali pakai oleh
     * controller pemulihan, bukan oleh halaman React.
     *
     * @return array{subject: string, email: ?string, nonce: ?string}
     */
    public function verifiedIdentity(string $credential): array
    {
        $clientId = config('google_identity.client_id');

        if (! is_string($clientId) || trim($clientId) === '') {
            abort(503, 'Verifikasi Google belum dikonfigurasi.');
        }

        try {
            $verifier = new AccessToken();

            $claims = $verifier->verify($credential, [
                'audience' => $clientId,
            ]);
        } catch (RuntimeException $exception) {
            // Jangan pernah mencatat ID token atau kredensial ke log.
            Log::warning('Layanan verifikasi Google tidak tersedia.', [
                'exception_type' => get_class($exception),
            ]);

            abort(
                503,
                'Layanan verifikasi Google sementara tidak tersedia. Coba lagi.'
            );
        } catch (Throwable $exception) {
            Log::warning('Verifikasi Google ID Token gagal.', [
                'exception_type' => get_class($exception),
            ]);

            $claims = false;
        }

        $audience = is_array($claims)
            ? ($claims['aud'] ?? null)
            : null;

        $issuer = is_array($claims)
            ? ($claims['iss'] ?? null)
            : null;

        $subject = is_array($claims)
            ? ($claims['sub'] ?? null)
            : null;

        $expiresAt = is_array($claims)
            ? ($claims['exp'] ?? null)
            : null;

        $validAudience = is_string($audience)
            && hash_equals($clientId, $audience);

        $validIssuer = in_array($issuer, [
            'accounts.google.com',
            'https://accounts.google.com',
        ], true);

        $validSubject = is_string($subject)
            && $subject !== ''
            && strlen($subject) <= 255;

        $validExpiration = is_numeric($expiresAt)
            && (int) $expiresAt > time();

        if (
            ! $validAudience ||
            ! $validIssuer ||
            ! $validSubject ||
            ! $validExpiration
        ) {
            throw ValidationException::withMessages([
                'credential' =>
                    'Verifikasi Google tidak valid atau sudah kedaluwarsa. Coba lagi.',
            ]);
        }

        // Email hanya disimpan bila Google menyatakan sudah terverifikasi.
        $rawEmail = $claims['email'] ?? null;
        $emailVerified = $claims['email_verified'] ?? false;

        $isEmailVerified = $emailVerified === true
            || $emailVerified === 'true';

        $googleEmail = null;

        if ($isEmailVerified && is_string($rawEmail)) {
            $candidate = trim($rawEmail);

            if (
                $candidate !== '' &&
                strlen($candidate) <= 254 &&
                filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false
            ) {
                $googleEmail = $candidate;
            }
        }

        // Tidak mewajibkan nonce secara global karena login dan penautan
        // Google yang sudah ada tidak menggunakan challenge pemulihan.
        $rawNonce = $claims['nonce'] ?? null;
        $nonce = null;

        if (
            is_string($rawNonce) &&
            $rawNonce !== '' &&
            strlen($rawNonce) <= 255
        ) {
            $nonce = $rawNonce;
        }

        return [
            'subject' => $subject,
            'email' => $googleEmail,
            'nonce' => $nonce,
        ];
    }
}
