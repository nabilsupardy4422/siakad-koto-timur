<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsUsable
{
    /**
     * Pastikan pengguna yang mengakses API:
     * 1. Sudah terautentikasi.
     * 2. Memiliki akun aktif.
     * 3. Tidak sedang memerlukan aktivasi akun.
     */
    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $user = $request->user();

        // Request tanpa autentikasi.
        if (! $user) {
            return ApiResponse::message(
                'Silakan login terlebih dahulu.',
                null,
                401
            );
        }

        // Akun yang dinonaktifkan TU
        // tidak boleh mengakses endpoint privat.
        if ($user->account_status !== 'active') {
            return ApiResponse::message(
                'Akun tidak aktif. Silakan hubungi TU.',
                null,
                403
            );
        }

        // Akun hasil import harus diaktivasi
        // sebelum menggunakan layanan SIAKAD.
        if ($user->requires_activation) {
            return ApiResponse::message(
                'Akun belum diaktivasi. Silakan lakukan aktivasi menggunakan kode dari TU.',
                null,
                403
            );
        }

        return $next($request);
    }
}
