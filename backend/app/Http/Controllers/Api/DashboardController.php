<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnggotaKelas;
use App\Models\Assignment;
use App\Models\BahanAjar;
use App\Models\Guru;
use App\Models\JadwalPelajaran;
use App\Models\Kelas;
use App\Models\KomponenNilai;
use App\Models\Nilai;
use App\Models\Presensi;
use App\Models\Siswa;
use App\Models\TahunAkademik;
use App\Models\User;
use App\Support\HomeroomReadAccess;
use App\Support\StudentScheduleAccess;
use App\Support\StudentMaterialAccess;
use App\Support\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->loadMissing([
            'role',
            'guru',
            'siswa',
        ]);

        $role = $user->role?->code;

        return match ($role) {
            'TU' => $this->tuDashboard(),
            'KEPALA_SEKOLAH' => $this->kepalaSekolahDashboard(),
            'GURU' => $this->guruDashboard($user),
            'SISWA' => $this->siswaDashboard($user),
            default => response()->json([
                'message' => 'Role pengguna tidak didukung untuk dashboard.',
            ], 403),
        };
    }

    private function tuDashboard(): JsonResponse
    {
        $academicYear = $this->activeAcademicYear();

        return ApiResponse::success([
            'role' => 'TU',
            'summary' => [
                'total_guru' => Guru::query()->count(),
                'total_siswa' => Siswa::query()->count(),
                'total_kelas' => Kelas::query()
                    ->when(
                        $academicYear,
                        fn ($query) => $query->where(
                            'tahun_akademik_id',
                            $academicYear->id
                        )
                    )
                    ->count(),
                'total_mata_pelajaran' => $this->subjectCount(),
                'active_academic_year' => $this->academicYearData(
                    $academicYear
                ),
            ],
            'charts' => [
                'students_by_class' => $this->studentsByClass(
                    $academicYear
                ),
            ],
            'recent' => [
                'academic_years' => TahunAkademik::query()
                    ->latest('id')
                    ->limit(5)
                    ->get()
                    ->map(fn (TahunAkademik $year) => $this->academicYearData($year))
                    ->values(),
            ],
        ]);
    }

    private function kepalaSekolahDashboard(): JsonResponse
    {
        $academicYear = $this->activeAcademicYear();

        return ApiResponse::success([
            'role' => 'KEPALA_SEKOLAH',
            'summary' => [
                'total_guru' => Guru::query()->count(),
                'total_siswa' => Siswa::query()->count(),
                'total_kelas' => Kelas::query()
                    ->when(
                        $academicYear,
                        fn ($query) => $query->where(
                            'tahun_akademik_id',
                            $academicYear->id
                        )
                    )
                    ->count(),
                'total_jadwal' => JadwalPelajaran::query()
                    ->when(
                        $academicYear,
                        fn ($query) => $query->where(
                            'tahun_akademik_id',
                            $academicYear->id
                        )
                    )
                    ->count(),
                'active_academic_year' => $this->academicYearData(
                    $academicYear
                ),
            ],
            'charts' => [
                'students_by_class' => $this->studentsByClass(
                    $academicYear
                ),
            ],
            'recent' => [
                'academic_years' => TahunAkademik::query()
                    ->latest('id')
                    ->limit(5)
                    ->get()
                    ->map(fn (TahunAkademik $year) => $this->academicYearData($year))
                    ->values(),
            ],
        ]);
    }

    private function guruDashboard(User $user): JsonResponse
    {
        $guruId = $user->guru?->id;
        $years = TahunAkademik::query()->where('is_active', true)->limit(2)->get();
        $academicYear = $years->count() === 1 ? $years->first() : null;
        $contextStatus = $years->count() > 1 ? 'conflict' : ($academicYear ? 'active' : 'none');

        if ($guruId === null) {
            return ApiResponse::success([
                'role' => 'GURU',
                'academic_year' => $this->academicYearData($academicYear),
                'context_status' => $guruId === null ? 'unlinked' : $contextStatus,
                'as_of' => today('Asia/Jakarta')->toDateString(),
                'is_wali_kelas' => false,
                'summary' => [
                    'today_schedule_count' => 0,
                    'teaching_schedule_count' => 0,
                    'material_count' => 0,
                    'assignment_count' => 0,
                    'grade_component_count' => 0,
                    'attendance_record_count' => 0,
                ],
                'wali_kelas' => null,
                'today_schedule' => [],
                'recent' => [
                    'materials' => [],
                    'assignments' => [],
                ],
            ]);
        }

        $scheduleQuery = JadwalPelajaran::query()
            ->where('guru_id', $guruId)
            ->when(
                $academicYear,
                fn ($query) => $query->where('tahun_akademik_id', $academicYear->id)
                    ->whereHas('kelas', fn ($c) => $c->where('tahun_akademik_id', $academicYear->id)),
                fn ($query) => $query->whereRaw('1=0')
            );

        $today = match (Carbon::now('Asia/Jakarta')->dayOfWeekIso) {1=>'Senin',2=>'Selasa',3=>'Rabu',4=>'Kamis',5=>'Jumat',6=>'Sabtu',7=>'Minggu'};

        $todaySchedule = (clone $scheduleQuery)
            ->whereRaw('UPPER(hari) = ?', [strtoupper($today)])
            ->with([
                'kelas',
                'mapel',
            ])
            ->orderBy('jam_mulai')
            ->get()
            ->map(fn (JadwalPelajaran $schedule) => [
                'id' => $schedule->id,
                'hari' => $schedule->hari,
                'jam_mulai' => $schedule->jam_mulai,
                'jam_selesai' => $schedule->jam_selesai,
                'kelas' => $schedule->kelas ? [
                    'id' => $schedule->kelas->id,
                    'nama' => $schedule->kelas->nama,
                    'tingkat' => $schedule->kelas->tingkat,
                ] : null,
                'mata_pelajaran' => $schedule->mapel ? [
                    'id' => $schedule->mapel->id,
                    'nama' => $schedule->mapel->nama,
                ] : null,
            ])
            ->values();

        $scheduleIds = (clone $scheduleQuery)->pluck('id');

        $materialQuery = BahanAjar::query()
            ->where('created_by', $user->id)
            ->whereIn('jadwal_pelajaran_id', $scheduleIds);

        $assignmentQuery = Assignment::query()
            ->where('created_by', $user->id)
            ->whereIn('jadwal_pelajaran_id', $scheduleIds);

        $gradeComponentQuery = KomponenNilai::query()
            ->whereIn('jadwal_pelajaran_id', $scheduleIds);

        $attendanceQuery = Presensi::query()->whereIn('jadwal_pelajaran_id', $scheduleIds)
            ->whereIn('presensi.id', \App\Services\TeacherMonitoringIntegrity::attendanceIds());

        $waliKelas = $academicYear ? HomeroomReadAccess::currentAssignments((int) $guruId, (int) $academicYear->id)
            ->with([
                'kelas',
                'tahunAkademik',
            ])
            ->latest('id')
            ->first() : null;

        $waliKelasData = null;

        if ($waliKelas) {
            $studentQuery = AnggotaKelas::query()
                ->where('kelas_id', $waliKelas->kelas_id)
                ->whereDate('tanggal_mulai','<=',today('Asia/Jakarta')->toDateString())->where(fn($q)=>$q->whereNull('tanggal_selesai')->orWhereDate('tanggal_selesai','>=',today('Asia/Jakarta')->toDateString()));

            $waliKelasData = [
                'assignment_id' => $waliKelas->id,
                'class' => [
                    'id' => $waliKelas->kelas?->id,
                    'nama' => $waliKelas->kelas?->nama,
                    'tingkat' => $waliKelas->kelas?->tingkat,
                ],
                'academic_year' => $this->academicYearData(
                    $waliKelas->tahunAkademik
                ),
                'total_students' => $studentQuery->distinct()->count('siswa_id'),
                'attendance_records' => Presensi::query()
                    ->whereHas('jadwalPelajaran', fn ($q) => $q->where('kelas_id',$waliKelas->kelas_id)->where('tahun_akademik_id',$waliKelas->tahun_akademik_id))
                    ->whereIn('presensi.id', \App\Services\TeacherMonitoringIntegrity::attendanceIds())->count(),
                'graded_records' => Nilai::query()
                    ->whereHas('komponenNilai.jadwalPelajaran', fn ($q) => $q->where('kelas_id',$waliKelas->kelas_id)->where('tahun_akademik_id',$waliKelas->tahun_akademik_id))
                    ->whereIn('nilai.id', \App\Services\TeacherMonitoringIntegrity::gradeIds())->count(),
            ];
        }

        return ApiResponse::success([
            'role' => 'GURU',
                'academic_year' => $this->academicYearData($academicYear),
                'context_status' => $guruId === null ? 'unlinked' : $contextStatus,
                'as_of' => today('Asia/Jakarta')->toDateString(),
            'is_wali_kelas' => $waliKelas !== null,
            'summary' => [
                'today_schedule_count' => $academicYear ? $todaySchedule->count() : null,
                'teaching_schedule_count' => $academicYear ? $scheduleQuery->count() : null,
                'material_count' => $academicYear ? $materialQuery->count() : null,
                'assignment_count' => $academicYear ? $assignmentQuery->count() : null,
                'grade_component_count' => $academicYear ? $gradeComponentQuery->count() : null,
                'attendance_record_count' => $academicYear ? $attendanceQuery->count() : null,
            ],
            'wali_kelas' => $waliKelasData,
            'today_schedule' => $todaySchedule,
            'recent' => [
                'materials' => (clone $materialQuery)
                    ->with('jadwalPelajaran.mapel')
                    ->latest('id')
                    ->limit(5)
                    ->get()
                    ->map(fn (BahanAjar $material) => [
                        'id' => $material->id,
                        'judul' => $material->judul,
                        'created_at' => $material->created_at?->toISOString(),
                        'mata_pelajaran' => $material->jadwalPelajaran?->mapel?->nama,
                    ])
                    ->values(),
                'assignments' => (clone $assignmentQuery)
                    ->with('jadwalPelajaran.mapel')
                    ->latest('id')
                    ->limit(5)
                    ->get()
                    ->map(fn (Assignment $assignment) => [
                        'id' => $assignment->id,
                        'judul' => $assignment->judul,
                        'deadline' => $assignment->deadline?->toISOString(),
                        'mata_pelajaran' => $assignment->jadwalPelajaran?->mapel?->nama,
                    ])
                    ->values(),
            ],
        ]);
    }

    /**
     * The legacy /api/dashboard response contract remains in use by existing
     * clients. Resolve its student scope with the same effective-membership
     * rules as StudentDashboardWorkspaceController, never with an unscoped query.
     *
     * The V2 dashboard continues to use /api/dashboard/student-workspace.
     */
    private function siswaDashboard(User $user): JsonResponse
    {
        $siswa = $user->siswa;
        $years = TahunAkademik::query()->where('is_active', true)->get();

        if ($years->count() !== 1) {
            return response()->json([
                'message' => 'Periode akademik aktif tidak tunggal.',
            ], 409);
        }

        $academicYear = $years->first();
        $context = StudentScheduleAccess::resolve($siswa?->id, $academicYear);

        if ($context['status'] === 'ambiguous') {
            return response()->json([
                'message' => 'Penempatan kelas aktif ambigu. Hubungi Tata Usaha.',
            ], 409);
        }

        // IMPORTANT: use a guaranteed non-matching class id when there is no
        // effective class. Without this where() clause, schedules from other
        // classes could leak through the original legacy endpoint.
        $classId = $context['status'] === 'active'
            ? (int) $context['class']['id']
            : 0;

        $scheduleQuery = JadwalPelajaran::query()
            ->where('tahun_akademik_id', $academicYear->id)
            ->where('kelas_id', $classId);

        // The student's academic day follows WIB, without changing the
        // existing teacher dashboard's day calculation.
        $wibDay = [
            1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis',
            5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu',
        ][Carbon::now('Asia/Jakarta')->dayOfWeekIso];

        $todaySchedule = (clone $scheduleQuery)
            ->whereRaw('LOWER(hari) = ?', [strtolower($wibDay)])
            ->with(['guru', 'mapel'])
            ->orderBy('jam_mulai')
            ->get()
            ->map(fn (JadwalPelajaran $schedule) => [
                'id' => $schedule->id,
                'hari' => $schedule->hari,
                'jam_mulai' => $schedule->jam_mulai,
                'jam_selesai' => $schedule->jam_selesai,
                'guru' => $schedule->guru ? [
                    'id' => $schedule->guru->id,
                    'nama' => $schedule->guru->nama_lengkap,
                ] : null,
                'mata_pelajaran' => $schedule->mapel ? [
                    'id' => $schedule->mapel->id,
                    'nama' => $schedule->mapel->nama,
                ] : null,
            ])
            ->values();

        $scheduleIds = (clone $scheduleQuery)->pluck('id');
        // In particular, whereIn([], ...) must never turn into an unrestricted
        // query if the student has no active class or academic profile.
        $attendanceQuery = Presensi::query()
            ->where('siswa_id', $siswa?->id ?? 0)
            ->whereIn('jadwal_pelajaran_id', $scheduleIds);

        $gradeQuery = Nilai::query()
            ->where('siswa_id', $siswa?->id ?? 0)
            ->whereHas('komponenNilai', fn ($query) =>
                $query->whereIn('jadwal_pelajaran_id', $scheduleIds)
            );

        $materialQuery = BahanAjar::query()
            ->whereIn('jadwal_pelajaran_id', $scheduleIds);
        if ($siswa) {
            // Keep material eligibility consistent with the protected
            // list/detail/download endpoints (effective at upload date).
            StudentMaterialAccess::apply($materialQuery, (int) $siswa->id);
        }

        $assignmentQuery = Assignment::query()
            ->whereIn('jadwal_pelajaran_id', $scheduleIds);

        return ApiResponse::success([
            'role' => 'SISWA',
            'summary' => [
                'attendance_count' => $attendanceQuery->count(),
                'grade_count' => $gradeQuery->count(),
                'material_count' => $materialQuery->count(),
                'assignment_count' => $assignmentQuery->count(),
            ],
            'class' => $context['status'] === 'active' ? [
                ...$context['class'],
                'academic_year' => $this->academicYearData($academicYear),
            ] : null,
            'today_schedule' => $todaySchedule,
            'recent' => [
                'materials' => (clone $materialQuery)
                    ->with('jadwalPelajaran.mapel')
                    ->latest('id')
                    ->limit(5)
                    ->get()
                    ->map(fn (BahanAjar $material) => [
                        'id' => $material->id,
                        'judul' => $material->judul,
                        'created_at' => $material->created_at?->toISOString(),
                        'mata_pelajaran' => $material->jadwalPelajaran?->mapel?->nama,
                    ])
                    ->values(),
                'assignments' => (clone $assignmentQuery)
                    ->with([
                        'jadwalPelajaran.mapel',
                        'submissionTugas' => fn ($query) => $query->where(
                            'siswa_id', $siswa?->id ?? 0
                        ),
                    ])
                    ->latest('deadline')
                    ->limit(5)
                    ->get()
                    ->map(fn (Assignment $assignment) => [
                        'id' => $assignment->id,
                        'judul' => $assignment->judul,
                        'deadline' => $assignment->deadline?->toISOString(),
                        'mata_pelajaran' => $assignment->jadwalPelajaran?->mapel?->nama,
                        'submission_status' => $assignment->submissionTugas->first()?->status,
                    ])
                    ->values(),
            ],
        ])->header('Cache-Control', 'private, no-store');
    }

    private function activeAcademicYear(): ?TahunAkademik
    {
        return TahunAkademik::query()
            ->where('is_active', true)
            ->latest('id')
            ->first();
    }

    private function academicYearData(
        ?TahunAkademik $academicYear
    ): ?array {
        if (! $academicYear) {
            return null;
        }

        return [
            'id' => $academicYear->id,
            'tahun_mulai' => $academicYear->tahun_mulai,
            'tahun_selesai' => $academicYear->tahun_selesai,
            'semester' => $academicYear->semester,
            'is_active' => $academicYear->is_active,
        ];
    }

    private function subjectCount(): int
    {
        return (int) \App\Models\Mapel::query()->count();
    }

    private function studentsByClass(
        ?TahunAkademik $academicYear
    ): array {
        return Kelas::query()
            ->when(
                $academicYear,
                fn ($query) => $query->where(
                    'tahun_akademik_id',
                    $academicYear->id
                )
            )
            ->withCount([
                'anggotaKelas as active_students_count' => fn ($query) => $query
                    ->whereDate('tanggal_mulai','<=',today('Asia/Jakarta')->toDateString())->where(fn($q)=>$q->whereNull('tanggal_selesai')->orWhereDate('tanggal_selesai','>=',today('Asia/Jakarta')->toDateString())),
            ])
            ->orderBy('tingkat')
            ->orderBy('nama')
            ->get()
            ->map(fn (Kelas $class) => [
                'class_id' => $class->id,
                'class_name' => $class->nama,
                'tingkat' => $class->tingkat,
                'student_count' => $class->active_students_count,
            ])
            ->values()
            ->all();
    }

    private function todayName(): string
    {
        return match (Carbon::now()->dayOfWeekIso) {
            1 => 'Senin',
            2 => 'Selasa',
            3 => 'Rabu',
            4 => 'Kamis',
            5 => 'Jumat',
            6 => 'Sabtu',
            7 => 'Minggu',
        };
    }
}
