<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreScheduleRequest;
use App\Http\Requests\UpdateScheduleRequest;
use App\Http\Resources\ScheduleResource;
use App\Models\JadwalPelajaran;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->loadMissing([
            'role',
            'guru',
            'siswa',
        ]);

        $query = JadwalPelajaran::query()
            ->with([
                'tahunAkademik',
                'kelas',
                'mapel',
                'guru',
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

        if ($academicYearId !== null) {
            $query->where(
                'tahun_akademik_id',
                $academicYearId
            );
        }

        if ($request->filled('semester')) {
            $semester = $request
                ->string('semester')
                ->toString();

            $query->whereHas(
                'tahunAkademik',
                fn (Builder $builder) => $builder->where(
                    'semester',
                    $semester
                )
            );
        }

        if ($request->filled('class_id')) {
            $query->where(
                'kelas_id',
                $request->integer('class_id')
            );
        }

        if ($request->filled('subject_id')) {
            $query->where(
                'mapel_id',
                $request->integer('subject_id')
            );
        }

        if ($request->filled('teacher_id')) {
            $query->where(
                'guru_id',
                $request->integer('teacher_id')
            );
        }

        if ($request->filled('day')) {
            $query->where(
                'hari',
                $request->string('day')->toString()
            );
        }

        $schedules = $query
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return ApiResponse::collection(
            ScheduleResource::collection($schedules->items()),
            [
                'current_page' => $schedules->currentPage(),
                'last_page' => $schedules->lastPage(),
                'per_page' => $schedules->perPage(),
                'total' => $schedules->total(),
            ]
        );
    }

    public function store(
        StoreScheduleRequest $request
    ): JsonResponse {
        $schedule = JadwalPelajaran::create(
            $request->validated()
        );

        return ApiResponse::message(
            'Jadwal pelajaran berhasil ditambahkan.',
            new ScheduleResource(
                $schedule->load([
                    'tahunAkademik',
                    'kelas',
                    'mapel',
                    'guru',
                ])
            ),
            201
        );
    }

    public function show(
        Request $request,
        JadwalPelajaran $schedule
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

        if (! $this->canViewSchedule(
            $schedule,
            $user,
            $academicYearId
        )) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses ke resource ini.',
            ], 403);
        }

        return ApiResponse::success(
            new ScheduleResource(
                $schedule->load([
                    'tahunAkademik',
                    'kelas',
                    'mapel',
                    'guru',
                ])
            )
        );
    }

    public function update(
        UpdateScheduleRequest $request,
        JadwalPelajaran $schedule
    ): JsonResponse {
        $schedule->update(
            $request->validated()
        );

        return ApiResponse::message(
            'Jadwal pelajaran berhasil diperbarui.',
            new ScheduleResource(
                $schedule->refresh()->load([
                    'tahunAkademik',
                    'kelas',
                    'mapel',
                    'guru',
                ])
            )
        );
    }

    public function destroy(
        JadwalPelajaran $schedule
    ): JsonResponse {
        $schedule->delete();

        return ApiResponse::message(
            'Jadwal pelajaran berhasil dihapus.'
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

            $query->where(function (Builder $builder) use (
                $guruId,
                $academicYearId
            ): void {
                // Jadwal yang diajar oleh guru tersebut.
                $builder->where('guru_id', $guruId);

                if ($academicYearId !== null) {
                    $builder->where(
                        'tahun_akademik_id',
                        $academicYearId
                    );
                }

                // Jadwal dari kelas yang sedang menjadi Wali Kelas.
                $builder->orWhereHas(
                    'kelas',
                    function (Builder $classQuery) use (
                        $guruId,
                        $academicYearId
                    ): void {
                        $classQuery->whereHas(
                            'waliKelas',
                            function (Builder $waliQuery) use (
                                $guruId,
                                $academicYearId
                            ): void {
                                $waliQuery
                                    ->where('guru_id', $guruId)
                                    ->whereNull('tanggal_selesai');

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

            $query->whereHas(
                'kelas.anggotaKelas',
                function (Builder $builder) use (
                    $studentId,
                    $academicYearId
                ): void {
                    $builder->where(
                        'siswa_id',
                        $studentId
                    );

                    $builder->whereNull(
                        'tanggal_selesai'
                    );

                    if ($academicYearId !== null) {
                        $builder->whereHas(
                            'kelas',
                            fn (Builder $classQuery) => $classQuery
                                ->where(
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

    private function canViewSchedule(
        JadwalPelajaran $schedule,
        User $user,
        ?int $academicYearId
    ): bool {
        if (
            $academicYearId !== null &&
            $schedule->tahun_akademik_id !== $academicYearId
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
                    function (Builder $builder) use (
                        $guruId
                    ): void {
                        $builder
                            ->where('guru_id', $guruId)
                            ->whereNull('tanggal_selesai');
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
                    function (Builder $builder) use (
                        $studentId
                    ): void {
                        $builder
                            ->where('siswa_id', $studentId)
                            ->whereNull('tanggal_selesai');
                    }
                )
                ->exists();
        }

        return false;
    }
}