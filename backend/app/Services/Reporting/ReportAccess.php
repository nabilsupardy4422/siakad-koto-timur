<?php

namespace App\Services\Reporting;

use App\Models\AnggotaKelas;
use App\Models\JadwalPelajaran;
use App\Models\Kelas;
use App\Models\TahunAkademik;
use App\Models\User;
use App\Support\HomeroomReadAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Authorization for the shared reporting service, independent of the UI. */
final class ReportAccess
{
    private const TYPES = ['students', 'teachers', 'classes', 'schedules', 'attendance', 'grades', 'semester-recap'];

    public static function allowsType(User $user, string $type): bool
    {
        return in_array($type, match ($user->role?->code) {
            'TU' => [...self::TYPES, 'attendance-daily','attendance-monthly','attendance-coverage','grade-coverage','grade-components'],
            'KEPALA_SEKOLAH' => self::TYPES,
            'GURU' => ['students', 'schedules', 'attendance', 'grades', 'semester-recap'],
            // students is a personal identity report here, never a roster.
            'SISWA' => ['students', 'schedules', 'attendance', 'grades', 'semester-recap'],
            default => [],
        }, true);
    }

    private static function require(bool $allowed): void
    {
        if (! $allowed) {
            throw new AuthorizationException('Anda tidak memiliki akses ke konteks laporan tersebut.');
        }
    }

    public static function normalize(User $user, string $type, array $filters): array
    {
        self::require(self::allowsType($user, $type));
        $role = $user->role?->code;
        $scope = $filters['scope'] ?? match ($role) {
            'GURU' => 'teaching',
            'SISWA' => 'own',
            default => 'school',
        };
        self::require(in_array($scope, match ($role) {
            'GURU' => ['teaching', 'homeroom'],
            'SISWA' => ['own'],
            default => ['school'],
        }, true));
        $filters['scope'] = $scope;
        if ($scope === 'school') {
            self::require($user->role?->code !== 'KEPALA_SEKOLAH' || $type !== 'teachers' || ! isset($filters['status']));
            return $filters;
        }
        if ($scope === 'own') {
            self::require($user->siswa?->id !== null);
            self::require(! isset($filters['student_id']) || (int) $filters['student_id'] === (int) $user->siswa->id);
            // Preserve explicit personal historical reports. Unspecified year uses active period.
        } else {
            self::require($user->guru?->id !== null);
            self::require($type !== 'semester-recap' || $scope === 'homeroom');
        }
        if ($scope !== 'own' || ! isset($filters['academic_year_id'])) {
            $active = TahunAkademik::query()->where('is_active', true)->pluck('id');
            self::require($active->count() === 1);
            $year = (int) $active->first();
            self::require(! isset($filters['academic_year_id']) || (int) $filters['academic_year_id'] === $year);
            $filters['academic_year_id'] = $year;
        }
        $year = TahunAkademik::query()->find($filters['academic_year_id']);
        self::require($year !== null);
        self::require(! isset($filters['semester']) || strcasecmp($filters['semester'], $year->semester) === 0);
        if (isset($filters['semester'])) $filters['semester'] = $year->semester;
        $classes = self::classIds($user, $filters);
        if ($scope === 'own' && $year->is_active) {
            self::require(TahunAkademik::where('is_active', true)->count() === 1);
            self::require(\App\Support\StudentScheduleAccess::resolve((int) $user->siswa->id, $year)['status'] !== 'ambiguous');
        }
        if ($scope === 'homeroom') {
            self::require($classes->isNotEmpty());
        }
        if (isset($filters['class_id'])) {
            self::require($classes->contains((int) $filters['class_id']));
        } elseif ($scope === 'homeroom' && $classes->count() === 1) {
            $filters['class_id'] = (int) $classes->first();
        }
        // A semester recap must describe one class, not combine unrelated memberships.
        if ($scope === 'homeroom' && $type === 'semester-recap') {
            self::require(isset($filters['class_id']));
        }
        if (isset($filters['subject_id']) || isset($filters['teacher_id']) || isset($filters['schedule_id'])) {
            self::require(self::schedules($user, $filters)->exists());
        }
        if ($scope !== 'own' && isset($filters['student_id'])) {
            $studentClasses = isset($filters['subject_id']) || isset($filters['schedule_id']) ? self::schedules($user, $filters)->pluck('kelas_id') : $classes;
            self::require(AnggotaKelas::query()->where('siswa_id', $filters['student_id'])
                ->whereDate('tanggal_mulai', '<=', today('Asia/Jakarta'))
                ->whereIn('kelas_id', $studentClasses)
                ->when(isset($filters['class_id']), fn (Builder $q) => $q->where('kelas_id', $filters['class_id']))
                ->exists());
        }
        return $filters;
    }

