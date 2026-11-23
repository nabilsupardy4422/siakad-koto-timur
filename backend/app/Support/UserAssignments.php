<?php

namespace App\Support;

use App\Models\User;

class UserAssignments
{
    public static function forUser(User $user): array
    {
        if ($user->role?->code !== 'GURU' || $user->guru?->id === null) {
            return [];
        }
        $assignments = HomeroomReadAccess::currentAssignments((int) $user->guru->id)->orderBy('id')->get();
        if ($assignments->isEmpty()) return [];
        return [[
            'type' => 'WALI_KELAS',
            'academic_year_id' => (int) $assignments->first()->tahun_akademik_id,
            'assignment_ids' => $assignments->map(fn ($row) => (int) $row->id)->values()->all(),
            'class_ids' => $assignments->map(fn ($row) => (int) $row->kelas_id)->values()->all(),
        ]];
    }
}
