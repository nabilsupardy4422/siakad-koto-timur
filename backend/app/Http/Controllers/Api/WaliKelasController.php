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

class WaliKelasController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->loadMissing(['role', 'guru']);

        $query = WaliKelas::query()
            ->with([
                'guru',
                'kelas',
                'tahunAkademik',
            ])
            ->latest('id');

        $this->applyViewScope($query, $user);

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
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return ApiResponse::collection(
            WaliKelasResource::collection($assignments),
            [
                'current_page' => $assignments->currentPage(),
                'per_page' => $assignments->perPage(),
                'total' => $assignments->total(),
                'last_page' => $assignments->lastPage(),
            ]
        );
    }

    public function store(
        StoreWaliKelasRequest $request
    ): JsonResponse {
        $assignment = WaliKelas::create(
            $request->validated()
        );

        return ApiResponse::message(
            'Data Wali Kelas berhasil ditambahkan.',
            new WaliKelasResource(
                $assignment->load([
                    'guru',
                    'kelas',
                    'tahunAkademik',
                ])
            ),
            201
        );
    }

    public function show(
        Request $request,
        WaliKelas $assignment
    ): JsonResponse {
        $user = $request->user();

        $user->loadMissing(['role', 'guru']);

        if (! $this->canViewAssignment(
            $assignment,
            $user
        )) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses ke resource ini.',
            ], 403);
        }

        return ApiResponse::success(
            new WaliKelasResource(
                $assignment->load([
                    'guru',
                    'kelas',
                    'tahunAkademik',
                ])
            )
        );
    }

    public function update(
        UpdateWaliKelasRequest $request,
        WaliKelas $assignment
    ): JsonResponse {
        $assignment->update(
            $request->validated()
        );

        return ApiResponse::message(
            'Data Wali Kelas berhasil diperbarui.',
            new WaliKelasResource(
                $assignment->refresh()->load([
                    'guru',
                    'kelas',
                    'tahunAkademik',
                ])
            )
        );
    }

    public function destroy(
        WaliKelas $assignment
    ): JsonResponse {
        if ($assignment->tanggal_selesai === null) {
            $assignment->update([
                'tanggal_selesai' => now()->toDateString(),
            ]);
        }

        return ApiResponse::message(
            'Assignment Wali Kelas berhasil diakhiri.'
        );
    }

    private function applyViewScope(
        Builder $query,
        User $user
    ): void {
        $role = $user->role?->code;

        if (in_array(
            $role,
            ['TU', 'KEPALA_SEKOLAH'],
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

            $query->where('guru_id', $guruId);

            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function canViewAssignment(
        WaliKelas $assignment,
        User $user
    ): bool {
        $role = $user->role?->code;

        if (in_array(
            $role,
            ['TU', 'KEPALA_SEKOLAH'],
            true
        )) {
            return true;
        }

        if ($role === 'GURU') {
            $guruId = $user->guru?->id;

            return $guruId !== null
                && $assignment->guru_id === $guruId;
        }

        return false;
    }
}