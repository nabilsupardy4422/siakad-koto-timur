<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\GooglePasswordRecoveryGrant;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Tahap kedua pemulihan password: gunakan reset_token yang diterbitkan
 * setelah Google ID token + nonce diverifikasi oleh endpoint /verify.
 *
 * Endpoint ini tidak menerima credential Google ataupun challenge_id.
 */
class GooglePasswordRecoveryController extends Controller
{
    public function __invoke(
        Request $request,
        GooglePasswordRecoveryGrant $grant
    ): JsonResponse {
        $validated = $request->validate([
            'reset_token' => [
                'required',
                'string',
                'regex:/\A[a-f0-9]{64}\z/D',
            ],
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(12)
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
        ]);

        // Hanya grant yang terbit dari verifikasi Google + nonce yang sah
        // yang diterima. Grant ini otomatis hangus pada percobaan pertama.
        $grantData = $grant->consume($validated['reset_token']);

        if ($grantData === null) {
            throw ValidationException::withMessages([
                'reset_token' => 'Izin pemulihan tidak valid, sudah digunakan, atau kedaluwarsa. Ulangi verifikasi Google.',
            ]);
        }

        // User tetap diperiksa ulang karena status, role, atau tautan Google
        // bisa berubah sejak grant diterbitkan. Password dan pencabutan token
        // dilakukan dalam satu transaksi database.
        $result = DB::transaction(function () use ($grantData, $validated): int {
            $user = User::query()
                ->with('role')
                ->whereKey($grantData['user_id'])
                ->lockForUpdate()
                ->first();

            if (! $user) {
                return 403;
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
                return 403;
            }

            // Tautan Google HARUS masih sama dengan saat verifikasi.
            // Alamat email tidak pernah dipakai sebagai kunci identitas.
            $currentGoogleSubject = $user->google_id;

            if (
                ! is_string($currentGoogleSubject) ||
                $currentGoogleSubject === '' ||
                ! hash_equals(
                    $grantData['google_subject_hash'],
                    hash('sha256', $currentGoogleSubject)
                )
            ) {
                return 403;
            }

            // Model User memakai cast 'password' => 'hashed'.
            $user->forceFill([
                'password' => $validated['password'],
            ])->save();

            // Cabut semua Sanctum personal access token lama.
            $user->tokens()->delete();

            return 200;
        });

        if ($result !== 200) {
            return ApiResponse::message(
                'Pemulihan akun tidak dapat diselesaikan. Ulangi verifikasi Google atau hubungi Tata Usaha.',
                null,
                $result
            )->header('Cache-Control', 'no-store, private');
        }

        // Jangan membuat sesi atau token login baru di alur pemulihan.
        return ApiResponse::message(
            'Password SIAKAD berhasil diperbarui. Silakan login kembali.',
            null,
            200
        )->header('Cache-Control', 'no-store, private');
    }
}
