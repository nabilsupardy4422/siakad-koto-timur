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
            $academicYearId
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

    public function store(
        StoreGradeRequest $request
    ): JsonResponse {
        $component = KomponenNilai::query()
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

        $grade = Nilai::create(
            $request->validated()
        );

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
    }

    public function update(
        UpdateGradeRequest $request,
        Nilai $grade
    ): JsonResponse {
        $grade->load([
            'komponenNilai.jadwalPelajaran',
        ]);

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

        $grade->update(
            $request->validated()
        );

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
                                $waliQuery
                                    ->where(
                                        'guru_id',
                                        $guruId
                                    )
                                    ->whereNull(
                                        'tanggal_selesai'
                                    );

                                if ($academicYearId !== null) {
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

    private function canManageGrade(
        ?User $user,
        ?KomponenNilai $component
    ): bool {
        if ($user === null || $component === null) {
            return false;
        }

        if ($user->role?->code !== 'GURU') {
            return false;
        }

        $guruId = $user->guru?->id;

        if ($guruId === null) {
            return false;
        }

        $schedule = $component->jadwalPelajaran;

        if ($schedule === null) {
            return false;
        }

        return $schedule->guru_id === $guruId;
    }
}