    public static function schedules(User $user, array $filters): Builder
    {
        $year = (int) $filters['academic_year_id'];
        $query = JadwalPelajaran::query()->where('tahun_akademik_id', $year)
            ->whereHas('kelas', fn (Builder $q) => $q->where('tahun_akademik_id', $year));
        match ($filters['scope']) {
            'teaching' => $query->where('guru_id', $user->guru->id),
            'homeroom', 'own' => $query->whereIn('kelas_id', self::classIds($user, $filters)),
            default => $query->whereRaw('1 = 0'),
        };
        foreach (['class_id' => 'kelas_id', 'subject_id' => 'mapel_id', 'teacher_id' => 'guru_id', 'schedule_id' => 'id'] as $filter => $column) {
            if (isset($filters[$filter])) $query->where($column, $filters[$filter]);
        }
        return $query;
    }

    public static function classIds(User $user, array $filters): Collection
    {
        $year = (int) $filters['academic_year_id'];
        $ids = match ($filters['scope']) {
            'teaching' => JadwalPelajaran::query()->where('guru_id', $user->guru->id)
                ->where('tahun_akademik_id', $year)->pluck('kelas_id'),
            'homeroom' => HomeroomReadAccess::currentAssignments((int) $user->guru->id, $year)->pluck('kelas_id'),
            'own' => AnggotaKelas::query()->where('siswa_id', $user->siswa->id)
                ->whereDate('tanggal_mulai', '<=', today('Asia/Jakarta'))
                ->where(fn ($q) => $q->whereNull('tanggal_selesai')->orWhereColumn('tanggal_selesai','>=','tanggal_mulai'))->pluck('kelas_id'),
            default => collect(),
        };
        return Kelas::query()->where('tahun_akademik_id', $year)->whereIn('id', $ids)
            ->pluck('id')->map(fn ($id) => (int) $id);
    }

    /** Restrict the same membership used for selecting AND displaying a roster row. */
    public static function membership(Builder $query, User $user, array $filters, bool $activeOnly): void
    {
        if ($filters['scope'] !== 'school') {
            $ids = self::classIds($user, $filters);
            if (isset($filters['subject_id']) || isset($filters['schedule_id'])) {
                $ids = self::schedules($user, $filters)->pluck('kelas_id');
            }
            $query->whereIn('kelas_id', $ids);
        }
        if ($filters['scope'] === 'school' && (isset($filters['subject_id']) || isset($filters['schedule_id']))) {
            $schedules = JadwalPelajaran::query()
                ->whereHas('kelas', fn (Builder $q) => $q->whereColumn('kelas.tahun_akademik_id', 'jadwal_pelajaran.tahun_akademik_id'));
            if (isset($filters['subject_id'])) $schedules->where('mapel_id', $filters['subject_id']);
            if (isset($filters['schedule_id'])) $schedules->whereKey($filters['schedule_id']);
            if (isset($filters['academic_year_id'])) $schedules->where('tahun_akademik_id', $filters['academic_year_id']);
            $query->whereIn('kelas_id', $schedules->select('kelas_id'));
        }
        if (isset($filters['class_id'])) $query->where('kelas_id', $filters['class_id']);
        if (isset($filters['academic_year_id'])) {
            $query->whereHas('kelas', fn (Builder $q) => $q->where('tahun_akademik_id', $filters['academic_year_id']));
        }
        if ($filters['scope'] === 'own') $query->where(fn ($q) => $q->whereNull('tanggal_selesai')->orWhereColumn('tanggal_selesai','>=','tanggal_mulai'));
        if ($activeOnly) {
            $query->whereDate('tanggal_mulai', '<=', today('Asia/Jakarta'))
                ->where(fn (Builder $q) => $q->whereNull('tanggal_selesai')->orWhereDate('tanggal_selesai', '>=', today('Asia/Jakarta')));
        } elseif ($filters['scope'] !== 'school') {
            $query->whereDate('tanggal_mulai', '<=', today('Asia/Jakarta'));
        }
    }
}
