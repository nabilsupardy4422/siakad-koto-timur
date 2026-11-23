<?php

namespace App\Support;

use App\Models\JadwalPelajaran;
use App\Models\TahunAkademik;
use App\Models\User;

final class AttendanceTeachingAccess
{
    public static function canManage(?User $user, JadwalPelajaran $schedule): bool
    {
        if ($user?->role?->code !== 'GURU' || $user->guru?->id === null) {
            return false;
        }
        $yearIds = TahunAkademik::query()->where('is_active', true)->pluck('id');
        if ($yearIds->count() !== 1) {
            return false;
        }
        $yearId = (int) $yearIds->first();
        $query = JadwalPelajaran::query()->whereKey($schedule->id)
            ->whereHas('kelas', fn ($class) => $class->where('tahun_akademik_id', $yearId));
        TeachingAssignmentScope::apply($query, $user->guru->id, $yearId);
        return $query->exists();
    }
}
