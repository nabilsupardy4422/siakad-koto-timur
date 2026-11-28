<?php

namespace App\Support;

use App\Models\AnggotaKelas;
use App\Models\Kelas;
use App\Models\TahunAkademik;
use Illuminate\Database\Eloquent\Builder;

/** Administrative statistics only; inactive periods describe history, never current access. */
final class ClassDirectory
{
    public static function context(TahunAkademik $period): array
    {
        $activeIds = TahunAkademik::where('is_active', true)->pluck('id');
        $active = $activeIds->count() === 1 && (int) $activeIds->first() === (int) $period->id;
        return ['id' => $period->id, 'tahun_mulai' => $period->tahun_mulai,
            'tahun_selesai' => $period->tahun_selesai, 'semester' => $period->semester,
            'mode' => $active ? 'effective' : 'history', 'as_of' => $active ? today('Asia/Jakarta')->toDateString() : null,
            'active_state' => $activeIds->count() === 1 ? 'valid' : ($activeIds->isEmpty() ? 'none' : 'conflict')];
    }

    public static function members(Builder $query, array $context): void
    {
        if ($context['mode'] === 'effective') {
            $date = $context['as_of'];
            $query->whereDate('tanggal_mulai', '<=', $date)
                ->where(fn ($q) => $q->whereNull('tanggal_selesai')->orWhereDate('tanggal_selesai', '>=', $date));
        }
    }

    public static function homerooms(Builder $query, array $context): void
    {
        $query->where('tahun_akademik_id', $context['id']);
        if ($context['mode'] === 'effective') {
            $query->whereDate('tanggal_mulai', '<=', $context['as_of'])->whereNull('tanggal_selesai')->whereNull('cancelled_at');
        }
    }

    public static function schedules(Builder $query, array $context): void
    {
        $query->where('tahun_akademik_id', $context['id']);
    }

    public static function enrich(Builder $query, array $context): Builder
    {
        return $query->withCount([
            'anggotaKelas as member_history_count', 'waliKelas as homeroom_history_count', 'jadwalPelajaran as schedule_history_count',
            'anggotaKelas as student_count' => function ($q) use ($context) {
                self::members($q, $context);
                $q->select([])->selectRaw('COUNT(DISTINCT anggota_kelas.siswa_id)');
            },
            'waliKelas as homeroom_count' => fn ($q) => self::homerooms($q, $context),
            'jadwalPelajaran as schedule_count' => fn ($q) => self::schedules($q, $context),
        ])->with(['waliKelas' => fn ($q) => self::homerooms($q->getQuery(), $context), 'waliKelas.guru:id,nama_lengkap']);
    }

    public static function summary(array $context): array
    {
        $base = Kelas::where('tahun_akademik_id', $context['id']);
        $members = AnggotaKelas::whereHas('kelas', fn ($q) => $q->where('tahun_akademik_id', $context['id']));
        self::members($members, $context);
        return [
            'total' => (clone $base)->count(),
            'students' => $members->distinct()->count('siswa_id'),
            'without_homeroom' => (clone $base)->whereDoesntHave('waliKelas', fn ($q) => self::homerooms($q, $context))->count(),
            'without_schedule' => (clone $base)->whereDoesntHave('jadwalPelajaran', fn ($q) => self::schedules($q, $context))->count(),
        ];
    }
}
