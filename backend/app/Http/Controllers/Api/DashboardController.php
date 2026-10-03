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
use App\Models\WaliKelas;
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
        $academicYear = $this->activeAcademicYear();

        if ($guruId === null) {
            return ApiResponse::success([
                'role' => 'GURU',
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
                fn ($query) => $query->where(
                    'tahun_akademik_id',
                    $academicYear->id
                )
            );

        $today = $this->todayName();

        $todaySchedule = (clone $scheduleQuery)
            ->where('hari', $today)
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

        $attendanceQuery = Presensi::query()
            ->whereHas(
                'jadwalPelajaran',
                function ($query) use ($guruId, $academicYear): void {
                    $query->where('guru_id', $guruId);

                    if ($academicYear) {
                        $query->where(
                            'tahun_akademik_id',
                            $academicYear->id
                        );
                    }
                }
            );

        $waliKelas = WaliKelas::query()
            ->where('guru_id', $guruId)
            ->whereNull('tanggal_selesai')
            ->when(
                $academicYear,
                fn ($query) => $query->where(
                    'tahun_akademik_id',
                    $academicYear->id
                )
            )
            ->with([
                'kelas',
                'tahunAkademik',
            ])
            ->latest('id')
            ->first();

        $waliKelasData = null;

        if ($waliKelas) {
            $studentQuery = AnggotaKelas::query()
                ->where('kelas_id', $waliKelas->kelas_id)
                ->whereNull('tanggal_selesai');

            $studentIds = (clone $studentQuery)->pluck('siswa_id');

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
                'total_students' => $studentQuery->count(),
                'attendance_records' => Presensi::query()
                    ->whereIn('siswa_id', $studentIds)
                    ->when(
                        $academicYear,
                        fn ($query) => $query->whereHas(
                            'jadwalPelajaran',
                            fn ($scheduleQuery) => $scheduleQuery->where(
                                'tahun_akademik_id',
                                $academicYear->id
                            )
                        )
                    )
                    ->count(),
                'graded_records' => Nilai::query()
                    ->whereIn('siswa_id', $studentIds)
                    ->whereHas(
                        'komponenNilai.jadwalPelajaran',
                        fn ($query) => $query->when(
                            $academicYear,
                            fn ($academicQuery) => $academicQuery->where(
                                'tahun_akademik_id',
                                $academicYear->id
                            )
                        )
                    )
                    ->count(),
            ];
        }

        return ApiResponse::success([
            'role' => 'GURU',
            'is_wali_kelas' => $waliKelas !== null,
            'summary' => [
                'today_schedule_count' => $todaySchedule->count(),
                'teaching_schedule_count' => $scheduleQuery->count(),
                'material_count' => $materialQuery->count(),
                'assignment_count' => $assignmentQuery->count(),
                'grade_component_count' => $gradeComponentQuery->count(),
                'attendance_record_count' => $attendanceQuery->count(),
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

    private function siswaDashboard(User $user): JsonResponse
    {
        $siswa = $user->siswa;
        $academicYear = $this->activeAcademicYear();

        if (! $siswa) {
            return ApiResponse::success([
                'role' => 'SISWA',
                'summary' => [
                    'attendance_count' => 0,
                    'grade_count' => 0,
                    'material_count' => 0,
                    'assignment_count' => 0,
                ],
                'class' => null,
                'today_schedule' => [],
                'recent' => [
                    'materials' => [],
                    'assignments' => [],
                ],
            ]);
        }

        $membership = AnggotaKelas::query()
            ->where('siswa_id', $siswa->id)
            ->whereNull('tanggal_selesai')
            ->whereHas(
                'kelas',
                fn ($query) => $query->when(
                    $academicYear,
                    fn ($academicQuery) => $academicQuery->where(
                        'tahun_akademik_id',
                        $academicYear->id
                    )
                )
            )
            ->with('kelas.tahunAkademik')
            ->latest('id')
            ->first();

        $classId = $membership?->kelas_id;

        $scheduleQuery = JadwalPelajaran::query()
            ->when(
                $classId,
                fn ($query) => $query->where('kelas_id', $classId)
            )
            ->when(
                $academicYear,
                fn ($query) => $query->where(
                    'tahun_akademik_id',
                    $academicYear->id
                )
            );

        $todaySchedule = (clone $scheduleQuery)
            ->where('hari', $this->todayName())
            ->with([
                'guru',
                'mapel',
                'kelas',
            ])
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

        $attendanceQuery = Presensi::query()
            ->where('siswa_id', $siswa->id)
            ->whereIn('jadwal_pelajaran_id', $scheduleIds);

        $gradeQuery = Nilai::query()
            ->where('siswa_id', $siswa->id)
            ->whereHas(
                'komponenNilai',
                fn ($query) => $query->whereIn(
                    'jadwal_pelajaran_id',
                    $scheduleIds
                )
            );

        $materialQuery = BahanAjar::query()
            ->whereIn('jadwal_pelajaran_id', $scheduleIds);

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
            'class' => $membership?->kelas ? [
                'id' => $membership->kelas->id,
                'nama' => $membership->kelas->nama,
                'tingkat' => $membership->kelas->tingkat,
                'academic_year' => $this->academicYearData(
                    $membership->kelas->tahunAkademik
                ),
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
                            'siswa_id',
                            $siswa->id
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
        ]);
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
                    ->whereNull('tanggal_selesai'),
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