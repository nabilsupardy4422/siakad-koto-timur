<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\GoogleIdentityVerifier;
use App\Support\ApiResponse;
use App\Support\Permissions;
use App\Support\UserAssignments;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class GoogleLoginController extends Controller
{
    public function __invoke(
        Request $request,
        GoogleIdentityVerifier $verifier
    ): JsonResponse {
        // =====================================
        // 1. VALIDASI GOOGLE CREDENTIAL
        // =====================================

        $validated = $request->validate([
            'credential' => [
                'required',
                'string',
                'max:8192',
            ],
        ]);

        // =====================================
        // 2. VERIFIKASI TOKEN GOOGLE
        // =====================================

        // Token diverifikasi oleh backend.
        // Google Subject (sub) menjadi identitas
        // utama, bukan alamat email Google.

        $googleSubject = $verifier->subject(
            $validated['credential']
        );

        // =====================================
        // 3. CARI AKUN SIAKAD TERTAUT
        // =====================================

        $user = User::query()
            ->with([
                'role',
                'guru',
            ])
            ->where(
                'google_id',
                $googleSubject
            )
            ->first();

        // Tidak membuat akun baru otomatis.
        if (! $user) {
            return ApiResponse::message(
                'Akun Google ini belum terhubung dengan SIAKAD. Login menggunakan username dan password terlebih dahulu, lalu hubungkan Google melalui Profil.',
                null,
                401
            );
        }

        // Recheck the verified Google link and account under the same lock
        // used by credential changes and administrative deactivation.
        return DB::transaction(function () use ($user, $googleSubject): JsonResponse {
            $locked = User::query()->with(['role', 'guru'])->lockForUpdate()->find($user->id);
            if (! $locked || ! is_string($locked->google_id) || ! hash_equals($googleSubject, $locked->google_id)
                || ! hash_equals($user->password, $locked->password) || (int) $locked->role_id !== (int) $user->role_id) {
                return ApiResponse::message('Akun berubah. Silakan login kembali.', null, 401);
            }
            $user = $locked;

            // =====================================
            // 4. PERIKSA STATUS AKUN
            // =====================================

            if ($user->account_status !== 'active') {
                return ApiResponse::message(
                    'Akun SIAKAD tidak aktif.',
                    null,
                    403
                );
            }

            // =====================================
            // 5. PERIKSA AKTIVASI
            // =====================================

            if ($user->requires_activation) {
                return ApiResponse::message(
                    'Akun belum diaktivasi. Gunakan kode aktivasi dari Tata Usaha.',
                    null,
                    403
                );
            }

            // =====================================
            // 6. PERIKSA ROLE
            // =====================================

            $roleCode = $user->role?->code;

            if (
                ! in_array(
                    $roleCode,
                    [
                        'TU',
                        'KEPALA_SEKOLAH',
                        'GURU',
                        'SISWA',
                    ],
                    true
                )
            ) {
                return ApiResponse::message(
                    'Role akun tidak diizinkan mengakses SIAKAD.',
                    null,
                    403
                );
            }

            // =====================================
            // 7. LOGIN BERHASIL
            // =====================================

            $user->forceFill([
                'last_login_at' => now(),
            ])->save();

            // Gunakan Sanctum seperti login biasa.
            $token = $user
                ->createToken('siakad-api')
                ->plainTextToken;

            // =====================================
            // 8. KIRIM RESPON LOGIN
            // =====================================

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
                    'guru' => $user->role?->code === 'GURU' && $user->guru ? [
                        'id' => $user->guru->id,
                        'nip' => $user->guru->nip,
                        'nama_lengkap' => $user->guru->nama_lengkap,
                    ] : null,
                ],

                'permissions' => Permissions::forRole(
                    $roleCode
                ),

                'assignments' => UserAssignments::forUser(
                    $user
                ),
            ]);
        }, 3);
    }
}
