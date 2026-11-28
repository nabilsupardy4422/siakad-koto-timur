<?php

namespace App\Support;

use App\Models\JadwalPelajaran;
use App\Models\TahunAkademik;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class AssignmentTeachingAccess
{
    public static function activeYearId(): ?int
    {
        $ids = TahunAkademik::query()->where('is_active', true)->pluck('id');
        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    public static function applyTeacher(Builder $query, User $user): void
    {
        $year = self::activeYearId();
        if ($user->role?->code !== 'GURU' || $year === null) {
            $query->whereRaw('1 = 0');
            return;
        }
        TeachingAssignmentScope::apply($query, $user->guru?->id, $year);
    }

    public static function teacherCanAccess(User $user, ?JadwalPelajaran $schedule): bool
    {
        if (! $schedule) return false;
        $query = JadwalPelajaran::query()->whereKey($schedule->id);
        self::applyTeacher($query, $user);
        return $query->exists();
    }

    public static function studentCanAccess($student, ?JadwalPelajaran $schedule): bool
    {
        $year = self::activeYearId();
        if (! $student || ! $schedule || $year === null || (int) $schedule->tahun_akademik_id !== $year) return false;
        return $student->anggotaKelas()->where('kelas_id', $schedule->kelas_id)
            ->whereDate('tanggal_mulai', '<=', now('Asia/Jakarta')->toDateString())
            ->where(fn($q)=>$q->whereNull('tanggal_selesai')->orWhereDate('tanggal_selesai','>=',now('Asia/Jakarta')->toDateString()))->exists();
    }
}
