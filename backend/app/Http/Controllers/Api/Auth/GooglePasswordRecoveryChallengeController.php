<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Services\GooglePasswordRecoveryChallenge;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class GooglePasswordRecoveryChallengeController extends Controller
{
    // ==========================================
    // TERBITKAN CHALLENGE PEMULIHAN PASSWORD
    // ==========================================

    public function __invoke(
        GooglePasswordRecoveryChallenge $challenge
    ): JsonResponse {

        // ======================================
        // 1. BUAT CHALLENGE BARU
        // ======================================

        // Service menghasilkan:
        //
        // - challenge_id
        // - nonce
        // - expires_in
        //
        // Challenge disimpan sementara di cache.
        // Password dan Google ID token
        // tidak terlibat pada tahap ini.

        $data = $challenge->issue();

        // ======================================
        // 2. KIRIM RESPONSE
        // ======================================

        // Nonce akan dipakai frontend ketika
        // memulai Google Identity Services.
        //
        // Jangan simpan response di cache
        // browser atau proxy.

        return ApiResponse::success([
            'challenge_id' =>
                $data['challenge_id'],

            'nonce' =>
                $data['nonce'],

            'expires_in' =>
                $data['expires_in'],
        ])
            ->header(
                'Cache-Control',
                'no-store, private'
            )
            ->header(
                'Pragma',
                'no-cache'
            );
    }
}
