<?php

namespace App\Policies;

use App\Models\Rapor;
use App\Models\User;

class RaporPolicy
{
    /**
     * Determine whether the user can view any report cards.
     */
    public function viewAny(User $user): bool
    {
        return $user->role?->code === 'TU'
            || $user->role?->code === 'SISWA';
    }

    /**
     * Determine whether the user can view the report card.
     */
    public function view(User $user, Rapor $rapor): bool
    {
        if ($user->role?->code === 'TU') {
            return true;
        }

        if ($user->role?->code !== 'SISWA') {
            return false;
        }

        $siswaId = $user->siswa?->id;

        return $siswaId !== null
            && $rapor->siswa_id === $siswaId;
    }

    /**
     * Determine whether the user can create report cards.
     */
    public function create(User $user): bool
    {
        return $user->role?->code === 'TU';
    }

    /**
     * Determine whether the user can update the report card.
     *
     * The current API contract does not define a report-card update
     * endpoint. Therefore this policy intentionally denies update.
     */
    public function update(User $user, Rapor $rapor): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the report card.
     */
    public function delete(User $user, Rapor $rapor): bool
    {
        return $user->role?->code === 'TU';
    }

    /**
     * Determine whether the user can restore the report card.
     */
    public function restore(User $user, Rapor $rapor): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the report card.
     */
    public function forceDelete(User $user, Rapor $rapor): bool
    {
        return false;
    }
}