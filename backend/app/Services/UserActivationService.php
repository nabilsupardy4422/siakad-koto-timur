<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserActivationService
{
    /**
     * Panggil di dalam transaksi database
     * setelah mengunci baris users.
     *
     * Kode asli tidak disimpan di database.
     *
     * @return array{
     *   activation_code: string,
     *   expires_at: string
     * }
     */
    public function issue(
        User $user,
        int $issuerId
    ): array {
        // 16 byte acak = 128 bit.
        $plainCode = strtoupper(
            bin2hex(random_bytes(16))
        );

        // Format lebih mudah dibaca pada slip.
        $displayCode = implode(
            '-',
            str_split($plainCode, 4)
        );

        $now = now();
        $expiresAt = $now->copy()->addDays(7);

        // Penerbitan ulang mengganti kode lama.
        DB::table('user_activation_tokens')
            ->updateOrInsert(
                ['user_id' => $user->id],
                [
                    'token_hash' => hash(
                        'sha256',
                        $plainCode
                    ),
                    'expires_at' => $expiresAt,
                    'used_at' => null,
                    'failed_attempts' => 0,
                    'issued_by' => $issuerId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

        return [
            'activation_code' => $displayCode,
            'expires_at' =>
                $expiresAt->toIso8601String(),
        ];
    }
}
