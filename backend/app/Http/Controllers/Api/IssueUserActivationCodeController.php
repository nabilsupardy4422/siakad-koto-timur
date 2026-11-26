<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserActivationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class IssueUserActivationCodeController extends Controller
{
    public function __invoke(
        Request $request,
        User $user,
        UserActivationService $activationService
    ): JsonResponse {
        $actor = $request->user();

        $result = app(BulkAccountController::class)->confirmSingle($request, $user, $activationService);

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
