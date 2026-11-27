<?php

namespace App\Services\Reporting;

use App\Models\AnggotaKelas;
use App\Models\Guru;
use App\Models\JadwalPelajaran;
use App\Models\Kelas;
use App\Models\Nilai;
use App\Models\Presensi;
use App\Models\Siswa;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ReportService
{
    private ?int $rowLimit = null;

    public function build(
        string $reportType,
        User $user,
        array $filters,
        ?int $rowLimit = null
    ): array {
        $this->rowLimit = $rowLimit;
        $filters = ReportAccess::normalize($user, $reportType, $filters);

        $report = match ($reportType) {
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

        // Internal export metadata: only filters already normalized by ReportAccess.
        return $report + ['effective_filters' => $filters];
    }

    private function students(User $user, array $filters): array
    {
        $query = Siswa::query()
            ->with([
                'anggotaKelas' => function ($relation) use ($user, $filters): void {
                    ReportAccess::membership($relation->getQuery(), $user, $filters, in_array($filters['scope'], ['teaching', 'homeroom'], true));
                    $relation->with('kelas.tahunAkademik');
                },
            ])
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

        if (in_array($filters['scope'], ['teaching', 'homeroom'], true) || isset($filters['class_id']) || isset($filters['academic_year_id']) || isset($filters['subject_id']) || isset($filters['schedule_id'])) {
            $query->whereHas('anggotaKelas', fn (Builder $q) => ReportAccess::membership($q, $user, $filters, in_array($filters['scope'], ['teaching', 'homeroom'], true)));
        }

        $this->applyStudentScope(
            $query,
            $user,
            $filters
        );

        $rows = $this->boundedGet($query->orderBy('nama_lengkap')->orderBy('id'))
            ->flatMap(function (Siswa $student): array {
                $memberships = $student->anggotaKelas->sortByDesc('tanggal_mulai')->unique('kelas_id');
                if ($memberships->isEmpty()) $memberships = collect([null]);

                return $memberships->map(fn ($membership) => [
                    'nis' => $student->nis,
                    'nisn' => $student->nisn,
                    'nama_siswa' => $student->nama_lengkap,
                    'kelas' => $membership?->kelas?->nama,
                    'tingkat' => $membership?->kelas?->tingkat,
                    ...$this->period($membership?->kelas?->tahunAkademik),
                    'tanggal_mulai' => $membership?->tanggal_mulai?->format('Y-m-d'),
                    'tanggal_selesai' => $membership?->tanggal_selesai?->format('Y-m-d'),
                    'status_keanggotaan' => $membership === null ? 'Belum ditempatkan' : (
                        $membership->tanggal_mulai->toDateString() > today('Asia/Jakarta')->toDateString() ? 'Terjadwal' : (
                            $membership->tanggal_selesai !== null && $membership->tanggal_selesai->toDateString() < today('Asia/Jakarta')->toDateString() ? 'Berakhir' : 'Aktif'
                        )
                    ),
                ])->values()->all();
            });

        return $this->result(
            match ($filters['scope']) {
                'teaching' => 'Daftar Siswa Aktif yang Diajar',
                'homeroom' => 'Daftar Anggota Aktif Kelas Binaan',
                'own' => 'Data Akademik Pribadi',
                default => 'Laporan Data Siswa',
            },
            ['NIS', 'NISN', 'Nama Siswa', 'Kelas', 'Tingkat', 'Tahun Akademik', 'Semester', 'Tanggal Mulai', 'Tanggal Selesai', 'Status Keanggotaan'],
            $rows
        );
    }

    private function teachers(User $user, array $filters): array
    {
        $query = Guru::query()
            ->when($user->role?->code === 'TU', fn (Builder $q) => $q->with('user'))
            ->when(
                isset($filters['search']),
                function (Builder $query) use ($filters, $user): void {
                    $search = $filters['search'];

                    $query->where(function (Builder $query) use ($search, $user): void {
                        $query
                            ->where('nip', 'like', "%{$search}%")
                            ->orWhere(
                                'nama_lengkap',
                                'like',
                                "%{$search}%"
                            )
                            ->when($user->role?->code === 'TU', fn (Builder $q) => $q->orWhereHas(
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
                            ));
                    });
                }
            )
            ->when(
                isset($filters['status']) && $user->role?->code === 'TU',
                fn (Builder $query) => $query->whereHas(
                    'user',
                    fn (Builder $query) => $query->where(
                        'account_status',
                        $filters['status']
                    )
                )
            );

        if ($user->role?->code === 'KEPALA_SEKOLAH') {
            return $this->result('Laporan Data Guru', ['NIP', 'Nama Guru'],
                $this->boundedGet($query->latest('id'), ['nip', 'nama_lengkap'])->map(fn (Guru $teacher) => [
                    'nip' => $teacher->nip, 'nama_guru' => $teacher->nama_lengkap,
                ]));
        }

        $rows = $this->boundedGet($query->latest('id'))
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

        $rows = $this->boundedGet($query->orderBy('tingkat')->orderBy('nama')->orderBy('id'))
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

        if (isset($filters['schedule_id'])) {
            $query->where('id', $filters['schedule_id']);
        }

        $this->applyScheduleScope(
            $query,
            $user,
            $filters
        );

        $rows = $this->boundedGet($query
            ->orderByRaw("CASE LOWER(hari) WHEN 'senin' THEN 1 WHEN 'selasa' THEN 2 WHEN 'rabu' THEN 3 WHEN 'kamis' THEN 4 WHEN 'jumat' THEN 5 WHEN 'sabtu' THEN 6 WHEN 'minggu' THEN 7 ELSE 8 END")
            ->orderBy('jam_mulai')->orderBy('id'))
            ->map(fn (JadwalPelajaran $schedule): array => [
                'hari' => $schedule->hari,
                'jam_mulai' => $schedule->jam_mulai,
                'jam_selesai' => $schedule->jam_selesai,
                'kelas' => $schedule->kelas?->nama,
                'tingkat' => $schedule->kelas?->tingkat,
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
                'Hari',
                'Jam Mulai',
                'Jam Selesai',
                'Kelas',
                'Tingkat',
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

        if (isset($filters['schedule_id'])) {
            $query->whereHas('jadwalPelajaran', fn (Builder $q) => $q->where('id', $filters['schedule_id']));
        }
        if (isset($filters['subject_id'])) {
            $query->whereHas('jadwalPelajaran', fn (Builder $q) => $q->where('mapel_id', $filters['subject_id']));
        }

        $this->applyAttendanceScope(
            $query,
            $user,
            $filters
        );

        $rows = $this->boundedGet($query->orderByDesc('tanggal')->orderBy('jadwal_pelajaran_id')->orderBy('siswa_id'))
            ->map(function (Presensi $attendance): array {
                $schedule = $attendance->jadwalPelajaran;
                return [
                    'tanggal' => $attendance->tanggal?->format('Y-m-d'),
                    'nis' => $attendance->siswa?->nis,
                    'nisn' => $attendance->siswa?->nisn,
                    'siswa' => $attendance->siswa?->nama_lengkap,
                    'kelas' => $schedule?->kelas?->nama,
                    'tingkat' => $schedule?->kelas?->tingkat,
                    'mata_pelajaran' => $schedule?->mapel?->nama,
                    'guru' => $schedule?->guru?->nama_lengkap,
                    'jam_mulai' => $schedule?->jam_mulai,
                    'jam_selesai' => $schedule?->jam_selesai,
                    'status' => $attendance->status,
                    'catatan' => $attendance->catatan,
                    ...$this->period($schedule?->tahunAkademik),
                ];
            });

        return $this->result('Laporan Presensi per Pertemuan', [
            'Tanggal', 'NIS', 'NISN', 'Siswa', 'Kelas', 'Tingkat', 'Mata Pelajaran', 'Guru',
            'Jam Mulai', 'Jam Selesai', 'Status', 'Catatan', 'Tahun Akademik', 'Semester',
        ], $rows);
    }

    private function grades(User $user, array $filters): array
    {
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

        if (isset($filters['schedule_id'])) {
            $query->whereHas('komponenNilai.jadwalPelajaran', fn (Builder $q) => $q->where('id', $filters['schedule_id']));
        }

        $this->applyGradeScope(
            $query,
            $user,
            $filters
        );

        $rows = $this->boundedGet($query->orderBy('siswa_id')->orderBy('komponen_nilai_id'))
            ->map(function (Nilai $grade): array {
                $component = $grade->komponenNilai;
                $schedule = $component?->jadwalPelajaran;
                return [
                    'nis' => $grade->siswa?->nis,
                    'nisn' => $grade->siswa?->nisn,
                    'siswa' => $grade->siswa?->nama_lengkap,
                    'kelas' => $schedule?->kelas?->nama,
                    'tingkat' => $schedule?->kelas?->tingkat,
                    'mata_pelajaran' => $schedule?->mapel?->nama,
                    'guru' => $schedule?->guru?->nama_lengkap,
                    'hari' => $schedule?->hari,
                    'jam_mulai' => $schedule?->jam_mulai,
                    'jam_selesai' => $schedule?->jam_selesai,
                    'komponen' => $component?->nama,
                    'bobot' => $component?->bobot === null ? null : (float) $component->bobot,
                    'nilai' => $grade->nilai === null ? null : (float) $grade->nilai,
                    'catatan' => $grade->catatan,
                    ...$this->period($schedule?->tahunAkademik),
                ];
            });

        return $this->result('Laporan Nilai Komponen', [
            'NIS', 'NISN', 'Siswa', 'Kelas', 'Tingkat', 'Mata Pelajaran', 'Guru', 'Hari Jadwal',
            'Jam Mulai', 'Jam Selesai', 'Komponen', 'Bobot', 'Nilai Tercatat', 'Catatan', 'Tahun Akademik', 'Semester',
        ], $rows);
    }

    private function semesterRecap(User $user, array $filters): array
    {
        // A row represents one student in one class (which belongs to exactly one period).
        // Started historical memberships remain reportable. Future memberships do not.
        $membershipQuery = AnggotaKelas::query()->with(['siswa', 'kelas.tahunAkademik'])
            ->whereDate('tanggal_mulai', '<=', today('Asia/Jakarta'))
            ->where(fn($q)=>$q->whereNull('tanggal_selesai')->orWhereRaw('DATE(tanggal_selesai) >= DATE(tanggal_mulai)'));
        ReportAccess::membership($membershipQuery, $user, $filters, false);
        if ($filters['scope'] === 'own') $membershipQuery->where('siswa_id', $user->siswa->id);
        if (isset($filters['student_id'])) $membershipQuery->where('siswa_id', $filters['student_id']);
        if (isset($filters['semester'])) {
            $membershipQuery->whereHas('kelas.tahunAkademik', fn (Builder $q) => $q->where('semester', $filters['semester']));
        }
        $memberships = $this->boundedGet($membershipQuery->orderBy('kelas_id')->orderBy('siswa_id')->orderBy('id'))
            ->unique(fn (AnggotaKelas $m) => $m->kelas_id . ':' . $m->siswa_id);

        // Batch aggregate; no per-student query. Apply the same scope and integrity rules as detail reports.
        $attendance = Presensi::query()->join('jadwal_pelajaran as r_schedule', 'r_schedule.id', '=', 'presensi.jadwal_pelajaran_id')
            ->selectRaw('presensi.siswa_id, r_schedule.kelas_id, LOWER(presensi.status) as status, COUNT(*) as jumlah');
        $grades = Nilai::query()->join('komponen_nilai as r_component', 'r_component.id', '=', 'nilai.komponen_nilai_id')
            ->join('jadwal_pelajaran as r_schedule', 'r_schedule.id', '=', 'r_component.jadwal_pelajaran_id')
            ->selectRaw('nilai.siswa_id, r_schedule.kelas_id, COUNT(nilai.nilai) as jumlah, AVG(nilai.nilai) as rata');

        foreach ([$attendance, $grades] as $query) {
            $query->whereIn('r_schedule.kelas_id', $memberships->pluck('kelas_id')->unique()->values())
                ->whereIn($query === $attendance ? 'presensi.siswa_id' : 'nilai.siswa_id', $memberships->pluck('siswa_id')->unique()->values());
            if (isset($filters['academic_year_id'])) $query->where('r_schedule.tahun_akademik_id', $filters['academic_year_id']);
            if (isset($filters['semester'])) $query->whereExists(fn ($q) => $q->selectRaw('1')->from('tahun_akademik as r_year')
                ->whereColumn('r_year.id', 'r_schedule.tahun_akademik_id')->where('r_year.semester', $filters['semester']));
        }
        // Use the base model queries for authorization; joined columns must be qualified.
        $attendanceIds = Presensi::query()->select('presensi.id');
        $this->applyAttendanceScope($attendanceIds, $user, $filters);
        $gradeIds = Nilai::query()->select('nilai.id');
        $this->applyGradeScope($gradeIds, $user, $filters);
        $attendance = $attendance->whereIn('presensi.id', $attendanceIds)->groupBy('presensi.siswa_id', 'r_schedule.kelas_id')->groupByRaw('LOWER(presensi.status)')->get()
            ->groupBy(fn ($r) => $r->kelas_id . ':' . $r->siswa_id);
        $grades = $grades->whereIn('nilai.id', $gradeIds)->groupBy('nilai.siswa_id', 'r_schedule.kelas_id')->get()
            ->keyBy(fn ($r) => $r->kelas_id . ':' . $r->siswa_id);

        $rows = $memberships->map(function (AnggotaKelas $membership) use ($attendance, $grades): array {
            $key = $membership->kelas_id . ':' . $membership->siswa_id;
            $counts = $attendance->get($key, collect())->pluck('jumlah', 'status');
            $grade = $grades->get($key);
            $hadir = (int) $counts->get('hadir', 0);
            $izin = (int) $counts->get('izin', 0);
            $sakit = (int) $counts->get('sakit', 0);
            $alpa = (int) $counts->get('alpa', 0);
            $total = $hadir + $izin + $sakit + $alpa;
            return [
                'nis' => $membership->siswa?->nis,
                'nisn' => $membership->siswa?->nisn,
                'nama_siswa' => $membership->siswa?->nama_lengkap,
                'kelas' => $membership->kelas?->nama,
                'tingkat' => $membership->kelas?->tingkat,
                ...$this->period($membership->kelas?->tahunAkademik),
                'hadir' => $hadir, 'izin' => $izin, 'sakit' => $sakit, 'alpa' => $alpa,
                'total_presensi' => $total,
                'persentase_hadir' => $total > 0 ? round($hadir * 100 / $total, 2) : null,
                'jumlah_nilai' => (int) ($grade?->jumlah ?? 0),
                'rata_rata_nilai' => $grade?->rata === null ? null : round((float) $grade->rata, 2),
            ];
        });

        return $this->result('Rekap Catatan Akademik Semester (Bukan Rapor)', [
            'NIS', 'NISN', 'Nama Siswa', 'Kelas', 'Tingkat', 'Tahun Akademik', 'Semester',
            'Hadir', 'Izin', 'Sakit', 'Alpa', 'Total Catatan Presensi', 'Hadir dari Catatan (%)',
            'Jumlah Nilai Tercatat', 'Rata-rata Komponen (Tanpa Bobot)',
        ], $rows);
    }

    private function boundedGet(Builder $query, array $columns=['*']): Collection
    {
        if ($this->rowLimit !== null) $query->limit($this->rowLimit+1);
        $rows=$query->get($columns);
        abort_if($this->rowLimit!==null && $rows->count()>$this->rowLimit,422,'Persempit filter laporan.');
        return $rows;
    }
    private function period($year): array
    {
        return [
            'tahun_akademik' => $year ? $year->tahun_mulai . '/' . $year->tahun_selesai : null,
            'semester' => $year?->semester,
        ];
    }

    private function applyStudentScope(Builder $query, User $user, array $filters): void
    {
        if ($filters['scope'] === 'own') $query->whereKey($user->siswa->id);
        // Roster membership is constrained in students() and semesterRecap().
    }

    private function applyScheduleScope(Builder $query, User $user, array $filters): void
    {
        $this->consistentSchedule($query);
        if ($filters['scope'] === 'school') return;
        $scheduleScope = ReportAccess::schedules($user, $filters);
        if ($filters['scope'] === 'own' && \App\Models\TahunAkademik::query()->whereKey($filters['academic_year_id'])->where('is_active', true)->exists()) {
            $scheduleScope->whereIn('kelas_id', \App\Models\AnggotaKelas::query()->where('siswa_id', $user->siswa->id)
                ->whereDate('tanggal_mulai', '<=', today('Asia/Jakarta'))->where(fn (Builder $q) => $q->whereNull('tanggal_selesai')->orWhereDate('tanggal_selesai', '>=', today('Asia/Jakarta')))->select('kelas_id'));
        }
        $query->whereIn('id', $scheduleScope->select('id'));
    }

    private function applyAttendanceScope(Builder $query, User $user, array $filters): void
    {
        $query->whereRaw("HEX(UPPER(presensi.status)) IN ('4841444952','495A494E','53414B4954','414C5041')")
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('presensi as duplicate_record')
                ->whereColumn('duplicate_record.jadwal_pelajaran_id','presensi.jadwal_pelajaran_id')
                ->whereColumn('duplicate_record.siswa_id','presensi.siswa_id')
                ->whereColumn('duplicate_record.id','<>','presensi.id')
                ->whereRaw('DATE(duplicate_record.tanggal) = DATE(presensi.tanggal)'));
        $query->whereHas('jadwalPelajaran', fn (Builder $q) => $this->consistentSchedule($q));
        if ($filters['scope'] === 'own') $query->where('siswa_id', $user->siswa->id);
        if ($filters['scope'] !== 'school') {
            $query->whereHas('jadwalPelajaran', fn (Builder $q) => $q->whereIn('id', ReportAccess::schedules($user, $filters)->select('id')));
        }
        // Correlate the record to the student's actual class at the meeting date.
        $query->whereExists(function ($q): void {
            $q->selectRaw('1')->from('anggota_kelas as member')
                ->join('jadwal_pelajaran as schedule', 'schedule.kelas_id', '=', 'member.kelas_id')
                ->whereColumn('schedule.id', 'presensi.jadwal_pelajaran_id')
                ->whereColumn('member.siswa_id', 'presensi.siswa_id')
                ->whereRaw('DATE(member.tanggal_mulai) <= DATE(presensi.tanggal)')
                ->whereDate('member.tanggal_mulai', '<=', today('Asia/Jakarta'))
                ->where(fn ($q) => $q->whereNull('member.tanggal_selesai')->orWhereRaw('DATE(member.tanggal_selesai) >= DATE(presensi.tanggal)'));
        });
    }

    private function applyGradeScope(Builder $query, User $user, array $filters): void
    {
        $query->whereHas('komponenNilai.jadwalPelajaran', fn (Builder $q) => $this->consistentSchedule($q));
        if ($filters['scope'] === 'own') $query->where('siswa_id', $user->siswa->id);
        if ($filters['scope'] !== 'school') {
            $query->whereHas('komponenNilai.jadwalPelajaran', fn (Builder $q) => $q->whereIn('id', ReportAccess::schedules($user, $filters)->select('id')));
        }
        $query->whereBetween('nilai.nilai',[0,100])->whereNotExists(fn ($q) => $q->selectRaw('1')->from('nilai as duplicate_record')
            ->whereColumn('duplicate_record.komponen_nilai_id','nilai.komponen_nilai_id')
            ->whereColumn('duplicate_record.siswa_id','nilai.siswa_id')->whereColumn('duplicate_record.id','<>','nilai.id'));
        // Grade records have no assessment date; include started memberships in this class only.
        $query->whereExists(function ($q): void {
            $q->selectRaw('1')->from('anggota_kelas as member')
                ->join('jadwal_pelajaran as schedule', 'schedule.kelas_id', '=', 'member.kelas_id')
                ->join('komponen_nilai as component', 'component.jadwal_pelajaran_id', '=', 'schedule.id')
                ->whereColumn('component.id', 'nilai.komponen_nilai_id')
                ->whereColumn('member.siswa_id', 'nilai.siswa_id')
                ->whereDate('member.tanggal_mulai', '<=', today('Asia/Jakarta'))
                ->where(fn ($q) => $q->whereNull('member.tanggal_selesai')->orWhereRaw('DATE(member.tanggal_selesai) >= DATE(member.tanggal_mulai)'));
        });
    }

    private function consistentSchedule(Builder $query): void
    {
        $query->whereHas('kelas', fn (Builder $q) => $q->whereColumn('kelas.tahun_akademik_id', 'jadwal_pelajaran.tahun_akademik_id'));
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
