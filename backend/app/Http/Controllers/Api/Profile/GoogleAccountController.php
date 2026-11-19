<?php

namespace App\Http\Controllers\Api\Profile;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\GoogleIdentityVerifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class GoogleAccountController extends Controller
{
    // =========================================
    // STATUS HUBUNGAN GOOGLE
    // =========================================

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $linked = $user->google_id !== null;

        return response()->json([
            'data' => [
                'google_linked' => $linked,
                'google_email' => $linked
                    ? $user->google_email
                    : null,
            ],
        ]);
    }

    // =========================================
    // HUBUNGKAN / VERIFIKASI ULANG GOOGLE
    // =========================================

    public function link(
        Request $request,
        GoogleIdentityVerifier $verifier
    ): JsonResponse {
        $validated = $request->validate([
            'credential' => [
                'required',
                'string',
                'max:8192',
            ],
            'current_password' => [
                'nullable',
                'string',
            ],
        ]);

        // Identitas dan email Google harus
        // diverifikasi oleh backend.
        $identity = $verifier->verifiedIdentity(
            $validated['credential']
        );

        $googleSubject = $identity['subject'];
        $googleEmail = $identity['email'];

        $currentPassword =
            $validated['current_password'] ?? null;

        $userId = $request->user()->getKey();

        try {
            DB::transaction(function () use (
                $userId,
                $googleSubject,
                $googleEmail,
                $currentPassword
            ): void {
                $user = User::query()
                    ->lockForUpdate()
                    ->findOrFail($userId);

                // =====================================
                // 1. CEGAH PERGANTIAN GOOGLE DIAM-DIAM
                // =====================================

                if (
                    $user->google_id !== null &&
                    ! hash_equals(
                        $user->google_id,
                        $googleSubject
                    )
                ) {
                    abort(
                        409,
                        'Akun SIAKAD ini sudah terhubung dengan Google lain.'
                    );
                }

                // =====================================
                // 2. KONFIRMASI PASSWORD SAAT LINK PERTAMA
                // =====================================

                if ($user->google_id === null) {
                    if (
                        ! is_string($currentPassword) ||
                        $currentPassword === ''
                    ) {
                        throw ValidationException::withMessages([
                            'current_password' =>
                                'Masukkan password SIAKAD untuk menghubungkan Google.',
                        ]);
                    }

                    if (
                        ! Hash::check(
                            $currentPassword,
                            $user->password
                        )
                    ) {
                        throw ValidationException::withMessages([
                            'current_password' =>
                                'Password SIAKAD tidak sesuai.',
                        ]);
                    }
                }

                // =====================================
                // 3. CEGAH SATU GOOGLE DIPAKAI DUA AKUN
                // =====================================

                $alreadyLinked = User::query()
                    ->where('google_id', $googleSubject)
                    ->where('id', '!=', $userId)
                    ->exists();

                if ($alreadyLinked) {
                    abort(
                        409,
                        'Akun Google sudah ditautkan ke akun SIAKAD lain.'
                    );
                }

                // =====================================
                // 4. SIMPAN IDENTITAS GOOGLE
                // =====================================

                // Jika belum tertaut:
                //   simpan Google ID dan email.
                //
                // Jika sudah tertaut dengan ID sama:
                //   perbarui email terverifikasi.
                //
                // Identitas SIAKAD tidak diubah.

                $user->forceFill([
                    'google_id' => $googleSubject,
                    'google_email' => $googleEmail,
                ])->save();
            });
        } catch (UniqueConstraintViolationException) {
            abort(
                409,
                'Akun Google sudah ditautkan ke akun SIAKAD lain.'
            );
        }

        return response()->json([
            'message' =>
                'Akun Google berhasil diverifikasi dan dihubungkan.',
            'data' => [
                'google_linked' => true,
                'google_email' => $googleEmail,
            ],
        ]);
    }

    // =========================================
    // LEPAS HUBUNGAN GOOGLE
    // =========================================

    public function unlink(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => [
                'required',
                'string',
            ],
        ]);

        $userId = $request->user()->getKey();

        DB::transaction(function () use (
            $userId,
            $validated
        ): void {
            $user = User::query()
                ->lockForUpdate()
                ->findOrFail($userId);

            // Verifikasi password SIAKAD terbaru
            // sebelum melepas Google.
            if (
                ! Hash::check(
                    $validated['current_password'],
                    $user->password
                )
            ) {
                throw ValidationException::withMessages([
                    'current_password' =>
                        'Password SIAKAD tidak sesuai.',
                ]);
            }

            if ($user->google_id === null) {
                abort(
                    409,
                    'Akun Google belum terhubung.'
                );
            }

            // Bersihkan seluruh identitas Google.
            $user->forceFill([
                'google_id' => null,
                'google_email' => null,
            ])->save();
        });

        return response()->json([
            'message' =>
                'Hubungan akun Google berhasil dilepas.',
            'data' => [
                'google_linked' => false,
                'google_email' => null,
            ],
        ]);
    }
}
