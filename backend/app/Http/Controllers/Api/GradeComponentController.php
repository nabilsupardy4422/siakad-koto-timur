<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGradeComponentRequest;
use App\Http\Requests\UpdateGradeComponentRequest;
use App\Http\Resources\GradeComponentResource;
use App\Models\JadwalPelajaran;
use App\Models\KomponenNilai;
use App\Models\User;
use App\Support\ApiResponse;
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
            $academicYearId
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
                $academicYearId
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
        $component->load([
            'jadwalPelajaran',
        ]);

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

        $component->update(
            $request->validated()
        );

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
    }

    public function destroy(
        Request $request,
        KomponenNilai $component
    ): JsonResponse {
        $component->load([
            'jadwalPelajaran',
        ]);

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

        $component->delete();

        return ApiResponse::message(
            'Komponen nilai berhasil dihapus.'
        );
    }

    private function applyViewScope(
        Builder $query,
        User $user,
        ?int $academicYearId
    ): void {
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
                                $waliQuery
                                    ->where(
                                        'guru_id',
                                        $guruId
                                    )
                                    ->whereNull(
                                        'tanggal_selesai'
                                    );

                                if (
                                    $academicYearId !== null
                                ) {
                                    $waliQuery->where(
                                        'tahun_akademik_id',
                                        $academicYearId
                                    );
                                }
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
        ?int $academicYearId
    ): bool {
        $schedule = $component->jadwalPelajaran;

        if ($schedule === null) {
            return false;
        }

        if (
            $academicYearId !== null
            && $schedule->tahun_akademik_id !== $academicYearId
        ) {
            return false;
        }

        $role = $user->role?->code;

        if (
            in_array(
                $role,
                ['TU', 'KEPALA_SEKOLAH'],
                true
            )
        ) {
            return true;
        }

        if ($role === 'GURU') {
            $guruId = $user->guru?->id;

            if ($guruId === null) {
                return false;
            }

            if ($schedule->guru_id === $guruId) {
                return true;
            }

            return $schedule->kelas()
                ->whereHas(
                    'waliKelas',
                    function (
                        Builder $builder
                    ) use ($guruId): void {
                        $builder
                            ->where(
                                'guru_id',
                                $guruId
                            )
                            ->whereNull(
                                'tanggal_selesai'
                            );
                    }
                )
                ->exists();
        }

        if ($role === 'SISWA') {
            $studentId = $user->siswa?->id;

            if ($studentId === null) {
                return false;
            }

            return $schedule->kelas()
                ->whereHas(
                    'anggotaKelas',
                    function (
                        Builder $builder
                    ) use ($studentId): void {
                        $builder
                            ->where(
                                'siswa_id',
                                $studentId
                            )
                            ->whereNull(
                                'tanggal_selesai'
                            );
                    }
                )
                ->exists();
        }

        return false;
    }

    private function canManageComponent(
        ?User $user,
        ?JadwalPelajaran $schedule
    ): bool {
        if ($user === null || $schedule === null) {
            return false;
        }

        if ($user->role?->code !== 'GURU') {
            return false;
        }

        $guruId = $user->guru?->id;

        if ($guruId === null) {
            return false;
        }

        return $schedule->guru_id === $guruId;
    }
}