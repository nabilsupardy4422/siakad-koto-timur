<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWaliKelasRequest;
use App\Http\Requests\UpdateWaliKelasRequest;
use App\Http\Resources\WaliKelasResource;
use App\Models\User;
use App\Models\WaliKelas;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class WaliKelasController extends Controller
{
    /**
     * Menampilkan daftar assignment Wali Kelas.
     *
     * TU dan Kepala Sekolah dapat melihat seluruh assignment.
     * Guru hanya dapat melihat assignment miliknya.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->loadMissing([
            'role',
            'guru',
        ]);

        $query = WaliKelas::query()
            ->with([
                'guru',
                'kelas',
                'tahunAkademik',
            ])
            ->latest('id');

        $this->applyViewScope(
            $query,
            $user
        );

        if ($request->filled('academic_year_id')) {
            $query->where(
                'tahun_akademik_id',
                $request->integer('academic_year_id')
            );
        }

        if ($request->filled('class_id')) {
            $query->where(
                'kelas_id',
                $request->integer('class_id')
            );
        }

        if ($request->filled('teacher_id')) {
            $query->where(
                'guru_id',
                $request->integer('teacher_id')
            );
        }

        $assignments = $query
            ->paginate(
                $request->integer('per_page', 20)
            )
            ->withQueryString();

        return ApiResponse::collection(
            WaliKelasResource::collection(
                $assignments
            ),
            [
                'current_page' => $assignments->currentPage(),
                'per_page' => $assignments->perPage(),
                'total' => $assignments->total(),
                'last_page' => $assignments->lastPage(),
            ]
        );
    }

    /**
     * Membuat assignment Wali Kelas.
     *
     * Route dibatasi untuk TU.
     * Validasi dilakukan oleh StoreWaliKelasRequest.
     */
    public function store(
        StoreWaliKelasRequest $request
    ): JsonResponse {
        $validated = $request->validated();

        $assignment = DB::transaction(
            function () use ($validated): WaliKelas {
                return WaliKelas::query()->create(
                    $validated
                );
            }
        );

        $assignment->load([
            'guru',
            'kelas',
            'tahunAkademik',
        ]);

        return ApiResponse::message(
            'Data Wali Kelas berhasil ditambahkan.',
            new WaliKelasResource(
                $assignment
            ),
            201
        );
    }

    /**
     * Menampilkan detail assignment Wali Kelas.
     *
     * Guru hanya dapat melihat assignment miliknya.
     */
    public function show(
        Request $request,
        WaliKelas $assignment
    ): JsonResponse {
        $user = $request->user();

        $user->loadMissing([
            'role',
            'guru',
        ]);

        if (! $this->canViewAssignment(
            $assignment,
            $user
        )) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses ke resource ini.',
            ], 403);
        }

        $assignment->load([
            'guru',
            'kelas',
            'tahunAkademik',
        ]);

        return ApiResponse::success(
            new WaliKelasResource(
                $assignment
            )
        );
    }

    /**
     * Memperbarui assignment Wali Kelas.
     *
     * Route dibatasi untuk TU.
     * Validasi dilakukan oleh UpdateWaliKelasRequest.
     */
    public function update(
        UpdateWaliKelasRequest $request,
        WaliKelas $assignment
    ): JsonResponse {
        $validated = $request->validated();

        DB::transaction(
            function () use (
                $assignment,
                $validated
            ): void {
                $assignment->update(
                    $validated
                );
            }
        );

        $assignment->refresh()->load([
            'guru',
            'kelas',
            'tahunAkademik',
        ]);

        return ApiResponse::message(
            'Data Wali Kelas berhasil diperbarui.',
            new WaliKelasResource(
                $assignment
            )
        );
    }

    /**
     * Mengakhiri assignment Wali Kelas.
     *
     * Route dibatasi untuk TU.
     * Assignment tidak dihapus agar riwayat tetap tersedia.
     */
    public function destroy(
        WaliKelas $assignment
    ): JsonResponse {
        if ($assignment->tanggal_selesai !== null) {
            return ApiResponse::message(
                'Assignment Wali Kelas sudah berakhir.'
            );
        }

        $today = Carbon::today();

        if (
            $assignment->tanggal_mulai !== null &&
            $assignment->tanggal_mulai->greaterThan($today)
        ) {
            return response()->json([
                'message' => 'Assignment belum dimulai sehingga tidak dapat diakhiri.',
            ], 422);
        }

        DB::transaction(
            function () use (
                $assignment,
                $today
            ): void {
                $assignment->update([
                    'tanggal_selesai' => $today,
                ]);
            }
        );

        return ApiResponse::message(
            'Assignment Wali Kelas berhasil diakhiri.'
        );
    }

    /**
     * Membatasi daftar assignment berdasarkan role.
     */
    private function applyViewScope(
        Builder $query,
        User $user
    ): void {
        $role = $user->role?->code;

        if (in_array(
            $role,
            [
                'TU',
                'KEPALA_SEKOLAH',
            ],
            true
        )) {
            return;
        }

        if ($role === 'GURU') {
            $guruId = $user->guru?->id;

            if ($guruId === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->where(
                'guru_id',
                $guruId
            );

            return;
        }

        $query->whereRaw('1 = 0');
    }

    /**
     * Memeriksa akses detail assignment.
     */
    private function canViewAssignment(
        WaliKelas $assignment,
        User $user
    ): bool {
        $role = $user->role?->code;

        if (in_array(
            $role,
            [
                'TU',
                'KEPALA_SEKOLAH',
            ],
            true
        )) {
            return true;
        }

        if ($role === 'GURU') {
            $guruId = $user->guru?->id;

            return $guruId !== null
                && (int) $assignment->guru_id === (int) $guruId;
        }

        return false;
    }
}
