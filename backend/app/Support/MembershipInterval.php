<?php

namespace App\Support;

/** Dates are ISO calendar dates; end dates are inclusive. Null end means open. */
final class MembershipInterval
{
    public static function conflictsWithOpen(?string $existingStart, ?string $existingEnd, string $newStart): bool
    {
        return $existingStart === null || ($existingEnd !== null && $existingEnd < $existingStart)
            || $existingEnd === null || $existingEnd >= $newStart;
    }
}
