<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAttendanceRequest;
use App\Http\Requests\UpdateAttendanceRequest;
use App\Http\Resources\AttendanceResource;
use App\Models\Guru;
use App\Models\JadwalPelajaran;
use App\Models\Presensi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Presensi::query()
            ->with([
                'jadwalPelajaran.kelas',
                'jadwalPelajaran.mapel',
                'jadwalPelajaran.guru',
                'siswa',
            ])
            ->latest('tanggal')
            ->latest('id');

        $this->applyViewScope($query, $user);

        if ($request->filled('academic_year_id')) {
            $query->whereHas(
                'jadwalPelajaran',
                fn ($scheduleQuery) => $scheduleQuery
                    ->where(
                        'tahun_akademik_id',
                        $request->integer('academic_year_id')
                    )
            );
        }

        if ($request->filled('schedule_id')) {
            $query->where(
                'jadwal_pelajaran_id',
                $request->integer('schedule_id')
            );
        }

        if ($request->filled('class_id')) {
            $query->whereHas(
                'jadwalPelajaran',
                fn ($scheduleQuery) => $scheduleQuery
                    ->where(
                        'kelas_id',
                        $request->integer('class_id')
                    )
            );
        }

        if ($request->filled('student_id')) {
            $query->where(
                'siswa_id',
                $request->integer('student_id')
            );
        }

        if ($request->filled('date')) {
            $query->whereDate(
                'tanggal',
                $request->input('date')
            );
        }

        if ($request->filled('date_from')) {
            $query->whereDate(
                'tanggal',
                '>=',
                $request->input('date_from')
            );
        }

        if ($request->filled('date_to')) {
            $query->whereDate(
                'tanggal',
                '<=',
                $request->input('date_to')
            );
        }

        if ($request->filled('status')) {
            $query->where(
                'status',
                $request->input('status')
            );
        }

        $perPage = min(
            max($request->integer('per_page', 20), 1),
            100
        );

        $attendance = $query->paginate($perPage);

        return response()->json([
            'data' => AttendanceResource::collection(
                $attendance->items()
            ),
            'meta' => [
                'current_page' => $attendance->currentPage(),
                'last_page' => $attendance->lastPage(),
                'per_page' => $attendance->perPage(),
                'total' => $attendance->total(),
            ],
        ]);
    }

    public function store(
        StoreAttendanceRequest $request
    ): JsonResponse {
        $user = $request->user();

        $schedule = JadwalPelajaran::query()
            ->findOrFail(
                $request->integer('jadwal_pelajaran_id')
            );

        if (!$this->canManageAttendance($user, $schedule)) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses ke resource ini.',
            ], 403);
        }

        $attendance = DB::transaction(
            function () use ($request): Presensi {
                return Presensi::create([
                    'jadwal_pelajaran_id' => $request->integer(
                        'jadwal_pelajaran_id'
                    ),
                    'siswa_id' => $request->integer('siswa_id'),
                    'tanggal' => $request->input('tanggal'),
                    'status' => $request->input('status'),
                    'catatan' => $request->input('catatan'),
                ]);
            }
        );

        $attendance->load([
            'jadwalPelajaran',
            'siswa',
        ]);

        return response()->json([
            'data' => new AttendanceResource($attendance),
        ], 201);
    }

    public function update(
        UpdateAttendanceRequest $request,
        Presensi $attendance
    ): JsonResponse {
        $user = $request->user();

        $attendance->load('jadwalPelajaran');

        if (
            !$attendance->jadwalPelajaran ||
            !$this->canManageAttendance(
                $user,
                $attendance->jadwalPelajaran
            )
        ) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses ke resource ini.',
            ], 403);
        }

        $attendance->update(
            $request->validated()
        );

        $attendance->load([
            'jadwalPelajaran',
            'siswa',
        ]);

        return response()->json([
            'data' => new AttendanceResource($attendance),
        ]);
    }

    public function recap(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Presensi::query();

        $this->applyViewScope($query, $user);

        if ($request->filled('academic_year_id')) {
            $query->whereHas(
                'jadwalPelajaran',
                fn ($scheduleQuery) => $scheduleQuery
                    ->where(
                        'tahun_akademik_id',
                        $request->integer('academic_year_id')
                    )
            );
        }

        if ($request->filled('class_id')) {
            $query->whereHas(
                'jadwalPelajaran',
                fn ($scheduleQuery) => $scheduleQuery
                    ->where(
                        'kelas_id',
                        $request->integer('class_id')
                    )
            );
        }

        if ($request->filled('student_id')) {
            $query->where(
                'siswa_id',
                $request->integer('student_id')
            );
        }

        if ($request->filled('date_from')) {
            $query->whereDate(
                'tanggal',
                '>=',
                $request->input('date_from')
            );
        }

        if ($request->filled('date_to')) {
            $query->whereDate(
                'tanggal',
                '<=',
                $request->input('date_to')
            );
        }

        $recap = $query
            ->selectRaw('COUNT(*) as total')
            ->selectRaw(
                "SUM(CASE WHEN status = 'HADIR' THEN 1 ELSE 0 END) as hadir"
            )
            ->selectRaw(
                "SUM(CASE WHEN status = 'IZIN' THEN 1 ELSE 0 END) as izin"
            )
            ->selectRaw(
                "SUM(CASE WHEN status = 'SAKIT' THEN 1 ELSE 0 END) as sakit"
            )
            ->selectRaw(
                "SUM(CASE WHEN status = 'ALPA' THEN 1 ELSE 0 END) as alpa"
            )
            ->first();

        return response()->json([
            'data' => [
                'total' => (int) ($recap->total ?? 0),
                'hadir' => (int) ($recap->hadir ?? 0),
                'izin' => (int) ($recap->izin ?? 0),
                'sakit' => (int) ($recap->sakit ?? 0),
                'alpa' => (int) ($recap->alpa ?? 0),
            ],
        ]);
    }

    private function applyViewScope(
        $query,
        $user
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
            $guru = Guru::query()
                ->where('user_id', $user->id)
                ->first();

            if (!$guru) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->where(function ($attendanceQuery) use ($guru): void {
                $attendanceQuery
                    ->whereHas(
                        'jadwalPelajaran',
                        fn ($scheduleQuery) => $scheduleQuery
                            ->where('guru_id', $guru->id)
                    )
                    ->orWhereHas(
                        'jadwalPelajaran',
                        function ($scheduleQuery) use ($guru): void {
                            $scheduleQuery->whereHas(
                                'kelas.waliKelas',
                                function ($waliKelasQuery) use ($guru): void {
                                    $waliKelasQuery
                                        ->where('guru_id', $guru->id)
                                        ->whereNull('tanggal_selesai')
                                        ->whereColumn(
                                            'wali_kelas.tahun_akademik_id',
                                            'jadwal_pelajaran.tahun_akademik_id'
                                        );
                                }
                            );
                        }
                    );
            });

            return;
        }

        if ($role === 'SISWA') {
            $siswa = $user->siswa;

            if (!$siswa) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->where(
                'siswa_id',
                $siswa->id
            );

            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function canManageAttendance(
        $user,
        JadwalPelajaran $schedule
    ): bool {
        if ($user->role?->code !== 'GURU') {
            return false;
        }

        $guru = Guru::query()
            ->where('user_id', $user->id)
            ->first();

        if (!$guru) {
            return false;
        }

        return (int) $schedule->guru_id === (int) $guru->id;
    }
}