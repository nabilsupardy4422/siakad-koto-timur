<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\HomeroomReadAccess;
use App\Http\Requests\StoreGradeComponentRequest;
use App\Http\Requests\UpdateGradeComponentRequest;
use App\Http\Resources\GradeComponentResource;
use App\Models\JadwalPelajaran;
use App\Models\KomponenNilai;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\GradeTeachingAccess;
use App\Support\TeachingAssignmentScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GradeComponentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->loadMissing([
            'role',
            'guru',
            'siswa',
        ]);

        $query = KomponenNilai::query()
            ->with([
                'jadwalPelajaran.tahunAkademik',
                'jadwalPelajaran.kelas',
                'jadwalPelajaran.mapel',
                'jadwalPelajaran.guru',
            ])
            ->latest('id');

        $academicYearId = $request->filled('academic_year_id')
            ? $request->integer('academic_year_id')
            : null;

        $this->applyViewScope(
            $query,
            $user,
            $academicYearId, GradeTeachingAccess::readScope($request)
        );

        if ($request->filled('schedule_id')) {
            $query->where(
                'jadwal_pelajaran_id',
                $request->integer('schedule_id')
            );
        }

        if ($academicYearId !== null) {
            $query->whereHas(
                'jadwalPelajaran',
                fn (Builder $builder) => $builder->where(
                    'tahun_akademik_id',
                    $academicYearId
                )
            );
        }

        if ($request->filled('semester')) {
            $semester = $request
                ->string('semester')
                ->toString();

            $query->whereHas(
                'jadwalPelajaran.tahunAkademik',
                fn (Builder $builder) => $builder->where(
                    'semester',
                    $semester
                )
            );
        }

        $components = $query
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return ApiResponse::collection(
            GradeComponentResource::collection(
                $components->items()
            ),
            [
                'current_page' => $components->currentPage(),
                'last_page' => $components->lastPage(),
                'per_page' => $components->perPage(),
                'total' => $components->total(),
            ]
        );
    }

    public function store(
        StoreGradeComponentRequest $request
    ): JsonResponse {
        $schedule = JadwalPelajaran::query()
            ->with([
                'tahunAkademik',
                'kelas',
                'mapel',
                'guru',
            ])
            ->findOrFail(
                $request->integer('jadwal_pelajaran_id')
            );

        if (
            ! $this->canManageComponent(
                $request->user(),
                $schedule
            )
        ) {
            return response()->json([
                'message' =>
                    'Anda tidak memiliki akses untuk mengelola komponen nilai pada jadwal ini.',
            ], 403);
        }

        $component = KomponenNilai::create(
            $request->validated()
        );

        return ApiResponse::message(
            'Komponen nilai berhasil ditambahkan.',
            new GradeComponentResource(
                $component->load([
                    'jadwalPelajaran.tahunAkademik',
                    'jadwalPelajaran.kelas',
                    'jadwalPelajaran.mapel',
                    'jadwalPelajaran.guru',
                ])
            ),
            201
        );
    }

    public function show(
        Request $request,
        KomponenNilai $component
    ): JsonResponse {
        $user = $request->user();

        $user->loadMissing([
            'role',
            'guru',
            'siswa',
        ]);

        $academicYearId = $request->filled('academic_year_id')
            ? $request->integer('academic_year_id')
            : null;

        if (
            ! $this->canViewComponent(
                $component,
                $user,
                $academicYearId, GradeTeachingAccess::readScope($request)
            )
        ) {
            return response()->json([
                'message' =>
                    'Anda tidak memiliki akses ke resource ini.',
            ], 403);
        }

        return ApiResponse::success(
            new GradeComponentResource(
                $component->load([
                    'jadwalPelajaran.tahunAkademik',
                    'jadwalPelajaran.kelas',
                    'jadwalPelajaran.mapel',
                    'jadwalPelajaran.guru',
                ])
            )
        );
    }

    public function update(
        UpdateGradeComponentRequest $request,
        KomponenNilai $component
    ): JsonResponse {
        return DB::transaction(function () use ($request, $component): JsonResponse {
            $component = KomponenNilai::query()->lockForUpdate()->findOrFail($component->id);
            $component->load('jadwalPelajaran');

            $currentSchedule = $component->jadwalPelajaran;

            if (
                ! $this->canManageComponent(
                    $request->user(),
                    $currentSchedule
                )
            ) {
                return response()->json([
                    'message' =>
                        'Anda tidak memiliki akses untuk mengubah komponen nilai ini.',
                ], 403);
            }

            $targetSchedule = $currentSchedule;

            if ($request->filled('jadwal_pelajaran_id')) {
                $targetSchedule = JadwalPelajaran::query()
                    ->with([
                        'tahunAkademik',
                        'kelas',
                        'mapel',
                        'guru',
                    ])
                    ->findOrFail(
                        $request->integer('jadwal_pelajaran_id')
                    );

                if (
                    ! $this->canManageComponent(
                        $request->user(),
                        $targetSchedule
                    )
                ) {
                    return response()->json([
                        'message' =>
                            'Anda tidak memiliki akses untuk memindahkan komponen nilai ke jadwal tersebut.',
                    ], 403);
                }
            }

            if ((int) $targetSchedule->id !== (int) $currentSchedule->id) {
                if ($component->nilai()->exists() || $component->assignments()->exists()) {
                    throw ValidationException::withMessages(['jadwal_pelajaran_id' => 'Komponen yang sudah memiliki nilai atau tugas tidak dapat dipindahkan.']);
                }
                $name = $request->input('nama', $component->nama);
                if (KomponenNilai::query()->where('jadwal_pelajaran_id', $targetSchedule->id)
                    ->where('nama', $name)->where('id', '!=', $component->id)->exists()) {
                    throw ValidationException::withMessages(['nama' => 'Nama komponen sudah digunakan pada jadwal tujuan.']);
                }
            }
            $component->update($request->validated());

            return ApiResponse::message(
                'Komponen nilai berhasil diperbarui.',
                new GradeComponentResource(
                    $component->refresh()->load([
                        'jadwalPelajaran.tahunAkademik',
                        'jadwalPelajaran.kelas',
                        'jadwalPelajaran.mapel',
                        'jadwalPelajaran.guru',
                    ])
                )
            );
        });
    }

    public function destroy(
        Request $request,
        KomponenNilai $component
    ): JsonResponse {
        return DB::transaction(function () use ($request, $component): JsonResponse {
            $component = KomponenNilai::query()->lockForUpdate()->findOrFail($component->id);
            $component->load('jadwalPelajaran');

            if (
                ! $this->canManageComponent(
                    $request->user(),
                    $component->jadwalPelajaran
                )
            ) {
                return response()->json([
                    'message' =>
                        'Anda tidak memiliki akses untuk menghapus komponen nilai ini.',
                ], 403);
            }

            if ($component->nilai()->exists() || $component->assignments()->exists()) {
                return response()->json(['message' => 'Komponen tidak dapat dihapus karena masih terkait nilai atau tugas.'], 409);
            }
            $component->delete();

            return ApiResponse::message(
                'Komponen nilai berhasil dihapus.'
            );
        });
    }

    private function applyViewScope(
        Builder $query,
        User $user,
        ?int $academicYearId,
        string $scope = TeachingAssignmentScope::LEGACY
    ): void {
        if ($scope !== TeachingAssignmentScope::LEGACY) {
            $query->whereHas('jadwalPelajaran', fn (Builder $schedule) => GradeTeachingAccess::apply($schedule, $user, $scope));
            return;
        }
        $role = $user->role?->code;

        if (
            in_array(
                $role,
                ['TU', 'KEPALA_SEKOLAH'],
                true
            )
        ) {
            return;
        }

        if ($role === 'GURU') {
            $guruId = $user->guru?->id;

            if ($guruId === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->where(function (
                Builder $builder
            ) use (
                $guruId,
                $academicYearId
            ): void {
                $builder->whereHas(
                    'jadwalPelajaran',
                    function (
                        Builder $scheduleQuery
                    ) use (
                        $guruId,
                        $academicYearId
                    ): void {
                        $scheduleQuery->where(
                            'guru_id',
                            $guruId
                        );

                        if ($academicYearId !== null) {
                            $scheduleQuery->where(
                                'tahun_akademik_id',
                                $academicYearId
                            );
                        }
                    }
                );

                $builder->orWhereHas(
                    'jadwalPelajaran.kelas',
                    function (
                        Builder $classQuery
                    ) use (
                        $guruId,
                        $academicYearId
                    ): void {
                        $classQuery->whereHas(
                            'waliKelas',
                            function (
                                Builder $waliQuery
                            ) use (
                                $guruId,
                                $academicYearId
                            ): void {
                                HomeroomReadAccess::restrictCurrent($waliQuery, (int) $guruId, $academicYearId);
                                $waliQuery->whereColumn('wali_kelas.tahun_akademik_id', 'kelas.tahun_akademik_id');
                            }
                        );

                        if ($academicYearId !== null) {
                            $classQuery->where(
                                'tahun_akademik_id',
                                $academicYearId
                            );
                        }
                    }
                );
            });

            return;
        }

        if ($role === 'SISWA') {
            $studentId = $user->siswa?->id;

            if ($studentId === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->whereHas(
                'jadwalPelajaran.kelas.anggotaKelas',
                function (
                    Builder $memberQuery
                ) use (
                    $studentId,
                    $academicYearId
                ): void {
                    $memberQuery->where(
                        'siswa_id',
                        $studentId
                    );

                    $memberQuery->whereNull(
                        'tanggal_selesai'
                    );

                    if ($academicYearId !== null) {
                        $memberQuery->whereHas(
                            'kelas',
                            fn (
                                Builder $classQuery
                            ) => $classQuery->where(
                                'tahun_akademik_id',
                                $academicYearId
                            )
                        );
                    }
                }
            );

            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function canViewComponent(
        KomponenNilai $component,
        User $user,
        ?int $academicYearId,
        string $scope
    ): bool {
        $query = KomponenNilai::query()->whereKey($component->id);
        $this->applyViewScope($query, $user, $academicYearId, $scope);
        if ($academicYearId !== null) {
            $query->whereHas('jadwalPelajaran', fn (Builder $q) => $q->where('tahun_akademik_id', $academicYearId));
        }
        return $query->exists();
    }

    private function canManageComponent(?User $user, ?JadwalPelajaran $schedule): bool
    {
        return GradeTeachingAccess::canManage($user, $schedule);
    }
}
