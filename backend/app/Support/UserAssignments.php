<?php

namespace App\Support;

use App\Models\User;
use App\Models\WaliKelas;

class UserAssignments
{
    public static function forUser(User $user): array
    {
        if ($user->role?->code !== 'GURU') {
            return [];
        }

        $guruId = $user->guru?->id;

        if ($guruId === null) {
            return [];
        }

        $today = now()->toDateString();

        $hasActiveHomeroomAssignment = WaliKelas::query()
            ->where('guru_id', $guruId)
            ->whereDate('tanggal_mulai', '<=', $today)
            ->where(function ($query) use ($today) {
                $query->whereNull('tanggal_selesai')
                    ->orWhereDate('tanggal_selesai', '>=', $today);
            })
            ->whereHas('tahunAkademik', function ($query) {
                $query->where('is_active', true);
            })
            ->exists();

        if (! $hasActiveHomeroomAssignment) {
            return [];
        }

        return [
            ['type' => 'WALI_KELAS'],
        ];
    }
}
