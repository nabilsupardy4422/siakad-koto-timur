<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreScheduleRequest;
use App\Http\Requests\UpdateScheduleRequest;
use App\Http\Resources\ScheduleResource;
use App\Models\JadwalPelajaran;
use App\Models\TahunAkademik;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    /**
     * Menampilkan daftar jadwal berdasarkan role pengguna.
     *
     * TU dan Kepala Sekolah:
     * - Dapat melihat semua periode.
     * - Dapat menggunakan filter tahun akademik dan semester.
     *
     * Guru:
     * - Hanya jadwal mengajarnya sendiri.
     * - Jadwal kelas yang menjadi tanggung jawabnya sebagai Wali Kelas.
     * - Hanya periode akademik aktif.
     *
     * Siswa:
     * - Hanya jadwal kelas yang sedang diikutinya.
     * - Hanya periode akademik aktif.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->loadMissing([
            'role',
            'guru',
            'siswa',
        ]);

        $role = $user->role?->code;

        $academicYearId = $request->filled('academic_year_id')
            ? $request->integer('academic_year_id')
            : null;

        // Guru dan Siswa wajib menggunakan tahun akademik aktif.
        if (in_array($role, ['GURU', 'SISWA'], true)) {
            $activeAcademicYear = TahunAkademik::query()
                ->where('is_active', true)
                ->first();

            if (! $activeAcademicYear) {
                return response()->json([
                    'message' => 'Tahun akademik aktif belum ditentukan. Silakan hubungi administrator sekolah.',
                ], 409);
            }

            // Abaikan academic_year_id dari request.
            $academicYearId = (int) $activeAcademicYear->id;
        }

        $query = JadwalPelajaran::query()
            ->with([
                'tahunAkademik',
                'kelas',
                'mapel',
                'guru',
            ])
            ->latest('id');

        $this->applyViewScope(
            $query,
            $user,
            $academicYearId
        );

        // Pembatasan periode berlaku untuk seluruh hasil query.
        if ($academicYearId !== null) {
            $query->where(
                'tahun_akademik_id',
                $academicYearId
            );
        }

        // Filter semester hanya digunakan oleh TU dan Kepala Sekolah.
        // Guru dan Siswa otomatis mengikuti semester aktif.
        if (
            ! in_array($role, ['GURU', 'SISWA'], true) &&
            $request->filled('semester')
        ) {
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

    /**
     * Menambahkan jadwal pelajaran.
     * Akses endpoint tetap dibatasi melalui middleware TU.
     */
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

    /**
     * Menampilkan detail jadwal.
     *
     * Guru dan Siswa hanya boleh membuka jadwal
     * pada tahun akademik aktif.
     */
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

        $role = $user->role?->code;

        $academicYearId = $request->filled('academic_year_id')
            ? $request->integer('academic_year_id')
            : null;

        if (in_array($role, ['GURU', 'SISWA'], true)) {
            $activeAcademicYear = TahunAkademik::query()
                ->where('is_active', true)
                ->first();

            if (! $activeAcademicYear) {
                return response()->json([
                    'message' => 'Tahun akademik aktif belum ditentukan. Silakan hubungi administrator sekolah.',
                ], 409);
            }

            // Jangan izinkan request mengganti periode aktif.
            $academicYearId = (int) $activeAcademicYear->id;
        }

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

    /**
     * Memperbarui jadwal pelajaran.
     */
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

    /**
     * Menghapus jadwal pelajaran.
     */
    public function destroy(
        JadwalPelajaran $schedule
    ): JsonResponse {
        $schedule->delete();

        return ApiResponse::message(
            'Jadwal pelajaran berhasil dihapus.'
        );
    }

    /**
     * Membatasi query jadwal berdasarkan role.
     */
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

    /**
     * Memeriksa apakah pengguna boleh melihat detail jadwal.
     */
    private function canViewSchedule(
        JadwalPelajaran $schedule,
        User $user,
        ?int $academicYearId
    ): bool {
        if (
            $academicYearId !== null &&
            (int) $schedule->tahun_akademik_id !== $academicYearId
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

            if ((int) $schedule->guru_id === (int) $guruId) {
                return true;
            }

            return $schedule->kelas()
                ->whereHas(
                    'waliKelas',
                    function (Builder $builder) use (
                        $guruId,
                        $academicYearId
                    ): void {
                        $builder
                            ->where('guru_id', $guruId)
                            ->whereNull('tanggal_selesai');

                        if ($academicYearId !== null) {
                            $builder->where(
                                'tahun_akademik_id',
                                $academicYearId
                            );
                        }
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
                        $studentId,
                        $academicYearId
                    ): void {
                        $builder
                            ->where('siswa_id', $studentId)
                            ->whereNull('tanggal_selesai');

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
                )
                ->exists();
        }

        return false;
    }
}
