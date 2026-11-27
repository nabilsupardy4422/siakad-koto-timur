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
use App\Models\AnggotaKelas;
use App\Models\Kelas;
use App\Models\TahunAkademik;
use App\Http\Requests\BulkAttendanceRequest;
use App\Models\Siswa;
use App\Support\TeachingAssignmentScope;
use App\Support\HomeroomReadAccess;
use App\Support\AttendanceTeachingAccess;
use App\Support\MembershipWriteGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;

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

        $scope = $request->validate(['scope' => ['sometimes', 'in:teaching,homeroom,combined']])['scope'] ?? TeachingAssignmentScope::LEGACY;
        $this->applyViewScope($query, $user, $scope);

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

        try {
            $attendance = DB::transaction(
                function () use ($request,$schedule): Presensi {
                    MembershipWriteGuard::lock([$request->integer('siswa_id')]);
                    MembershipWriteGuard::requireEffective((int)$schedule->kelas_id,$request->integer('siswa_id'),$request->input('tanggal'));
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
        } catch (UniqueConstraintViolationException $e) {
            return response()->json(['message' => 'Presensi sudah tercatat. Muat ulang daftar siswa sebelum menyimpan kembali.'], 409);
        }

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

        try {
            DB::transaction(function()use($attendance,$request){
                MembershipWriteGuard::lock([(int)$attendance->siswa_id]);
                $current=Presensi::whereKey($attendance->id)->lockForUpdate()->firstOrFail();
                $date=$request->input('tanggal',$current->tanggal->toDateString());
                abort_if($date > today('Asia/Jakarta')->toDateString(),422,'Tanggal presensi tidak boleh melebihi hari ini (WIB).');
                MembershipWriteGuard::requireEffective((int)$attendance->jadwalPelajaran->kelas_id,(int)$current->siswa_id,$date,'tanggal');
                $current->update($request->validated());
            },3);
            $attendance->refresh();
        } catch (UniqueConstraintViolationException $e) {
            return response()->json(['message' => 'Presensi pada tanggal tersebut sudah tercatat.'], 409);
        }

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

        $scope = $request->validate(['scope' => ['sometimes', 'in:teaching,homeroom,combined']])['scope'] ?? TeachingAssignmentScope::LEGACY;
        $this->applyViewScope($query, $user, $scope);
        HomeroomReadAccess::authorizeRecap($request);

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

        if ($scope === TeachingAssignmentScope::HOMEROOM) {
            $query->whereIn('presensi.id', \App\Services\TeacherMonitoringIntegrity::attendanceIds());
        }

        $statusColumn = $scope === TeachingAssignmentScope::HOMEROOM ? 'UPPER(status)' : 'status';
        $recap = $query
            ->selectRaw('COUNT(*) as total')
            ->selectRaw(
                "SUM(CASE WHEN {$statusColumn} = 'HADIR' THEN 1 ELSE 0 END) as hadir"
            )
            ->selectRaw(
                "SUM(CASE WHEN {$statusColumn} = 'IZIN' THEN 1 ELSE 0 END) as izin"
            )
            ->selectRaw(
                "SUM(CASE WHEN {$statusColumn} = 'SAKIT' THEN 1 ELSE 0 END) as sakit"
            )
            ->selectRaw(
                "SUM(CASE WHEN {$statusColumn} = 'ALPA' THEN 1 ELSE 0 END) as alpa"
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
        $user,
        string $scope = TeachingAssignmentScope::LEGACY
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
            // Explicit workspace contexts use the same active-period scope as schedules.
            // Keep the existing combined read for legacy reports during migration.
            if ($scope !== TeachingAssignmentScope::LEGACY) {
                $yearIds = TahunAkademik::query()->where('is_active', true)->pluck('id');
                if ($yearIds->count() !== 1) {
                    $query->whereRaw('1 = 0');
                    return;
                }
                $query->whereHas('jadwalPelajaran', function (Builder $schedule) use ($user, $yearIds, $scope): void {
                    TeachingAssignmentScope::apply($schedule, $user->guru?->id, (int) $yearIds->first(), $scope);
                });
                return;
            }

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
                                    HomeroomReadAccess::restrictCurrent($waliKelasQuery, (int) $guru->id);
                                    $waliKelasQuery->whereColumn('wali_kelas.tahun_akademik_id', 'jadwal_pelajaran.tahun_akademik_id');
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


    public function classRecap(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'academic_year_id' => ['required', 'integer', 'min:1'],
            'date' => ['required', 'date_format:Y-m-d'],
            'class_id' => ['nullable', 'integer', 'min:1'],
            'semester' => ['nullable', 'in:GANJIL,GENAP'],
        ]);

        $academicYear = TahunAkademik::query()
            ->find($validated['academic_year_id']);

        if (!$academicYear) {
            return response()->json([
                'message' => 'Tahun akademik tidak ditemukan.',
            ], 404);
        }

        if (isset($validated['class_id'])) {
            $selectedClass = Kelas::query()->find($validated['class_id']);

            if (!$selectedClass) {
                return response()->json([
                    'message' => 'Kelas tidak ditemukan.',
                ], 404);
            }

            if ((int) $selectedClass->tahun_akademik_id !== (int) $academicYear->id) {
                return response()->json([
                    'message' => 'Kelas tidak termasuk dalam tahun akademik yang dipilih.',
                ], 422);
            }
        }

        if (
            isset($validated['semester']) &&
            $academicYear->semester !== $validated['semester']
        ) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'date' => $validated['date'],
                    'academic_year_id' => $academicYear->id,
                    'semester' => $validated['semester'],
                    'total_classes' => 0,
                ],
            ]);
        }

        $date = $validated['date'];

        $classesQuery = Kelas::query()
            ->where('tahun_akademik_id', $academicYear->id)
            ->orderBy('tingkat')
            ->orderBy('nama');

        if (isset($validated['class_id'])) {
            $classesQuery->where('id', $validated['class_id']);
        }

        $classes = $classesQuery->get();

        $data = $classes->map(function (Kelas $class) use (
            $date,
            $academicYear
        ): array {
            // Ambil anggota kelas yang aktif pada tanggal rekap.
            $members = AnggotaKelas::query()
                ->where('kelas_id', $class->id)
                ->whereDate('tanggal_mulai', '<=', $date)
                ->where(function ($query) use ($date) {
                    $query->whereNull('tanggal_selesai')
                        ->orWhereDate('tanggal_selesai', '>=', $date);
                })
                ->get(['siswa_id']);

            $studentIds = $members->pluck('siswa_id')
                ->unique()
                ->values();

            // Inisialisasi status untuk semua siswa terdaftar.
            // Gunakan array PHP biasa agar elemen status dapat ditambahkan.
            $statusByStudent = [];

            foreach ($studentIds as $studentId) {
                $statusByStudent[$studentId] = [];
            }

            // Ambil catatan semua jadwal kelas pada tanggal tersebut.
            $attendanceRecords = Presensi::query()
                ->whereDate('tanggal', $date)
                ->whereIn('siswa_id', $studentIds)
                ->whereHas('jadwalPelajaran', function ($query) use (
                    $class,
                    $academicYear
                ) {
                    $query->where('kelas_id', $class->id)
                        ->where(
                            'tahun_akademik_id',
                            $academicYear->id
                        );
                })
                ->get(['siswa_id', 'status']);

            foreach ($attendanceRecords as $record) {
                $statusByStudent[$record->siswa_id][] = $record->status;
            }

            $counts = [
                'hadir' => 0,
                'izin' => 0,
                'sakit' => 0,
                'alpa' => 0,
                'belum_ada_data' => 0,
            ];

            foreach ($studentIds as $studentId) {
                $statuses = $statusByStudent[$studentId] ?? [];

                if (empty($statuses)) {
                    $counts['belum_ada_data']++;
                    continue;
                }

                // Prioritas status harian:
                // ALPA > SAKIT > IZIN > HADIR.
                if (in_array('ALPA', $statuses, true)) {
                    $counts['alpa']++;
                } elseif (in_array('SAKIT', $statuses, true)) {
                    $counts['sakit']++;
                } elseif (in_array('IZIN', $statuses, true)) {
                    $counts['izin']++;
                } elseif (in_array('HADIR', $statuses, true)) {
                    $counts['hadir']++;
                } else {
                    $counts['belum_ada_data']++;
                }
            }

            return [
                'class_id' => $class->id,
                'nama_kelas' => $class->nama,
                'tingkat' => $class->tingkat,
                'total_siswa' => $studentIds->count(),
                ...$counts,
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'date' => $date,
                'academic_year_id' => $academicYear->id,
                'semester' => $academicYear->semester,
                'total_classes' => $data->count(),
            ],
        ]);
    }


    private function canManageAttendance($user, JadwalPelajaran $schedule): bool
    {
        return AttendanceTeachingAccess::canManage($user, $schedule);
    }

    /** Roster for one owned schedule, evaluated on the attendance date. */
    public function roster(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'schedule_id' => ['required', 'integer', 'min:1'],
            'date' => ['required', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $schedule = JadwalPelajaran::query()->findOrFail($validated['schedule_id']);
        abort_unless($this->canManageAttendance($request->user(), $schedule), 403);
        $date = $validated['date'];
        abort_if($date > today('Asia/Jakarta')->toDateString(), 422, 'Tanggal presensi tidak boleh melebihi hari ini (WIB).');
        $students = Siswa::query()
            ->whereHas('anggotaKelas', function (Builder $membership) use ($schedule, $date): void {
                $membership->where('kelas_id', $schedule->kelas_id)
                    ->whereDate('tanggal_mulai', '<=', $date)
                    ->where(function (Builder $end) use ($date): void {
                        $end->whereNull('tanggal_selesai')->orWhereDate('tanggal_selesai', '>=', $date);
                    });
            })
            ->orderBy('nama_lengkap')->orderBy('id')
            ->paginate($validated['per_page'] ?? 100, ['id', 'nama_lengkap', 'nis', 'nisn']);

        return response()->json([
            'data' => $students->items(),
            'meta' => [
                'current_page' => $students->currentPage(),
                'last_page' => $students->lastPage(),
                'per_page' => $students->perPage(),
                'total' => $students->total(),
                'schedule_id' => $schedule->id,
                'class_id' => $schedule->kelas_id,
                'academic_year_id' => $schedule->tahun_akademik_id,
                'date' => $date,
            ],
        ]);
    }

    public function bulkStore(
        BulkAttendanceRequest $request
    ): JsonResponse {
        $user = $request->user();
        $validated = $request->validated();

        $schedule = JadwalPelajaran::query()
            ->findOrFail($validated['jadwal_pelajaran_id']);

        if (!$this->canManageAttendance($user, $schedule)) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses ke jadwal ini.',
            ], 403);
        }

        $date = $validated['tanggal'];

        $classId = (int) $schedule->kelas_id;

        $attendanceRows = collect($validated['presensi']);

        $studentIds = $attendanceRows
            ->pluck('siswa_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // Pastikan seluruh siswa merupakan anggota aktif kelas
        // pada tanggal presensi yang dipilih.
        $validStudentIds = AnggotaKelas::query()
            ->where('kelas_id', $classId)
            ->whereIn('siswa_id', $studentIds)
            ->whereDate('tanggal_mulai', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('tanggal_selesai')
                    ->orWhereDate('tanggal_selesai', '>=', $date);
            })
            ->pluck('siswa_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $invalidStudentIds = array_values(
            array_diff($studentIds, $validStudentIds)
        );

        if ($invalidStudentIds !== []) {
            return response()->json([
                'message' => 'Terdapat siswa yang bukan anggota aktif kelas pada tanggal tersebut.',
                'invalid_student_ids' => $invalidStudentIds,
            ], 422);
        }

        // Seluruh perubahan berada dalam satu transaksi.
        try {
            $result = DB::transaction(function () use (
                $attendanceRows,
                $schedule,
                $date
            ): array {
                MembershipWriteGuard::lock($attendanceRows->pluck('siswa_id')->all());
                foreach($attendanceRows as $row) MembershipWriteGuard::requireEffective((int)$schedule->kelas_id,(int)$row['siswa_id'],$date);
                $created = 0;
                $updated = 0;

                foreach ($attendanceRows as $row) {
                    $attendance = Presensi::query()
                        ->where(
                            'jadwal_pelajaran_id',
                            $schedule->id
                        )
                        ->where('siswa_id', $row['siswa_id'])
                        ->whereDate('tanggal', $date)
                        ->lockForUpdate()
                        ->first();

                    $attributes = [
                        'status' => $row['status'],
                        'catatan' => $row['catatan'] ?? null,
                    ];

                    if ($attendance) {
                        $attendance->update($attributes);
                        $updated++;

                        continue;
                    }

                    Presensi::create([
                        'jadwal_pelajaran_id' => $schedule->id,
                        'siswa_id' => $row['siswa_id'],
                        'tanggal' => $date,
                        ...$attributes,
                    ]);

                    $created++;
                }

                return [
                    'created' => $created,
                    'updated' => $updated,
                ];
            });
        } catch (UniqueConstraintViolationException $e) {
            return response()->json(['message' => 'Presensi berubah di sesi lain. Muat ulang sebelum menyimpan kembali.'], 409);
        }

        return response()->json([
            'message' => 'Presensi kelas berhasil disimpan.',
            'data' => [
                'jadwal_pelajaran_id' => $schedule->id,
                'tanggal' => $date,
                'total_siswa' => count($studentIds),
                'created' => $result['created'],
                'updated' => $result['updated'],
            ],
        ]);
    }

}
