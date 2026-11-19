<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class UserActivationCodeStatusController extends Controller
{
    /**
     * Memeriksa keberadaan kode aktivasi aktif.
     *
     * Kode dianggap aktif jika:
     * - Akun Guru/Siswa masih aktif.
     * - Akun masih memerlukan aktivasi.
     * - Token belum digunakan.
     * - Token belum kedaluwarsa.
     * - Percobaan salah masih di bawah batas.
     *
     * Endpoint ini hanya boleh diakses
     * TU melalui middleware route.
     */
    public function __invoke(User $user): JsonResponse
    {
        $user->loadMissing('role');

        $eligible =
            $user->account_status === 'active' &&
            (bool) $user->requires_activation &&
            in_array(
                $user->role?->code,
                ['GURU', 'SISWA'],
                true
            );

        $hasActiveCode = false;

        if ($eligible) {
            $hasActiveCode = DB::table(
                'user_activation_tokens'
            )
                ->where('user_id', $user->id)
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->where('failed_attempts', '<', 5)
                ->exists();
        }

        return ApiResponse::success([
            'has_active_code' => $hasActiveCode,
        ])
            ->header(
                'Cache-Control',
                'private, no-store, max-age=0'
            )
            ->header('Pragma', 'no-cache');
    }
}
