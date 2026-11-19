<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CancelUserActivationCodeController extends Controller
{
    /**
     * Membatalkan kode aktivasi akun oleh TU.
     *
     * Hanya berlaku untuk Guru/Siswa
     * yang belum melakukan aktivasi.
     *
     * Pembatalan kode tidak menghapus akun,
     * tidak mengganti password, dan
     * tidak mengubah status akun.
     */
    public function __invoke(
        Request $request,
        User $user
    ): JsonResponse {
        // =====================================
        // PROSES PEMBATALAN DALAM TRANSAKSI
        // =====================================

        $result = DB::transaction(
            function () use ($user): array {

                // Kunci akun agar pembatalan
                // tidak berbenturan dengan
                // aktivasi atau penerbitan kode.

                $target = User::query()
                    ->with('role')
                    ->lockForUpdate()
                    ->findOrFail($user->id);

                // =================================
                // VALIDASI AKUN
                // =================================

                // Hanya akun Guru atau Siswa
                // yang belum diaktivasi.

                if (
                    ! $target->requires_activation ||
                    ! in_array(
                        $target->role?->code,
                        ['GURU', 'SISWA'],
                        true
                    )
                ) {
                    return [
                        'allowed' => false,
                        'revoked' => false,
                    ];
                }

                // =================================
                // HAPUS KODE AKTIVASI
                // =================================

                // Database hanya menyimpan hash
                // kode, bukan kode aslinya.

                $deleted = DB::table(
                    'user_activation_tokens'
                )
                    ->where(
                        'user_id',
                        $target->id
                    )
                    ->delete();

                // requires_activation tetap true.
                // Akun tetap belum bisa login.

                return [
                    'allowed' => true,
                    'revoked' => $deleted > 0,
                ];
            },
            3
        );

        // =====================================
        // AKUN TIDAK MEMENUHI SYARAT
        // =====================================

        if (! $result['allowed']) {
            return ApiResponse::message(
                'Pembatalan hanya tersedia untuk akun Guru/Siswa yang belum diaktivasi.',
                null,
                422
            );
        }

        // =====================================
        // AUDIT LOG
        // =====================================

        // Catat pembatalan tanpa menyimpan
        // kode aktivasi atau password.

        if ($result['revoked']) {
            Log::info(
                'User activation code cancelled by TU',
                [
                    'actor_user_id' =>
                        $request->user()->id,

                    'target_user_id' =>
                        $user->id,
                ]
            );
        }

        // =====================================
        // RESPONSE
        // =====================================

        return ApiResponse::message(
            $result['revoked']
                ? 'Kode aktivasi berhasil dibatalkan. Akun tetap belum diaktivasi.'
                : 'Tidak ada kode aktivasi yang dapat dibatalkan untuk akun ini.',
            [
                'revoked' => $result['revoked'],
            ]
        )->header(
            'Cache-Control',
            'private, no-store, max-age=0'
        );
    }
}
