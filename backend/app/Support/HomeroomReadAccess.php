<?php

namespace App\Support;

use App\Models\Kelas;
use App\Models\TahunAkademik;
use App\Models\User;
use App\Models\WaliKelas;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Explicit, read-only homeroom workspace authorization. */
final class HomeroomReadAccess
{
    public static function effective(Builder $query, int $guruId, int $yearId): void
    {
        $query->where('guru_id', $guruId)
            ->where('tahun_akademik_id', $yearId)
            ->whereDate('tanggal_mulai', '<=', today('Asia/Jakarta')->toDateString())
            ->whereNull('tanggal_selesai')->whereNull('cancelled_at');
    }

    /** Restrict any homeroom privilege to the single active period. */
    public static function restrictCurrent(Builder $query, int $guruId, ?int $requestedYear = null): void
    {
        $ids = TahunAkademik::query()->where('is_active', true)->pluck('id');
        if ($ids->count() !== 1 || ($requestedYear !== null && (int) $ids->first() !== $requestedYear)) {
            $query->whereRaw('1 = 0');
            return;
        }
        $year = (int) $ids->first();
        self::effective($query, $guruId, $year);
        $query->whereHas('kelas', fn (Builder $class) => $class->where('tahun_akademik_id', $year));
    }

    public static function currentAssignments(int $guruId, ?int $requestedYear = null): Builder
    {
        $query = WaliKelas::query();
        self::restrictCurrent($query, $guruId, $requestedYear);
        return $query;
    }

    public static function activeYear(User $user): ?int
    {
        abort_unless($user->role?->code === 'GURU' && $user->guru?->id !== null, 403);
        $ids = TahunAkademik::query()->where('is_active', true)->pluck('id');
        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    public static function authorizeClass(User $user, Kelas $class): void
    {
        $year = self::activeYear($user);
        abort_unless($year !== null && (int) $class->tahun_akademik_id === $year, 403);
        $assignments = $class->waliKelas();
        self::effective($assignments->getQuery(), (int) $user->guru->id, $year);
        abort_unless($assignments->exists(), 403);
    }

    public static function authorizeRecap(Request $request): void
    {
        if ($request->input('scope') !== TeachingAssignmentScope::HOMEROOM) return;
        $user = $request->user();
        $year = self::activeYear($user);
        if ($request->filled('academic_year_id')) {
            abort_unless($year !== null && $request->integer('academic_year_id') === $year, 403);
        }
        if ($request->filled('class_id')) {
            $class = Kelas::query()->findOrFail($request->integer('class_id'));
            self::authorizeClass($user, $class);
        }
    }
}
