<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\Permissions;
use App\Support\UserAssignments;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class LoginController extends Controller
{
    // Maksimal 5 password salah untuk
    // kombinasi username dan alamat IP.
    private const MAX_ATTEMPTS_PER_ACCOUNT_IP = 5;

    // Perlindungan tambahan pada satu alamat IP.
    // Nilai ini dapat disesuaikan dengan kondisi
    // jaringan bersama di lingkungan sekolah.
    private const MAX_ATTEMPTS_PER_IP = 120;

    // Masa pembatasan: 15 menit.
    private const LOCK_SECONDS = 900;

    public function __invoke(
        LoginRequest $request
    ): JsonResponse {
        $credentials = $request->validated();

        // =====================================
        // RATE LIMIT KEYS
        // =====================================

        $ip = $request->ip() ?? 'unknown';

        // Jangan menggunakan username
        // maupun IP mentah sebagai key cache.
        $accountKey = 'siakad:login:account:' . hash(
            'sha256',
            mb_strtolower(
                trim($credentials['username'])
            ) . '|' . $ip
        );

        $ipKey = 'siakad:login:ip:' . hash(
            'sha256',
            $ip
        );

        // =====================================
        // CEK PEMBATASAN LOGIN
        // =====================================

        if (
            RateLimiter::tooManyAttempts(
                $accountKey,
                self::MAX_ATTEMPTS_PER_ACCOUNT_IP
            ) ||
            RateLimiter::tooManyAttempts(
                $ipKey,
                self::MAX_ATTEMPTS_PER_IP
            )
        ) {
            $retryAfter = max(
                1,
                RateLimiter::availableIn($accountKey),
                RateLimiter::availableIn($ipKey)
            );

            return ApiResponse::message(
                'Terlalu banyak percobaan login. Coba lagi beberapa saat.',
                null,
                429
            )->header(
                'Retry-After',
                (string) $retryAfter
            );
        }

        // =====================================
        // CARI USER
        // =====================================

        $user = User::with([
            'role',
            'guru',
        ])
            ->where(
                'username',
                $credentials['username']
            )
            ->first();

        // =====================================
        // VERIFIKASI PASSWORD
        // =====================================

        if (
            ! $user ||
            ! Hash::check(
                $credentials['password'],
                $user->password
            )
        ) {
            // Hanya password salah yang
            // menambah penghitung percobaan.
            RateLimiter::hit(
                $accountKey,
                self::LOCK_SECONDS
            );

            RateLimiter::hit(
                $ipKey,
                self::LOCK_SECONDS
            );

            return ApiResponse::message(
                'Username atau password tidak valid.',
                null,
                401
            );
        }

        // =====================================
        // CEK STATUS AKUN
        // =====================================

        if ($user->account_status !== 'active') {
            return ApiResponse::message(
                'Akun tidak aktif.',
                null,
                403
            );
        }

        // =====================================
        // CEK AKTIVASI
        // =====================================

        if ($user->requires_activation) {
            return ApiResponse::message(
                'Akun belum diaktivasi. Gunakan kode aktivasi dari Tata Usaha.',
                null,
                403
            );
        }

        // =====================================
        // LOGIN BERHASIL
        // =====================================

        // Bersihkan percobaan gagal untuk
        // kombinasi akun dan IP ini.
        RateLimiter::clear($accountKey);

        $user->update([
            'last_login_at' => now(),
        ]);

        $token = $user
            ->createToken('siakad-api')
            ->plainTextToken;

        return ApiResponse::success([
            'token' => $token,
            'token_type' => 'Bearer',

            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'name' => $user->name,
                'email' => $user->email,
                'account_status' =>
                    $user->account_status,

                'role' => [
                    'code' => $user->role->code,
                    'name' => $user->role->name,
                ],
            ],

            'permissions' => Permissions::forRole(
                $user->role->code
            ),

            'assignments' => UserAssignments::forUser(
                $user
            ),
        ]);
    }
}
