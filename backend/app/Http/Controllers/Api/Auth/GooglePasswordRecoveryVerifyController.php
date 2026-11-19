<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\GoogleIdentityVerifier;
use App\Services\GooglePasswordRecoveryChallenge;
use App\Services\GooglePasswordRecoveryGrant;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Tahap pertama reset password: verifikasi Google terlebih dahulu.
 * Tidak mengubah password dan tidak membuat sesi/login Sanctum.
 */
class GooglePasswordRecoveryVerifyController extends Controller
{
    public function __invoke(
        Request $request,
        GoogleIdentityVerifier $verifier,
        GooglePasswordRecoveryChallenge $challenge,
        GooglePasswordRecoveryGrant $grant
    ): JsonResponse {
        // Challenge harus diterbitkan oleh endpoint challenge sebelum
        // frontend meminta Google ID token dengan klaim nonce.
        $validated = $request->validate([
            'challenge_id' => [
                'required',
                'string',
                'regex:/\A[a-f0-9]{48}\z/D',
            ],
            'credential' => [
                'required',
                'string',
                'max:8192',
            ],
        ]);

        // Periksa signature, audience, issuer, masa berlaku, dan subject
        // di backend. Email Google TIDAK digunakan untuk mencari user.
        $identity = $verifier->verifiedIdentity($validated['credential']);
        $verifiedNonce = $identity['nonce'] ?? null;

        // Nonce harus berasal dari ID token yang sudah terverifikasi.
        // Challenge dihabiskan secara atomik pada percobaan pencocokan.
        if (
            ! is_string($verifiedNonce) ||
            ! $challenge->consume($validated['challenge_id'], $verifiedNonce)
        ) {
            throw ValidationException::withMessages([
                'credential' => 'Verifikasi Google tidak valid atau sudah kedaluwarsa. Mulai kembali dari awal.',
            ]);
        }

        $googleSubject = $identity['subject'];

        $user = User::query()
            ->with('role')
            ->where('google_id', $googleSubject)
            ->first();

        if (! $user) {
            return ApiResponse::message(
                'Akun Google ini belum tertaut dengan akun SIAKAD yang dapat dipulihkan.',
                null,
                401
            )->header('Cache-Control', 'no-store, private');
        }

        if (
            $user->account_status !== 'active' ||
            $user->requires_activation ||
            ! in_array($user->role?->code, [
                'TU',
                'KEPALA_SEKOLAH',
                'GURU',
                'SISWA',
            ], true)
        ) {
            return ApiResponse::message(
                'Akun SIAKAD tidak dapat dipulihkan melalui Google. Hubungi Tata Usaha.',
                null,
                403
            )->header('Cache-Control', 'no-store, private');
        }

        // Grant 5 menit, hanya sekali pakai, terikat user + Google sub.
        // Token ini rahasia dan BUKAN token autentikasi Sanctum.
        $resetGrant = $grant->issue((int) $user->id, $googleSubject);

        return ApiResponse::success([
            'username' => $user->username,
            'reset_token' => $resetGrant['reset_token'],
            'expires_in' => $resetGrant['expires_in'],
        ])
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }
}
