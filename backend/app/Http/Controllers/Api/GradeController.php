<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGradeRequest;
use App\Http\Requests\UpdateGradeRequest;
use App\Http\Resources\GradeResource;
use App\Models\KomponenNilai;
use App\Models\Nilai;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\HomeroomReadAccess;
use App\Support\GradeTeachingAccess;
use App\Support\TeachingAssignmentScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GradeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->loadMissing([
            'role',
            'guru',
            'siswa',
        ]);

        $query = Nilai::query()
            ->with([
                'komponenNilai.jadwalPelajaran.tahunAkademik',
                'komponenNilai.jadwalPelajaran.kelas',
                'komponenNilai.jadwalPelajaran.mapel',
                'komponenNilai.jadwalPelajaran.guru',
                'siswa',
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
            $query->whereHas(
                'komponenNilai',
                fn (Builder $builder) => $builder->where(
                    'jadwal_pelajaran_id',
                    $request->integer('schedule_id')
                )
            );
        }

        if ($request->filled('class_id')) {
            $query->whereHas(
                'komponenNilai.jadwalPelajaran',
                fn (Builder $builder) => $builder->where(
                    'kelas_id',
                    $request->integer('class_id')
                )
            );
        }

        if ($request->filled('subject_id')) {
            $query->whereHas(
                'komponenNilai.jadwalPelajaran',
                fn (Builder $builder) => $builder->where(
                    'mapel_id',
                    $request->integer('subject_id')
                )
            );
        }

        if ($request->filled('teacher_id')) {
            $query->whereHas(
                'komponenNilai.jadwalPelajaran',
                fn (Builder $builder) => $builder->where(
                    'guru_id',
                    $request->integer('teacher_id')
                )
            );
        }

        if ($request->filled('student_id')) {
            $query->where(
                'siswa_id',
                $request->integer('student_id')
            );
        }

        if ($request->filled('component_id')) {
            $query->where(
                'komponen_nilai_id',
                $request->integer('component_id')
            );
        }

        if ($academicYearId !== null) {
            $query->whereHas(
                'komponenNilai.jadwalPelajaran',
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
                'komponenNilai.jadwalPelajaran.tahunAkademik',
                fn (Builder $builder) => $builder->where(
                    'semester',
                    $semester
                )
            );
        }

        $grades = $query
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return ApiResponse::collection(
            GradeResource::collection(
                $grades->items()
            ),
            [
                'current_page' => $grades->currentPage(),
                'last_page' => $grades->lastPage(),
                'per_page' => $grades->perPage(),
                'total' => $grades->total(),
            ]
        );
    }

    public function recap(Request $request): JsonResponse
{
    $user = $request->user();

    $user->loadMissing([
        'role',
        'guru',
        'siswa',
    ]);

    $academicYearId = $request->filled('academic_year_id')
        ? $request->integer('academic_year_id')
        : null;

    $query = Nilai::query();

    $this->applyViewScope(
        $query,
        $user,
        $academicYearId, GradeTeachingAccess::readScope($request)
    );

    HomeroomReadAccess::authorizeRecap($request);

    if ($request->filled('class_id')) {
        $query->whereHas(
            'komponenNilai.jadwalPelajaran',
            fn (Builder $builder) => $builder->where(
                'kelas_id',
                $request->integer('class_id')
            )
        );
    }

    if ($academicYearId !== null) {
        $query->whereHas(
            'komponenNilai.jadwalPelajaran',
            fn (Builder $builder) => $builder->where(
                'tahun_akademik_id',
                $academicYearId
            )
        );
    }

    if ($request->filled('semester')) {
        $semester = $request->string('semester')->toString();

        $query->whereHas(
            'komponenNilai.jadwalPelajaran.tahunAkademik',
            fn (Builder $builder) => $builder->where(
                'semester',
                $semester
            )
        );
    }

    $recap = $query
        ->selectRaw('COUNT(*) as total')
        ->selectRaw('AVG(nilai) as rata_rata')
        ->selectRaw('MAX(nilai) as tertinggi')
        ->selectRaw('MIN(nilai) as terendah')
        ->first();

    return response()->json([
        'data' => [
            'total' => (int) ($recap->total ?? 0),
            'rata_rata' => round((float) ($recap->rata_rata ?? 0), 2),
            'tertinggi' => $recap->tertinggi !== null
                ? (float) $recap->tertinggi
                : null,
            'terendah' => $recap->terendah !== null
                ? (float) $recap->terendah
                : null,
        ],
    ]);
}

    public function store(
        StoreGradeRequest $request
    ): JsonResponse {
        return DB::transaction(function () use ($request): JsonResponse {
            $component = KomponenNilai::query()->lockForUpdate()
                ->with([
                    'jadwalPelajaran',
                ])
                ->findOrFail(
                    $request->integer('komponen_nilai_id')
                );

            if (
                ! $this->canManageGrade(
                    $request->user(),
                    $component
                )
            ) {
                return response()->json([
                    'message' =>
                        'Anda tidak memiliki akses untuk mengelola nilai pada komponen ini.',
                ], 403);
            }

            $studentId = $request->integer('siswa_id');
            if (! GradeTeachingAccess::activeStudent($component->jadwalPelajaran, $studentId)) {
                throw ValidationException::withMessages(['siswa_id' => 'Siswa bukan anggota aktif kelas pada jadwal ini.']);
            }
            if ($component->nilai()->where('siswa_id', $studentId)->exists()) {
                throw ValidationException::withMessages(['siswa_id' => 'Nilai untuk siswa dan komponen ini sudah ada.']);
            }
            try {
                $grade = Nilai::create($request->validated());
            } catch (UniqueConstraintViolationException $e) {
                throw ValidationException::withMessages(['siswa_id' => 'Nilai sudah tercatat. Muat ulang sebelum mengubahnya.']);
            }

            return ApiResponse::message(
                'Nilai berhasil ditambahkan.',
                new GradeResource(
                    $grade->load([
                        'komponenNilai.jadwalPelajaran.tahunAkademik',
                        'komponenNilai.jadwalPelajaran.kelas',
                        'komponenNilai.jadwalPelajaran.mapel',
                        'komponenNilai.jadwalPelajaran.guru',
                        'siswa',
                    ])
                ),
                201
            );
        });
    }

    public function update(
        UpdateGradeRequest $request,
        Nilai $grade
    ): JsonResponse {
        return DB::transaction(function () use ($request, $grade): JsonResponse {
            $component = KomponenNilai::query()->lockForUpdate()->with('jadwalPelajaran')->findOrFail($grade->komponen_nilai_id);
            $grade = Nilai::query()->lockForUpdate()->findOrFail($grade->id);
            $grade->setRelation('komponenNilai', $component);

            if (
                ! $this->canManageGrade(
                    $request->user(),
                    $grade->komponenNilai
                )
            ) {
                return response()->json([
                    'message' =>
                        'Anda tidak memiliki akses untuk mengubah nilai ini.',
                ], 403);
            }

            if (! GradeTeachingAccess::activeStudent($component->jadwalPelajaran, (int) $grade->siswa_id)) {
                throw ValidationException::withMessages(['siswa_id' => 'Siswa bukan anggota aktif kelas pada jadwal ini.']);
            }
            $grade->update($request->validated());

            return ApiResponse::message(
                'Nilai berhasil diperbarui.',
                new GradeResource(
                    $grade->refresh()->load([
                        'komponenNilai.jadwalPelajaran.tahunAkademik',
                        'komponenNilai.jadwalPelajaran.kelas',
                        'komponenNilai.jadwalPelajaran.mapel',
                        'komponenNilai.jadwalPelajaran.guru',
                        'siswa',
                    ])
                )
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
            $query->whereHas('komponenNilai.jadwalPelajaran', fn (Builder $schedule) => GradeTeachingAccess::apply($schedule, $user, $scope));
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
                    'komponenNilai.jadwalPelajaran',
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
                    'komponenNilai.jadwalPelajaran.kelas',
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

            $query->where(
                'siswa_id',
                $studentId
            );

            if ($academicYearId !== null) {
                $query->whereHas(
                    'komponenNilai.jadwalPelajaran',
                    fn (Builder $builder) => $builder->where(
                        'tahun_akademik_id',
                        $academicYearId
                    )
                );
            }

            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function canManageGrade(?User $user, ?KomponenNilai $component): bool
    {
        return GradeTeachingAccess::canManage($user, $component?->jadwalPelajaran);
    }
}
