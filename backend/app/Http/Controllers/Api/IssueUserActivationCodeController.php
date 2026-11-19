<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserActivationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IssueUserActivationCodeController extends Controller
{
    public function __invoke(
        Request $request,
        User $user,
        UserActivationService $activationService
    ): JsonResponse {
        $actor = $request->user();

        $result = DB::transaction(function () use (
            $user,
            $actor,
            $activationService
        ): ?array {
            // Mencegah penerbitan ulang bersamaan
            // dengan proses aktivasi pengguna.
            $target = User::query()
                ->with('role')
                ->lockForUpdate()
                ->findOrFail($user->id);

            // Hanya Guru/Siswa yang belum aktivasi.
            if (
                $target->account_status !== 'active' ||
                ! $target->requires_activation ||
                ! in_array(
                    $target->role->code,
                    ['GURU', 'SISWA'],
                    true
                )
            ) {
                return null;
            }

            $issued = $activationService->issue(
                $target,
                (int) $actor->id
            );

            return [
                'user_id' => $target->id,
                'username' => $target->username,
                'name' => $target->name,
                'role_code' => $target->role->code,
                ...$issued,
            ];
        });

        if ($result === null) {
            return ApiResponse::message(
                'Kode hanya dapat diterbitkan untuk akun Guru/Siswa aktif yang belum diaktivasi.',
                null,
                422
            );
        }

        // Log hanya identitas akun.
        // Jangan mencatat kode aktivasi.
        Log::info(
            'User activation code issued by TU',
            [
                'actor_user_id' => $actor->id,
                'target_user_id' => $result['user_id'],
            ]
        );

        // Respons tidak boleh disimpan dalam cache.
        // Kode hanya ditampilkan saat diterbitkan.
        return ApiResponse::message(
            'Kode aktivasi diterbitkan. Simpan atau cetak slip sekarang; kode tidak dapat dilihat ulang.',
            $result
        )
            ->header(
                'Cache-Control',
                'private, no-store, max-age=0'
            )
            ->header(
                'Pragma',
                'no-cache'
            );
    }
}
