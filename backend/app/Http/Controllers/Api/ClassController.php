<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClassRequest;
use App\Http\Requests\UpdateClassRequest;
use App\Http\Resources\ClassResource;
use App\Models\Kelas;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClassController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Kelas::query()
            ->latest('id');

        $academicYearId = $request->filled('academic_year_id')
            ? $request->integer('academic_year_id')
            : null;

        $this->applyViewScope($query, $user, $academicYearId);

        if ($academicYearId !== null) {
            $query->where('tahun_akademik_id', $academicYearId);
        }

        if ($request->filled('grade_level')) {
            $query->where(
                'tingkat',
                $request->integer('grade_level')
            );
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();

            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('nama', 'like', "%{$search}%");
            });
        }

        $classes = $query
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return ApiResponse::collection(
            ClassResource::collection($classes),
            [
                'current_page' => $classes->currentPage(),
                'per_page' => $classes->perPage(),
                'total' => $classes->total(),
                'last_page' => $classes->lastPage(),
            ]
        );
    }

    public function store(StoreClassRequest $request): JsonResponse
    {
        $class = Kelas::create($request->validated());

        return ApiResponse::message(
            'Data kelas berhasil ditambahkan.',
            new ClassResource($class),
            201
        );
    }

    public function show(
        Request $request,
        Kelas $class
    ): JsonResponse {
        $academicYearId = $request->filled('academic_year_id')
            ? $request->integer('academic_year_id')
            : null;

        if (! $this->canViewClass(
            $class,
            $request->user(),
            $academicYearId
        )) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses ke resource ini.',
            ], 403);
        }

        return ApiResponse::success(
            new ClassResource($class)
        );
    }

    public function update(
        UpdateClassRequest $request,
        Kelas $class
    ): JsonResponse {
        $class->update($request->validated());

        return ApiResponse::message(
            'Data kelas berhasil diperbarui.',
            new ClassResource($class->refresh())
        );
    }

    public function destroy(Kelas $class): JsonResponse
    {
        $class->delete();

        return ApiResponse::message(
            'Data kelas berhasil dihapus.'
        );
    }

    private function applyViewScope(
        Builder $query,
        $user,
        ?int $academicYearId
    ): void {
        $role = $user->role->code;

        if (in_array($role, ['TU', 'KEPALA_SEKOLAH'], true)) {
            return;
        }

        if ($role === 'SISWA') {
            $query->whereHas(
                'anggotaKelas',
                function (Builder $builder) use ($user, $academicYearId): void {
                    $builder->whereHas(
                        'siswa',
                        function (Builder $studentQuery) use ($user): void {
                            $studentQuery->where('user_id', $user->id);
                        }
                    );
                }
            );

            return;
        }

        if ($role === 'GURU') {
            $guruId = $user->guru?->id;

            if (! $guruId) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->where(function (Builder $builder) use (
                $guruId,
                $academicYearId
            ): void {
                $builder->whereHas(
                    'jadwalPelajaran',
                    function (Builder $scheduleQuery) use (
                        $guruId,
                        $academicYearId
                    ): void {
                        $scheduleQuery->where('guru_id', $guruId);

                        if ($academicYearId !== null) {
                            $scheduleQuery->where(
                                'tahun_akademik_id',
                                $academicYearId
                            );
                        }
                    }
                );

                $builder->orWhereHas(
                    'waliKelas',
                    function (Builder $waliQuery) use (
                        $guruId,
                        $academicYearId
                    ): void {
                        $waliQuery->where('guru_id', $guruId);

                        if ($academicYearId !== null) {
                            $waliQuery->where(
                                'tahun_akademik_id',
                                $academicYearId
                            );
                        }
                    }
                );
            });

            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function canViewClass(
        Kelas $class,
        $user,
        ?int $academicYearId
    ): bool {
        if ($academicYearId !== null &&
            $class->tahun_akademik_id !== $academicYearId) {
            return false;
        }

        $role = $user->role->code;

        if (in_array($role, ['TU', 'KEPALA_SEKOLAH'], true)) {
            return true;
        }

        if ($role === 'SISWA') {
            return $class->anggotaKelas()
                ->whereHas(
                    'siswa',
                    fn (Builder $query) => $query->where(
                        'user_id',
                        $user->id
                    )
                )
                ->exists();
        }

        if ($role === 'GURU') {
            $guruId = $user->guru?->id;

            if (! $guruId) {
                return false;
            }

            $teachingClass = $class->jadwalPelajaran()
                ->where('guru_id', $guruId)
                ->when(
                    $academicYearId !== null,
                    fn (Builder $query) => $query->where(
                        'tahun_akademik_id',
                        $academicYearId
                    )
                )
                ->exists();

            if ($teachingClass) {
                return true;
            }

            return $class->waliKelas()
                ->where('guru_id', $guruId)
                ->when(
                    $academicYearId !== null,
                    fn (Builder $query) => $query->where(
                        'tahun_akademik_id',
                        $academicYearId
                    )
                )
                ->exists();
        }

        return false;
    }
}