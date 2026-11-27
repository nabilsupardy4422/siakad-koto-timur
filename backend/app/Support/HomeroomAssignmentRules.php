<?php

namespace App\Support;

/** Pure date/interval rules. Dates are ISO calendar days in WIB. */
final class HomeroomAssignmentRules
{
    public static function invalid(?string $start, ?string $end, string $today): bool
    {
        return $start === null || ($end !== null && ($end < $start || $end > $today));
    }

    public static function overlaps(string $start, ?string $end, string $otherStart, ?string $otherEnd): bool
    {
        // A same-day closure records history but occupies no further calendar days.
        if ($end === $start || $otherEnd === $otherStart) return false;
        return ($end === null || $otherStart < $end)
            && ($otherEnd === null || $start < $otherEnd);
    }

    public static function status(?string $start, ?string $end, string $today): string
    {
        if (self::invalid($start, $end, $today)) return 'conflict';
        if ($end !== null) return 'history';
        return $start > $today ? 'scheduled' : 'effective';
    }
}
