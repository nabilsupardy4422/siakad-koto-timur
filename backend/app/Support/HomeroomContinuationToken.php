<?php

namespace App\Support;

/** Short-lived preview signature bound to actor, dates, mappings and versions. */
final class HomeroomContinuationToken
{
    private static function sign(array $state, int $expiry, string $key): string
    {
        return hash_hmac('sha256', json_encode([$expiry,$state], JSON_THROW_ON_ERROR), $key);
    }

    public static function issue(array $state, int $now, string $key): string
    {
        if ($key === '') throw new \InvalidArgumentException('Signing key required.');
        $expiry = $now + 300;
        return $expiry.'.'.self::sign($state,$expiry,$key);
    }

    public static function verify(string $token, array $state, int $now, string $key): bool
    {
        if ($key === '' || !preg_match('/^(\d{1,12})\.([a-f0-9]{64})$/D', $token, $parts)) return false;
        $expiry=(int)$parts[1];
        return $expiry >= $now && $expiry <= $now+300 && hash_equals(self::sign($state,$expiry,$key),$parts[2]);
    }
}
