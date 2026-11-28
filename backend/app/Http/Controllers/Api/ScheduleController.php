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
use App\Services\ScheduleWriteService;
use App\Support\StudentScheduleAccess;
use App\Support\TeachingAssignmentScope;
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
     * - scope=teaching: hanya jadwal mengajar sendiri.
     * - scope=homeroom: hanya jadwal kelas binaan yang masih terbuka.
     * - Tanpa scope: gabungan lama untuk kompatibilitas sementara.
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

        $validated = $request->validate([
            'scope' => 'sometimes|required|string|in:teaching,homeroom',
            'history' => 'sometimes|boolean',
        ]);
        $scope = $validated['scope'] ?? TeachingAssignmentScope::LEGACY;

        if (isset($validated['scope']) && $role !== 'GURU') {
            return response()->json(['message' => 'Konteks penugasan hanya tersedia untuk Guru.'], 403);
        }

        $academicYearId = $request->filled('academic_year_id')
            ? $request->integer('academic_year_id')
            : null;

        $studentContext = null;
        if ($role === 'SISWA') {
            $periods = TahunAkademik::query()->where('is_active', true)->limit(2)->get();
            if ($periods->count() !== 1) {
                return response()->json(['message' => 'Periode akademik aktif harus tepat satu. Hubungi Tata Usaha.'], 409);
            }
            $studentContext = StudentScheduleAccess::resolve($user->siswa?->id, $periods->first());
            if ($studentContext['status'] === 'ambiguous') {
                return response()->json(['message' => 'Penempatan kelas aktif tidak konsisten. Hubungi Tata Usaha.'], 409);
            }
        }

        // History is opt-in for the teacher's own assignments only (materials library).
        // Existing calendar, attendance, student and homeroom callers stay active-period only.
        $history = $request->boolean('history');
        if ($history) {
            if ($role !== 'GURU' || $scope !== TeachingAssignmentScope::TEACHING) {
                return response()->json(['message' => 'Riwayat jadwal hanya tersedia pada konteks mengajar pribadi.'], 403);
            }
            $request->validate(['academic_year_id' => 'required|integer|exists:tahun_akademik,id']);
        } else {
            // Guru dan Siswa wajib menggunakan tahun akademik aktif.
            if (in_array($role, ['GURU', 'SISWA'], true)) {
                $activeAcademicYear = $role === 'SISWA'
                    ? $periods->first()
                    : TahunAkademik::query()->where('is_active', true)->first();

                if (! $activeAcademicYear) {
                    return response()->json([
                        'message' => 'Tahun akademik aktif belum ditentukan. Silakan hubungi administrator sekolah.',
                    ], 409);
                }

                // Abaikan academic_year_id dari request.
                $academicYearId = (int) $activeAcademicYear->id;
            }

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
            $academicYearId,
            $scope,
            $studentContext
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

        $meta = [
            'current_page' => $schedules->currentPage(),
            'last_page' => $schedules->lastPage(),
            'per_page' => $schedules->perPage(),
            'total' => $schedules->total(),
        ];
        if ($role === 'SISWA') {
            $meta['student_context'] = $studentContext;
        }

        return ApiResponse::collection(
            ScheduleResource::collection($schedules->items()),
            $meta
        );
    }

    /**
     * Menambahkan jadwal pelajaran.
     * Akses endpoint tetap dibatasi melalui middleware TU.
     */
    public function store(
        StoreScheduleRequest $request
    ): JsonResponse {
        $schedule = ScheduleWriteService::transaction(function () use ($request) {
            $data = $request->validated();
            ScheduleWriteService::validate($data);
            return JadwalPelajaran::create($data);
        });

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

        $validated = $request->validate(['scope' => 'sometimes|required|string|in:teaching,homeroom']);
        $scope = $validated['scope'] ?? TeachingAssignmentScope::LEGACY;

        if (isset($validated['scope']) && $role !== 'GURU') {
            return response()->json(['message' => 'Konteks penugasan hanya tersedia untuk Guru.'], 403);
        }

        $academicYearId = $request->filled('academic_year_id')
            ? $request->integer('academic_year_id')
            : null;

        if (in_array($role, ['GURU', 'SISWA'], true)) {
            if ($role === 'SISWA') {
                $activePeriods = TahunAkademik::query()->where('is_active', true)->limit(2)->get();
                if ($activePeriods->count() !== 1) {
                    return response()->json(['message' => 'Periode akademik aktif harus tepat satu. Hubungi Tata Usaha.'], 409);
                }
                $activeAcademicYear = $activePeriods->first();
            } else {
                $activeAcademicYear = TahunAkademik::query()->where('is_active', true)->first();
            }

            if (! $activeAcademicYear) {
                return response()->json([
                    'message' => 'Tahun akademik aktif belum ditentukan. Silakan hubungi administrator sekolah.',
                ], 409);
            }

            // Jangan izinkan request mengganti periode aktif.
            $academicYearId = (int) $activeAcademicYear->id;
        }

        if ($role === 'SISWA') {
            $context = StudentScheduleAccess::resolve($user->siswa?->id, $activeAcademicYear);
            if ($context['status'] === 'ambiguous') {
                return response()->json(['message' => 'Penempatan kelas tidak konsisten.'], 409);
            }
            if ($context['status'] !== 'active' ||
                (int) $schedule->tahun_akademik_id !== (int) $activeAcademicYear->id ||
                (int) $schedule->kelas_id !== (int) $context['class']['id']) {
                return response()->json(['message' => 'Anda tidak memiliki akses ke resource ini.'], 403);
            }
        }

        if ($role !== 'SISWA' && ! $this->canViewSchedule(
            $schedule,
            $user,
            $academicYearId,
            $scope
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
        $schedule = ScheduleWriteService::transaction(function () use ($request, $schedule) {
            $row = JadwalPelajaran::query()->lockForUpdate()->findOrFail($schedule->id);
            $version = $request->validate(['version'=>'sometimes|required|string|size:64'])['version'] ?? null;
            ScheduleWriteService::assertMutable($row, $version);
            $data = array_replace($row->only($row->getFillable()), $request->validated());
            ScheduleWriteService::validate($data, $row->id);
            $row->update($request->validated());
            return $row;
        });

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
        Request $request, JadwalPelajaran $schedule
    ): JsonResponse {
        ScheduleWriteService::transaction(function () use ($request, $schedule) {
            $row = JadwalPelajaran::query()->lockForUpdate()->findOrFail($schedule->id);
            $version = $request->validate(['version'=>'sometimes|required|string|size:64'])['version'] ?? null;
            ScheduleWriteService::assertMutable($row, $version);
            $row->delete();
        });

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
        ?int $academicYearId,
        string $scope = TeachingAssignmentScope::LEGACY,
        ?array $studentContext = null
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
            if ($academicYearId === null) {
                $query->whereRaw('1 = 0');
                return;
            }

            TeachingAssignmentScope::apply($query, $user->guru?->id, $academicYearId, $scope);
            return;
        }

        if ($role === 'SISWA') {
            if ($studentContext && $studentContext['status'] === 'active') {
                $query->where('kelas_id', $studentContext['class']['id']);
            } else {
                $query->whereRaw('1 = 0');
            }

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
        ?int $academicYearId,
        string $scope = TeachingAssignmentScope::LEGACY
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
            if ($academicYearId === null) {
                return false;
            }

            // Use exactly the same scope for the list and direct ID requests.
            $query = JadwalPelajaran::query()->whereKey($schedule->getKey());
            TeachingAssignmentScope::apply($query, $user->guru?->id, $academicYearId, $scope);

            return $query->exists();
        }

        if ($role === 'SISWA') {
            // Student details are authorized above via StudentScheduleAccess.
            // Never fall back to the legacy whereNull(tanggal_selesai) condition.
            return false;
        }

        return false;
    }
}
