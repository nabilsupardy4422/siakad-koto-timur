<?php

namespace App\Services\Reporting;

use App\Models\AnggotaKelas;
use App\Models\Guru;
use App\Models\JadwalPelajaran;
use App\Models\Kelas;
use App\Models\Nilai;
use App\Models\Presensi;
use App\Models\Siswa;
use App\Models\WaliKelas;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ReportService
{
    public function build(
        string $reportType,
        User $user,
        array $filters
    ): array {
        return match ($reportType) {
            'students' => $this->students($user, $filters),
            'teachers' => $this->teachers($user, $filters),
            'classes' => $this->classes($user, $filters),
            'schedules' => $this->schedules($user, $filters),
            'attendance' => $this->attendance($user, $filters),
            'grades' => $this->grades($user, $filters),
            'semester-recap' => $this->semesterRecap($user, $filters),
            default => throw new \InvalidArgumentException(
                'Jenis laporan tidak didukung.'
            ),
        };
    }

    private function students(User $user, array $filters): array
    {
        $this->authorizeReportFilters($user, 'students', $filters);

        $academicYearId = $filters['academic_year_id'] ?? null;

        $query = Siswa::query()
            ->with([
                'anggotaKelas.kelas.tahunAkademik',
            ])
            ->when(
                isset($filters['class_id']),
                function (Builder $query) use ($filters, $academicYearId): void {
                    $query->whereHas(
                        'anggotaKelas.kelas',
                        function (Builder $query) use ($filters, $academicYearId): void {
                            $query->where('id', $filters['class_id'])
                                ->when(
                                    $academicYearId !== null,
                                    fn (Builder $query) => $query->where(
                                        'tahun_akademik_id',
                                        $academicYearId
                                    )
                                );
                        }
                    );
                }
            )
            ->when(
                $academicYearId !== null,
                fn (Builder $query) => $query->whereHas(
                    'anggotaKelas.kelas',
                    fn (Builder $query) => $query->where(
                        'tahun_akademik_id',
                        $academicYearId
                    )
                )
            )
            ->when(
                isset($filters['search']),
                function (Builder $query) use ($filters): void {
                    $search = $filters['search'];

                    $query->where(function (Builder $query) use ($search): void {
                        $query
                            ->where('nis', 'like', "%{$search}%")
                            ->orWhere('nisn', 'like', "%{$search}%")
                            ->orWhere(
                                'nama_lengkap',
                                'like',
                                "%{$search}%"
                            );
                    });
                }
            );

        $this->applyStudentScope(
            $query,
            $user,
            $academicYearId
        );

        $rows = $query
            ->latest('id')
            ->get()
            ->map(function (Siswa $student) use ($academicYearId): array {
                $membership = $student->anggotaKelas
                    ->filter(
                        fn ($member) => $academicYearId === null
                            || $member->kelas?->tahun_akademik_id === $academicYearId
                    )
                    ->sortByDesc(
                        fn ($member) => $member->kelas?->tahun_akademik_id
                    )
                    ->first();

                return [
                    'id' => $student->id,
                    'nis' => $student->nis,
                    'nisn' => $student->nisn,
                    'nama_siswa' => $student->nama_lengkap,
                    'kelas' => $membership?->kelas?->nama,
                    'tingkat' => $membership?->kelas?->tingkat,
                    'tahun_akademik' => $membership?->kelas?->tahunAkademik
                        ? $membership->kelas->tahunAkademik->tahun_mulai
                            . '/'
                            . $membership->kelas->tahunAkademik->tahun_selesai
                        : null,
                ];
            });

        return $this->result(
            'Laporan Data Siswa',
            [
                'ID',
                'NIS',
                'NISN',
                'Nama Siswa',
                'Kelas',
                'Tingkat',
                'Tahun Akademik',
            ],
            $rows
        );
    }

    private function teachers(User $user, array $filters): array
    {
        $query = Guru::query()
            ->with('user')
            ->when(
                isset($filters['search']),
                function (Builder $query) use ($filters): void {
                    $search = $filters['search'];

                    $query->where(function (Builder $query) use ($search): void {
                        $query
                            ->where('nip', 'like', "%{$search}%")
                            ->orWhere(
                                'nama_lengkap',
                                'like',
                                "%{$search}%"
                            )
                            ->orWhereHas(
                                'user',
                                function (Builder $query) use ($search): void {
                                    $query
                                        ->where(
                                            'username',
                                            'like',
                                            "%{$search}%"
                                        )
                                        ->orWhere(
                                            'name',
                                            'like',
                                            "%{$search}%"
                                        )
                                        ->orWhere(
                                            'email',
                                            'like',
                                            "%{$search}%"
                                        );
                                }
                            );
                    });
                }
            )
            ->when(
                isset($filters['status']),
                fn (Builder $query) => $query->whereHas(
                    'user',
                    fn (Builder $query) => $query->where(
                        'account_status',
                        $filters['status']
                    )
                )
            );

        $rows = $query
            ->latest('id')
            ->get()
            ->map(fn (Guru $teacher): array => [
                'id' => $teacher->id,
                'nip' => $teacher->nip,
                'nama_guru' => $teacher->nama_lengkap,
                'username' => $teacher->user?->username,
                'email' => $teacher->user?->email,
                'status' => $teacher->user?->account_status,
            ]);

        return $this->result(
            'Laporan Data Guru',
            [
                'ID',
                'NIP',
                'Nama Guru',
                'Username',
                'Email',
                'Status',
            ],
            $rows
        );
    }

    private function classes(User $user, array $filters): array
    {
        $query = Kelas::query()
            ->with('tahunAkademik')
            ->when(
                isset($filters['academic_year_id']),
                fn (Builder $query) => $query->where(
                    'tahun_akademik_id',
                    $filters['academic_year_id']
                )
            );

        $this->applyClassScope(
            $query,
            $user,
            $filters['academic_year_id'] ?? null
        );

        $rows = $query
            ->orderBy('tingkat')
            ->orderBy('nama')
            ->get()
            ->map(fn (Kelas $class): array => [
                'id' => $class->id,
                'nama_kelas' => $class->nama,
                'tingkat' => $class->tingkat,
                'tahun_akademik' => $class->tahunAkademik
                    ? $class->tahunAkademik->tahun_mulai
                        . '/'
                        . $class->tahunAkademik->tahun_selesai
                    : null,
                'semester' => $class->tahunAkademik?->semester,
            ]);

        return $this->result(
            'Laporan Data Kelas',
            [
                'ID',
                'Nama Kelas',
                'Tingkat',
                'Tahun Akademik',
                'Semester',
            ],
            $rows
        );
    }

    private function schedules(User $user, array $filters): array
    {
        $this->authorizeReportFilters($user, 'schedules', $filters);

        $query = JadwalPelajaran::query()
            ->with([
                'tahunAkademik',
                'kelas',
                'mapel',
                'guru',
            ])
            ->when(
                isset($filters['academic_year_id']),
                fn (Builder $query) => $query->where(
                    'tahun_akademik_id',
                    $filters['academic_year_id']
                )
            )
            ->when(
                isset($filters['semester']),
                fn (Builder $query) => $query->whereHas(
                    'tahunAkademik',
                    fn (Builder $query) => $query->where(
                        'semester',
                        $filters['semester']
                    )
                )
            )
            ->when(
                isset($filters['class_id']),
                fn (Builder $query) => $query->where(
                    'kelas_id',
                    $filters['class_id']
                )
            )
            ->when(
                isset($filters['subject_id']),
                fn (Builder $query) => $query->where(
                    'mapel_id',
                    $filters['subject_id']
                )
            )
            ->when(
                isset($filters['teacher_id']),
                fn (Builder $query) => $query->where(
                    'guru_id',
                    $filters['teacher_id']
                )
            );

        $this->applyScheduleScope(
            $query,
            $user,
            $filters['academic_year_id'] ?? null
        );

        $rows = $query
            ->orderBy('hari')
            ->orderBy('jam_mulai')
            ->get()
            ->map(fn (JadwalPelajaran $schedule): array => [
                'id' => $schedule->id,
                'hari' => $schedule->hari,
                'jam_mulai' => $schedule->jam_mulai,
                'jam_selesai' => $schedule->jam_selesai,
                'kelas' => $schedule->kelas?->nama,
                'mata_pelajaran' => $schedule->mapel?->nama,
                'guru' => $schedule->guru?->nama_lengkap,
                'tahun_akademik' => $schedule->tahunAkademik
                    ? $schedule->tahunAkademik->tahun_mulai
                        . '/'
                        . $schedule->tahunAkademik->tahun_selesai
                    : null,
                'semester' => $schedule->tahunAkademik?->semester,
            ]);

        return $this->result(
            'Laporan Jadwal Pelajaran',
            [
                'ID',
                'Hari',
                'Jam Mulai',
                'Jam Selesai',
                'Kelas',
                'Mata Pelajaran',
                'Guru',
                'Tahun Akademik',
                'Semester',
            ],
            $rows
        );
    }

    private function attendance(User $user, array $filters): array
    {
        $this->authorizeReportFilters($user, 'attendance', $filters);

        $query = Presensi::query()
            ->with([
                'siswa',
                'jadwalPelajaran.tahunAkademik',
                'jadwalPelajaran.kelas',
                'jadwalPelajaran.mapel',
                'jadwalPelajaran.guru',
            ])
            ->when(
                isset($filters['academic_year_id']),
                fn (Builder $query) => $query->whereHas(
                    'jadwalPelajaran',
                    fn (Builder $query) => $query->where(
                        'tahun_akademik_id',
                        $filters['academic_year_id']
                    )
                )
            )
            ->when(
                isset($filters['semester']),
                fn (Builder $query) => $query->whereHas(
                    'jadwalPelajaran.tahunAkademik',
                    fn (Builder $query) => $query->where(
                        'semester',
                        $filters['semester']
                    )
                )
            )
            ->when(
                isset($filters['class_id']),
                fn (Builder $query) => $query->whereHas(
                    'jadwalPelajaran',
                    fn (Builder $query) => $query->where(
                        'kelas_id',
                        $filters['class_id']
                    )
                )
            )
            ->when(
                isset($filters['student_id']),
                fn (Builder $query) => $query->where(
                    'siswa_id',
                    $filters['student_id']
                )
            )
            ->when(
                isset($filters['date_from']),
                fn (Builder $query) => $query->whereDate(
                    'tanggal',
                    '>=',
                    $filters['date_from']
                )
            )
            ->when(
                isset($filters['date_to']),
                fn (Builder $query) => $query->whereDate(
                    'tanggal',
                    '<=',
                    $filters['date_to']
                )
            );

        $this->applyAttendanceScope(
            $query,
            $user,
            $filters['academic_year_id'] ?? null
        );

        $rows = $query
            ->orderByDesc('tanggal')
            ->get()
            ->map(fn (Presensi $attendance): array => [
                'id' => $attendance->id,
                'tanggal' => $attendance->tanggal?->format('Y-m-d'),
                'siswa' => $attendance->siswa?->nama_lengkap,
                'kelas' => $attendance->jadwalPelajaran?->kelas?->nama,
                'mata_pelajaran' => $attendance->jadwalPelajaran?->mapel?->nama,
                'guru' => $attendance->jadwalPelajaran?->guru?->nama_lengkap,
                'status' => $attendance->status,
                'catatan' => $attendance->catatan,
            ]);

        return $this->result(
            'Laporan Presensi',
            [
                'ID',
                'Tanggal',
                'Siswa',
                'Kelas',
                'Mata Pelajaran',
                'Guru',
                'Status',
                'Catatan',
            ],
            $rows
        );
    }

    private function grades(User $user, array $filters): array
    {
        $this->authorizeReportFilters($user, 'grades', $filters);

        $query = Nilai::query()
            ->with([
                'siswa',
                'komponenNilai.jadwalPelajaran.tahunAkademik',
                'komponenNilai.jadwalPelajaran.kelas',
                'komponenNilai.jadwalPelajaran.mapel',
                'komponenNilai.jadwalPelajaran.guru',
            ])
            ->when(
                isset($filters['academic_year_id']),
                fn (Builder $query) => $query->whereHas(
                    'komponenNilai.jadwalPelajaran',
                    fn (Builder $query) => $query->where(
                        'tahun_akademik_id',
                        $filters['academic_year_id']
                    )
                )
            )
            ->when(
                isset($filters['semester']),
                fn (Builder $query) => $query->whereHas(
                    'komponenNilai.jadwalPelajaran.tahunAkademik',
                    fn (Builder $query) => $query->where(
                        'semester',
                        $filters['semester']
                    )
                )
            )
            ->when(
                isset($filters['class_id']),
                fn (Builder $query) => $query->whereHas(
                    'komponenNilai.jadwalPelajaran',
                    fn (Builder $query) => $query->where(
                        'kelas_id',
                        $filters['class_id']
                    )
                )
            )
            ->when(
                isset($filters['subject_id']),
                fn (Builder $query) => $query->whereHas(
                    'komponenNilai.jadwalPelajaran',
                    fn (Builder $query) => $query->where(
                        'mapel_id',
                        $filters['subject_id']
                    )
                )
            )
            ->when(
                isset($filters['student_id']),
                fn (Builder $query) => $query->where(
                    'siswa_id',
                    $filters['student_id']
                )
            );

        $this->applyGradeScope(
            $query,
            $user,
            $filters['academic_year_id'] ?? null
        );

        $rows = $query
            ->orderBy('siswa_id')
            ->get()
            ->map(fn (Nilai $grade): array => [
                'id' => $grade->id,
                'siswa' => $grade->siswa?->nama_lengkap,
                'kelas' => $grade->komponenNilai
                    ?->jadwalPelajaran?->kelas?->nama,
                'mata_pelajaran' => $grade->komponenNilai
                    ?->jadwalPelajaran?->mapel?->nama,
                'guru' => $grade->komponenNilai
                    ?->jadwalPelajaran?->guru?->nama_lengkap,
                'nilai' => $grade->nilai,
                'catatan' => $grade->catatan,
            ]);

        return $this->result(
            'Laporan Nilai',
            [
                'ID',
                'Siswa',
                'Kelas',
                'Mata Pelajaran',
                'Guru',
                'Nilai',
                'Catatan',
            ],
            $rows
        );
    }

    private function semesterRecap(User $user, array $filters): array
    {
        $this->authorizeReportFilters($user, 'semester-recap', $filters);

        $academicYearId = $filters['academic_year_id'] ?? null;

        $studentQuery = Siswa::query()
            ->with([
                'anggotaKelas.kelas.tahunAkademik',
            ])
            ->when(
                isset($filters['class_id']),
                function (Builder $query) use ($filters, $academicYearId): void {
                    $query->whereHas(
                        'anggotaKelas.kelas',
                        function (Builder $query) use ($filters, $academicYearId): void {
                            $query->where('id', $filters['class_id'])
                                ->when(
                                    $academicYearId !== null,
                                    fn (Builder $query) => $query->where(
                                        'tahun_akademik_id',
                                        $academicYearId
                                    )
                                );
                        }
                    );
                }
            )
            ->when(
                isset($filters['student_id']),
                fn (Builder $query) => $query->whereKey(
                    $filters['student_id']
                )
            )
            ->when(
                isset($filters['academic_year_id']),
                fn (Builder $query) => $query->whereHas(
                    'anggotaKelas.kelas',
                    fn (Builder $query) => $query->where(
                        'tahun_akademik_id',
                        $filters['academic_year_id']
                    )
                )
            );

        $this->applyStudentScope(
            $studentQuery,
            $user,
            $filters['academic_year_id'] ?? null
        );

        $students = $studentQuery->get();

        $rows = $students->map(function (Siswa $student) use ($filters, $user, $academicYearId): array {
            $attendance = Presensi::query()
                ->where('siswa_id', $student->id)
                ->when(
                    isset($filters['academic_year_id']),
                    fn (Builder $query) => $query->whereHas(
                        'jadwalPelajaran',
                        fn (Builder $query) => $query->where(
                            'tahun_akademik_id',
                            $filters['academic_year_id']
                        )
                    )
                )
                ->when(
                    isset($filters['semester']),
                    fn (Builder $query) => $query->whereHas(
                        'jadwalPelajaran.tahunAkademik',
                        fn (Builder $query) => $query->where(
                            'semester',
                            $filters['semester']
                        )
                    )
                )
                ->when(
                    isset($filters['class_id']),
                    fn (Builder $query) => $query->whereHas(
                        'jadwalPelajaran',
                        fn (Builder $query) => $query->where(
                            'kelas_id',
                            $filters['class_id']
                        )
                    )
                );

            $this->applyAttendanceScope(
                $attendance,
                $user,
                $academicYearId
            );

            $attendance = $attendance
                ->get()
                ->groupBy('status');

            $grades = Nilai::query()
                ->where('siswa_id', $student->id)
                ->when(
                    isset($filters['academic_year_id']),
                    fn (Builder $query) => $query->whereHas(
                        'komponenNilai.jadwalPelajaran',
                        fn (Builder $query) => $query->where(
                            'tahun_akademik_id',
                            $filters['academic_year_id']
                        )
                    )
                )
                ->when(
                    isset($filters['semester']),
                    fn (Builder $query) => $query->whereHas(
                        'komponenNilai.jadwalPelajaran.tahunAkademik',
                        fn (Builder $query) => $query->where(
                            'semester',
                            $filters['semester']
                        )
                    )
                )
                ->when(
                    isset($filters['class_id']),
                    fn (Builder $query) => $query->whereHas(
                        'komponenNilai.jadwalPelajaran',
                        fn (Builder $query) => $query->where(
                            'kelas_id',
                            $filters['class_id']
                        )
                    )
                );

            $this->applyGradeScope(
                $grades,
                $user,
                $academicYearId
            );

            $grades = $grades->avg('nilai');

            $membership = $student->anggotaKelas
                ->filter(
                    fn ($member) => $academicYearId === null
                        || $member->kelas?->tahun_akademik_id === $academicYearId
                )
                ->sortByDesc(
                    fn ($member) => $member->kelas?->tahun_akademik_id
                )
                ->first();

            return [
                'student_id' => $student->id,
                'nis' => $student->nis,
                'nama_siswa' => $student->nama_lengkap,
                'kelas' => $membership?->kelas?->nama,
                'hadir' => $attendance->get('hadir', collect())->count(),
                'izin' => $attendance->get('izin', collect())->count(),
                'sakit' => $attendance->get('sakit', collect())->count(),
                'alpa' => $attendance->get('alpa', collect())->count(),
                'rata_rata_nilai' => $grades !== null
                    ? round((float) $grades, 2)
                    : null,
            ];
        });

        return $this->result(
            'Rekap Akademik Semester',
            [
                'ID Siswa',
                'NIS',
                'Nama Siswa',
                'Kelas',
                'Hadir',
                'Izin',
                'Sakit',
                'Alpa',
                'Rata-rata Nilai',
            ],
            $rows
        );
    }

    private function applyStudentScope(
        Builder $query,
        User $user,
        ?int $academicYearId
    ): void {
        $role = $user->role?->code;

        if (in_array($role, ['TU', 'KEPALA_SEKOLAH'], true)) {
            return;
        }

        if ($role === 'SISWA') {
            $studentId = $user->siswa?->id;

            if ($studentId === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->whereKey($studentId);

            return;
        }

        if ($role !== 'GURU') {
            $query->whereRaw('1 = 0');

            return;
        }

        $classIds = $this->teacherClassIds(
            $user,
            $academicYearId
        );

        if ($classIds->isEmpty()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereHas(
            'anggotaKelas',
            fn (Builder $query) => $query->whereIn(
                'kelas_id',
                $classIds
            )
        );
    }

    private function applyClassScope(
        Builder $query,
        User $user,
        ?int $academicYearId = null
    ): void {
        $role = $user->role?->code;

        if (in_array($role, ['TU', 'KEPALA_SEKOLAH'], true)) {
            return;
        }

        if ($role === 'SISWA') {
            $classIds = $this->studentClassIds($user, $academicYearId);

            if ($classIds->isEmpty()) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->whereIn('id', $classIds);

            return;
        }

        if ($role === 'GURU') {
            $classIds = $this->teacherClassIds($user, $academicYearId);

            if ($classIds->isEmpty()) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->whereIn('id', $classIds);

            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function applyScheduleScope(
        Builder $query,
        User $user,
        ?int $academicYearId = null
    ): void {
        $role = $user->role?->code;

        if (in_array($role, ['TU', 'KEPALA_SEKOLAH'], true)) {
            return;
        }

        if ($role === 'GURU') {
            $guruId = $user->guru?->id;

            if ($guruId === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $waliClassIds = WaliKelas::query()
                ->where('guru_id', $guruId)
                ->when(
                    $academicYearId !== null,
                    fn (Builder $query) => $query->where(
                        'tahun_akademik_id',
                        $academicYearId
                    )
                )
                ->pluck('kelas_id');

            $query->where(function (Builder $query) use (
                $guruId,
                $waliClassIds
            ): void {
                $query->where('guru_id', $guruId)
                    ->orWhereIn('kelas_id', $waliClassIds);
            });

            return;
        }

        if ($role === 'SISWA') {
            $classIds = $this->studentClassIds($user, $academicYearId);

            if ($classIds->isEmpty()) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->whereIn('kelas_id', $classIds);

            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function applyAttendanceScope(
        Builder $query,
        User $user,
        ?int $academicYearId = null
    ): void {
        $role = $user->role?->code;

        if (in_array($role, ['TU', 'KEPALA_SEKOLAH'], true)) {
            return;
        }

        if ($role === 'SISWA') {
            $studentId = $user->siswa?->id;

            if ($studentId === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->where('siswa_id', $studentId);

            return;
        }

        if ($role === 'GURU') {
            $guruId = $user->guru?->id;

            if ($guruId === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $waliClassIds = WaliKelas::query()
                ->where('guru_id', $guruId)
                ->when(
                    $academicYearId !== null,
                    fn (Builder $query) => $query->where(
                        'tahun_akademik_id',
                        $academicYearId
                    )
                )
                ->pluck('kelas_id');

            $query->whereHas(
                'jadwalPelajaran',
                function (Builder $query) use (
                    $guruId,
                    $waliClassIds
                ): void {
                    $query->where('guru_id', $guruId)
                        ->orWhereIn('kelas_id', $waliClassIds);
                }
            );

            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function applyGradeScope(
        Builder $query,
        User $user,
        ?int $academicYearId = null
    ): void {
        $role = $user->role?->code;

        if (in_array($role, ['TU', 'KEPALA_SEKOLAH'], true)) {
            return;
        }

        if ($role === 'SISWA') {
            $studentId = $user->siswa?->id;

            if ($studentId === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->where('siswa_id', $studentId);

            return;
        }

        if ($role === 'GURU') {
            $guruId = $user->guru?->id;

            if ($guruId === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $waliClassIds = WaliKelas::query()
                ->where('guru_id', $guruId)
                ->when(
                    $academicYearId !== null,
                    fn (Builder $query) => $query->where(
                        'tahun_akademik_id',
                        $academicYearId
                    )
                )
                ->pluck('kelas_id');

            $query->whereHas(
                'komponenNilai.jadwalPelajaran',
                function (Builder $query) use (
                    $guruId,
                    $waliClassIds
                ): void {
                    $query->where('guru_id', $guruId)
                        ->orWhereIn('kelas_id', $waliClassIds);
                }
            );

            return;
        }

        $query->whereRaw('1 = 0');
    }


    private function authorizeReportFilters(
        User $user,
        string $reportType,
        array $filters
    ): void {
        $role = $user->role?->code;

        if (in_array($role, ['TU', 'KEPALA_SEKOLAH'], true)) {
            return;
        }

        if (! in_array($role, ['GURU', 'SISWA'], true)) {
            throw new AuthorizationException(
                'Anda tidak memiliki akses untuk mengekspor laporan.'
            );
        }

        $academicYearId = $filters['academic_year_id'] ?? null;

        if ($role === 'SISWA') {
            $studentId = $user->siswa?->id;

            if ($studentId === null) {
                throw new AuthorizationException(
                    'Akun siswa tidak terhubung dengan data siswa.'
                );
            }

            if (
                isset($filters['student_id'])
                && (int) $filters['student_id'] !== (int) $studentId
            ) {
                throw new AuthorizationException(
                    'Siswa hanya dapat mengakses laporan miliknya sendiri.'
                );
            }

            if (isset($filters['class_id'])) {
                $classIds = $this->studentClassIds(
                    $user,
                    $academicYearId
                );

                if (! $classIds->contains((int) $filters['class_id'])) {
                    throw new AuthorizationException(
                        'Siswa tidak memiliki akses ke kelas tersebut.'
                    );
                }
            }

            return;
        }

        if (isset($filters['class_id'])) {
            $this->authorizeTeacherClass(
                $user,
                (int) $filters['class_id'],
                $academicYearId
            );
        }

        if (isset($filters['student_id'])) {
            $this->authorizeTeacherStudent(
                $user,
                (int) $filters['student_id'],
                $academicYearId
            );
        }

        if ($reportType === 'schedules') {
            $this->authorizeTeacherScheduleFilters($user, $filters);
        }

        if ($reportType === 'grades' && isset($filters['subject_id'])) {
            $this->authorizeTeacherSubject(
                $user,
                (int) $filters['subject_id'],
                $academicYearId,
                $filters['class_id'] ?? null,
                $filters['teacher_id'] ?? null,
                $filters['semester'] ?? null
            );
        }
    }

    private function authorizeTeacherClass(
        User $user,
        int $classId,
        ?int $academicYearId
    ): void {
        $class = Kelas::query()->find($classId);

        if ($class === null) {
            return;
        }

        $resolvedAcademicYearId = $academicYearId
            ?? $class->tahun_akademik_id;

        $allowedClassIds = $this->teacherClassIds(
            $user,
            $resolvedAcademicYearId
        );

        if (! $allowedClassIds->contains($classId)) {
            throw new AuthorizationException(
                'Guru tidak memiliki akses ke kelas tersebut.'
            );
        }
    }

    private function authorizeTeacherStudent(
        User $user,
        int $studentId,
        ?int $academicYearId
    ): void {
        $student = Siswa::query()->find($studentId);

        if ($student === null) {
            return;
        }

        $classIds = $this->teacherClassIds(
            $user,
            $academicYearId
        );

        if ($classIds->isEmpty()) {
            throw new AuthorizationException(
                'Guru tidak memiliki teaching context yang sah.'
            );
        }

        $belongsToAuthorizedClass = AnggotaKelas::query()
            ->where('siswa_id', $studentId)
            ->whereIn('kelas_id', $classIds)
            ->exists();

        if (! $belongsToAuthorizedClass) {
            throw new AuthorizationException(
                'Guru tidak memiliki akses ke data siswa tersebut.'
            );
        }
    }

    private function authorizeTeacherScheduleFilters(
        User $user,
        array $filters
    ): void {
        $guruId = $user->guru?->id;

        if ($guruId === null) {
            throw new AuthorizationException(
                'Akun Guru tidak terhubung dengan data Guru.'
            );
        }

        $academicYearId = $filters['academic_year_id'] ?? null;
        $classId = $filters['class_id'] ?? null;
        $subjectId = $filters['subject_id'] ?? null;
        $teacherId = $filters['teacher_id'] ?? null;
        $semester = $filters['semester'] ?? null;

        $query = JadwalPelajaran::query()
            ->when(
                $academicYearId !== null,
                fn (Builder $query) => $query->where(
                    'tahun_akademik_id',
                    $academicYearId
                )
            )
            ->when(
                $classId !== null,
                fn (Builder $query) => $query->where(
                    'kelas_id',
                    $classId
                )
            )
            ->when(
                $subjectId !== null,
                fn (Builder $query) => $query->where(
                    'mapel_id',
                    $subjectId
                )
            )
            ->when(
                $teacherId !== null,
                fn (Builder $query) => $query->where(
                    'guru_id',
                    $teacherId
                )
            )
            ->when(
                $semester !== null,
                fn (Builder $query) => $query->whereHas(
                    'tahunAkademik',
                    fn (Builder $query) => $query->where(
                        'semester',
                        $semester
                    )
                )
            );

        $waliClassIds = WaliKelas::query()
            ->where('guru_id', $guruId)
            ->when(
                $academicYearId !== null,
                fn (Builder $query) => $query->where(
                    'tahun_akademik_id',
                    $academicYearId
                )
            )
            ->pluck('kelas_id');

        $query->where(function (Builder $query) use (
            $guruId,
            $waliClassIds
        ): void {
            $query->where('guru_id', $guruId)
                ->orWhereIn('kelas_id', $waliClassIds);
        });

        if (! $query->exists()) {
            throw new AuthorizationException(
                'Guru tidak memiliki akses ke scope jadwal tersebut.'
            );
        }
    }

    private function authorizeTeacherSubject(
        User $user,
        int $subjectId,
        ?int $academicYearId,
        ?int $classId,
        ?int $teacherId,
        ?string $semester
    ): void {
        $guruId = $user->guru?->id;

        if ($guruId === null) {
            throw new AuthorizationException(
                'Akun Guru tidak terhubung dengan data Guru.'
            );
        }

        $query = JadwalPelajaran::query()
            ->where('mapel_id', $subjectId)
            ->when(
                $academicYearId !== null,
                fn (Builder $query) => $query->where(
                    'tahun_akademik_id',
                    $academicYearId
                )
            )
            ->when(
                $classId !== null,
                fn (Builder $query) => $query->where(
                    'kelas_id',
                    $classId
                )
            )
            ->when(
                $teacherId !== null,
                fn (Builder $query) => $query->where(
                    'guru_id',
                    $teacherId
                )
            )
            ->when(
                $semester !== null,
                fn (Builder $query) => $query->whereHas(
                    'tahunAkademik',
                    fn (Builder $query) => $query->where(
                        'semester',
                        $semester
                    )
                )
            );

        $ownedScheduleExists = (clone $query)
            ->where('guru_id', $guruId)
            ->exists();

        if ($ownedScheduleExists) {
            return;
        }

        $waliClassIds = WaliKelas::query()
            ->where('guru_id', $guruId)
            ->when(
                $academicYearId !== null,
                fn (Builder $query) => $query->where(
                    'tahun_akademik_id',
                    $academicYearId
                )
            )
            ->pluck('kelas_id');

        if (
            $waliClassIds->isEmpty()
            || ! (clone $query)
                ->whereIn('kelas_id', $waliClassIds)
                ->exists()
        ) {
            throw new AuthorizationException(
                'Guru tidak memiliki akses ke mata pelajaran tersebut.'
            );
        }
    }

    private function teacherClassIds(
        User $user,
        ?int $academicYearId = null
    ): Collection {
        $guruId = $user->guru?->id;

        if ($guruId === null) {
            return collect();
        }

        $teaching = JadwalPelajaran::query()
            ->where('guru_id', $guruId)
            ->when(
                $academicYearId !== null,
                fn (Builder $query) => $query->where(
                    'tahun_akademik_id',
                    $academicYearId
                )
            )
            ->pluck('kelas_id');

        $homeroom = WaliKelas::query()
            ->where('guru_id', $guruId)
            ->when(
                $academicYearId !== null,
                fn (Builder $query) => $query->where(
                    'tahun_akademik_id',
                    $academicYearId
                )
            )
            ->pluck('kelas_id');

        return $teaching
            ->merge($homeroom)
            ->unique()
            ->values();
    }

    private function studentClassIds(
        User $user,
        ?int $academicYearId = null
    ): Collection {
        $studentId = $user->siswa?->id;

        if ($studentId === null) {
            return collect();
        }

        return AnggotaKelas::query()
            ->where('siswa_id', $studentId)
            ->when(
                $academicYearId !== null,
                fn (Builder $query) => $query->whereHas(
                    'kelas',
                    fn (Builder $query) => $query->where(
                        'tahun_akademik_id',
                        $academicYearId
                    )
                )
            )
            ->pluck('kelas_id')
            ->unique()
            ->values();
    }

    private function result(
        string $title,
        array $headings,
        Collection $rows
    ): array {
        return [
            'title' => $title,
            'headings' => $headings,
            'rows' => $rows,
        ];
    }
}