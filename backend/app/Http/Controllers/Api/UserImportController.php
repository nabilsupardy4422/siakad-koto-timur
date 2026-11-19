<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class UserImportController extends Controller
{
    /**
     * Import massal hanya untuk GURU dan SISWA.
     * Semua baris divalidasi ulang sebelum disimpan.
     */
    public function __invoke(Request $request): JsonResponse
    {
        // ========================================
        // VALIDASI REQUEST
        // ========================================

        $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:xlsx',
                'max:2048',
            ],

            'confirm_import' => [
                'required',
                'accepted',
            ],
        ]);

        // ========================================
        // VALIDASI ULANG FILE EXCEL
        // ========================================

        // Menggunakan controller preview yang
        // sudah kita uji agar hasil validasinya
        // tetap konsisten.
        //
        // Backend tidak menerima daftar baris
        // valid yang diklaim oleh browser React.

        $previewResponse = app(
            UserImportPreviewController::class
        )($request);

        if (
            $previewResponse->getStatusCode() !== 200
        ) {
            return $previewResponse;
        }

        $preview = $previewResponse->getData(true);

        $data = $preview['data'] ?? null;
        $summary = $data['summary'] ?? null;
        $rows = $data['rows'] ?? null;

        // ========================================
        // SEMUA BARIS WAJIB VALID
        // ========================================

        if (
            ! is_array($summary) ||
            ! is_array($rows) ||
            count($rows) === 0 ||
            ($summary['invalid_rows'] ?? 1) !== 0 ||
            ($summary['total_rows'] ?? 0) !== count($rows) ||
            ($summary['valid_rows'] ?? 0) !== count($rows)
        ) {
            return ApiResponse::message(
                'Import dibatalkan. Perbaiki semua baris bermasalah, lalu periksa ulang file Excel.',
                $data,
                422
            );
        }

        // ========================================
        // BATASI ROLE IMPORT
        // ========================================

        foreach ($rows as $row) {
            if (
                empty($row['valid']) ||
                ! in_array(
                    $row['role_code'] ?? null,
                    ['GURU', 'SISWA'],
                    true
                )
            ) {
                return ApiResponse::message(
                    'Import massal hanya menerima role GURU dan SISWA. Hapus role TU atau KEPALA_SEKOLAH dari file.',
                    null,
                    422
                );
            }
        }

        // ========================================
        // AMBIL ROLE DARI DATABASE
        // ========================================

        $roleIds = Role::query()
            ->whereIn(
                'code',
                ['GURU', 'SISWA']
            )
            ->pluck('id', 'code')
            ->all();

        if (
            ! isset(
                $roleIds['GURU'],
                $roleIds['SISWA']
            )
        ) {
            return ApiResponse::message(
                'Data role GURU/SISWA belum lengkap. Hubungi administrator sistem.',
                null,
                422
            );
        }

        // ========================================
        // SIMPAN DALAM TRANSAKSI
        // ========================================

        try {
            $createdUsers = DB::transaction(
                function () use (
                    $rows,
                    $roleIds
                ): array {
                    $created = [];

                    foreach ($rows as $row) {
                        $user = new User();

                        $user->fill([
                            'username' =>
                                $row['username'],

                            'name' =>
                                $row['name'],

                            'email' =>
                                $row['email'],

                            'role_id' =>
                                $roleIds[
                                    $row['role_code']
                                ],

                            'account_status' =>
                                'active',

                            // Password acak yang tidak
                            // diberikan kepada pengguna.
                            //
                            // Cast hashed pada User
                            // akan meng-hash nilainya.
                            'password' =>
                                Str::random(64),
                        ]);

                        // Kolom keamanan sengaja tidak
                        // masuk ke $fillable.
                        //
                        // Set sebelum INSERT supaya akun
                        // sejak awal wajib aktivasi.

                        $user->forceFill([
                            'requires_activation' =>
                                true,

                            'activated_at' =>
                                null,
                        ]);

                        $user->save();

                        // Data aman untuk respons API.
                        // Tidak menyertakan password.

                        $created[] = [
                            'id' =>
                                $user->id,

                            'username' =>
                                $user->username,

                            'name' =>
                                $user->name,

                            'role_code' =>
                                $row['role_code'],

                            'requires_activation' =>
                                true,
                        ];
                    }

                    return $created;
                },
                3
            );
        } catch (QueryException $exception) {
            // Jika pengguna lain memasukkan
            // username/email yang sama tepat
            // sebelum proses INSERT selesai,
            // unique constraint akan menolak.
            //
            // Seluruh transaksi dibatalkan.

            if (
                (int) (
                    $exception->errorInfo[1] ?? 0
                ) === 1062
            ) {
                return ApiResponse::message(
                    'Import dibatalkan: ada username atau email yang baru saja terdaftar. Upload dan periksa ulang Excel.',
                    null,
                    409
                );
            }

            throw $exception;
        }

        // ========================================
        // AUDIT LOG
        // ========================================

        Log::info(
            'Users imported from Excel',
            [
                'actor_user_id' =>
                    $request->user()->id,

                'created_count' =>
                    count($createdUsers),
            ]
        );

        // ========================================
        // RESPONSE BERHASIL
        // ========================================

        // Password serta kode aktivasi tidak
        // dikembalikan pada respons ini.
        //
        // TU menerbitkan slip individual melalui
        // endpoint activation-code.

        return ApiResponse::message(
            'Import berhasil. Semua akun tersimpan dan wajib melakukan aktivasi sebelum login.',
            [
                'created_count' =>
                    count($createdUsers),

                'users' =>
                    $createdUsers,

                'next_step' =>
                    'Terbitkan kode aktivasi masing-masing akun melalui TU.',
            ],
            201
        )->header(
            'Cache-Control',
            'private, no-store, max-age=0'
        );
    }
}
