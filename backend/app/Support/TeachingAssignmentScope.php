<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/** Read contexts for an authenticated teacher. Never grants write permission. */
final class TeachingAssignmentScope
{
    public const TEACHING = 'teaching';
    public const HOMEROOM = 'homeroom';
    public const LEGACY = 'combined';

    public static function apply(
        Builder $query,
        ?int $guruId,
        int $academicYearId,
        string $scope = self::TEACHING
    ): void {
        if ($guruId === null || ! in_array($scope, [self::TEACHING, self::HOMEROOM, self::LEGACY], true)) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->where('tahun_akademik_id', $academicYearId);

        // Existing application convention: an open assignment has no end date.
        // The selected active academic year, not the real calendar year, is authoritative.
        $homeroom = static function (Builder $class) use ($guruId, $academicYearId, $scope): void {
            $class->where('tahun_akademik_id', $academicYearId)
                ->whereHas('waliKelas', static function (Builder $assignment) use ($guruId, $academicYearId, $scope): void {
                    HomeroomReadAccess::restrictCurrent($assignment, $guruId, $academicYearId);
                });
        };

        if ($scope === self::TEACHING) {
            $query->where('guru_id', $guruId);
        } elseif ($scope === self::HOMEROOM) {
            $query->whereHas('kelas', $homeroom);
        } else {
            // Compatibility for existing monitoring callers until their migration.
            $query->where(static function (Builder $builder) use ($guruId, $homeroom): void {
                $builder->where('guru_id', $guruId)->orWhereHas('kelas', $homeroom);
            });
        }
    }
}
