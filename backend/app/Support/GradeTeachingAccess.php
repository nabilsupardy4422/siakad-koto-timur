<?php

namespace App\Support;

use App\Models\JadwalPelajaran;
use App\Models\TahunAkademik;
use App\Models\AnggotaKelas;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

final class GradeTeachingAccess
{
    public static function readScope(Request $request): string
    {
        $validated = $request->validate(['scope' => 'sometimes|required|string|in:teaching,homeroom']);
        if (isset($validated['scope'])) {
            abort_unless($request->user()?->role?->code === 'GURU', 403);
        }
        return $validated['scope'] ?? TeachingAssignmentScope::LEGACY;
    }

    public static function apply(Builder $query, ?User $user, string $scope = TeachingAssignmentScope::TEACHING): void
    {
        $ids = TahunAkademik::query()->where('is_active', true)->pluck('id');
        if ($user?->role?->code !== 'GURU' || $user->guru?->id === null || $ids->count() !== 1) {
            $query->whereRaw('1 = 0');
            return;
        }
        $year = (int) $ids->first();
        $query->whereHas('kelas', fn (Builder $class) => $class->where('tahun_akademik_id', $year));
        TeachingAssignmentScope::apply($query, $user->guru->id, $year, $scope);
    }

    public static function canManage(?User $user, ?JadwalPelajaran $schedule): bool
    {
        if (! $schedule) return false;
        $query = JadwalPelajaran::query()->whereKey($schedule->id);
        self::apply($query, $user);
        return $query->exists();
    }

    // Same date convention as ClassMemberController::index (application timezone).
    public static function activeStudent(JadwalPelajaran $schedule, int $studentId): bool
    {
        $today = today('Asia/Jakarta')->toDateString();
        return AnggotaKelas::query()->where('kelas_id', $schedule->kelas_id)
            ->where('siswa_id', $studentId)->whereDate('tanggal_mulai', '<=', $today)
            ->where(fn (Builder $q) => $q->whereNull('tanggal_selesai')->orWhereDate('tanggal_selesai', '>=', $today))
            ->exists();
    }
}
