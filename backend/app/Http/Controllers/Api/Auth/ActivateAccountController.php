<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;

class ActivateAccountController extends Controller
{
    public function __invoke(
        Request $request
    ): JsonResponse {
        // ====================================
        // VALIDASI REQUEST
        // ====================================

        $validated = $request->validate([
            'username' => [
                'required',
                'string',
                'max:100',
            ],

            'activation_code' => [
                'required',
                'string',
                'max:80',
            ],

            'password' => [
                'required',
                'confirmed',
                Password::defaults(),
            ],
        ]);

        $username = trim(
            $validated['username']
        );

        // Kode dapat ditulis dengan / tanpa
        // tanda hubung dan spasi.
        $code = strtoupper(
            preg_replace(
                '/[\s-]+/',
                '',
                $validated['activation_code']
            )
        );

        // Rate limiting per username dan IP.
        $limitKey = 'activate-account:' . hash(
            'sha256',
            mb_strtolower($username) .
                '|' .
                $request->ip()
        );

        if (
            RateLimiter::tooManyAttempts(
                $limitKey,
                5
            )
        ) {
            return ApiResponse::message(
                'Terlalu banyak percobaan. Coba lagi beberapa saat atau hubungi TU.',
                null,
                429
            );
        }

        // Kode harus berisi 32 karakter HEX.
        if (
            ! preg_match(
                '/\A[0-9A-F]{32}\z/',
                $code
            )
        ) {
            RateLimiter::hit(
                $limitKey,
                15 * 60
            );

            return $this->invalidCode();
        }

        $codeHash = hash(
            'sha256',
            $code
        );

        // ====================================
        // AKTIVASI DALAM TRANSAKSI
        // ====================================

        $activatedUserId = DB::transaction(
            function () use (
                $username,
                $codeHash,
                $validated
            ): ?int {
                // Kunci akun untuk mencegah
                // penggunaan kode secara bersamaan.
                $user = User::query()
                    ->with('role')
                    ->where(
                        'username',
                        $username
                    )
                    ->lockForUpdate()
                    ->first();

                if (
                    ! $user ||
                    $user->account_status !== 'active' ||
                    ! $user->requires_activation ||
                    ! in_array(
                        $user->role?->code,
                        ['GURU', 'SISWA'],
                        true
                    )
                ) {
                    return null;
                }

                // Kunci token aktivasi pengguna.
                $token = DB::table(
                    'user_activation_tokens'
                )
                    ->where(
                        'user_id',
                        $user->id
                    )
                    ->lockForUpdate()
                    ->first();

                if (
                    ! $token ||
                    $token->used_at !== null ||
                    Carbon::parse(
                        $token->expires_at
                    )->isPast() ||
                    $token->failed_attempts >= 5
                ) {
                    return null;
                }

                // Bandingkan hash kode.
                if (
                    ! hash_equals(
                        $token->token_hash,
                        $codeHash
                    )
                ) {
                    DB::table(
                        'user_activation_tokens'
                    )
                        ->where(
                            'id',
                            $token->id
                        )
                        ->increment(
                            'failed_attempts'
                        );

                    return null;
                }

                // ====================================
                // SIMPAN PASSWORD PRIBADI
                // ====================================

                // Model User menggunakan cast:
                // password => hashed.
                $user->forceFill([
                    'password' =>
                        $validated['password'],

                    'requires_activation' =>
                        false,

                    'activated_at' =>
                        now(),
                ])->save();

                // Tandai kode sudah dipakai.
                DB::table(
                    'user_activation_tokens'
                )
                    ->where(
                        'id',
                        $token->id
                    )
                    ->update([
                        'used_at' =>
                            now(),

                        'updated_at' =>
                            now(),
                    ]);

                // Cabut token API lama
                // apabila ada.
                $user->tokens()->delete();

                return (int) $user->id;
            }
        );

        // ====================================
        // AKTIVASI GAGAL
        // ====================================

        if ($activatedUserId === null) {
            RateLimiter::hit(
                $limitKey,
                15 * 60
            );

            return $this->invalidCode();
        }

        // ====================================
        // AKTIVASI BERHASIL
        // ====================================

        RateLimiter::clear($limitKey);

        // Tidak menyimpan password atau kode
        // asli dalam log.
        Log::info(
            'User activated own account',
            [
                'target_user_id' =>
                    $activatedUserId,
            ]
        );

        return ApiResponse::message(
            'Aktivasi berhasil. Silakan login dengan username dan password baru Anda.',
            null
        );
    }

    private function invalidCode(): JsonResponse
    {
        return ApiResponse::message(
            'Kode aktivasi tidak valid, kedaluwarsa, sudah digunakan, atau akun tidak memenuhi syarat. Hubungi TU jika perlu kode baru.',
            null,
            422
        );
    }
}
