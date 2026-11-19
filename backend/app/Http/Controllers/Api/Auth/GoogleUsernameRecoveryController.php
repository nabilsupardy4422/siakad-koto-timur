<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\GoogleIdentityVerifier;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoogleUsernameRecoveryController extends Controller
{
    // =========================================
    // PEMULIHAN USERNAME MELALUI GOOGLE
    // =========================================

    public function __invoke(
        Request $request,
        GoogleIdentityVerifier $verifier
    ): JsonResponse {

        // =====================================
        // 1. VALIDASI GOOGLE CREDENTIAL
        // =====================================

        $validated = $request->validate([
            'credential' => [
                'required',
                'string',
                'max:8192',
            ],
        ]);

        // =====================================
        // 2. VERIFIKASI IDENTITAS GOOGLE
        // =====================================

        // Google ID Token harus diverifikasi
        // menggunakan library Google di backend.
        //
        // Jangan mempercayai alamat email
        // yang dikirim langsung oleh frontend.

        $googleSubject = $verifier->subject(
            $validated['credential']
        );

        // =====================================
        // 3. CARI AKUN SIAKAD
        // =====================================

        $user = User::query()
            ->with('role')
            ->where(
                'google_id',
                $googleSubject
            )
            ->first();

        // Tidak melakukan registrasi otomatis.
        // Tidak mencocokkan berdasarkan email.

        if (! $user) {
            return ApiResponse::message(
                'Akun Google ini belum terhubung dengan akun SIAKAD yang dapat dipulihkan.',
                null,
                401
            );
        }

        // =====================================
        // 4. PERIKSA STATUS AKUN
        // =====================================

        if (
            $user->account_status !== 'active' ||
            $user->requires_activation
        ) {
            return ApiResponse::message(
                'Akun SIAKAD tidak dapat dipulihkan melalui Google. Hubungi Tata Usaha.',
                null,
                403
            );
        }

        // =====================================
        // 5. PERIKSA ROLE
        // =====================================

        $allowedRoles = [
            'TU',
            'KEPALA_SEKOLAH',
            'GURU',
            'SISWA',
        ];

        if (
            ! in_array(
                $user->role?->code,
                $allowedRoles,
                true
            )
        ) {
            return ApiResponse::message(
                'Akun SIAKAD tidak dapat dipulihkan melalui Google. Hubungi Tata Usaha.',
                null,
                403
            );
        }

        // =====================================
        // 6. KEMBALIKAN USERNAME
        // =====================================

        // Username hanya diberikan setelah
        // identitas Google berhasil diverifikasi
        // dan akun memenuhi seluruh persyaratan.
        //
        // Jangan kirim password, google_id,
        // token Sanctum, atau data sensitif lain.

        return ApiResponse::success([
            'username' => $user->username,
        ]);
    }
}
