<?php

namespace App\Services;

/** Administrator-configured activation destination; never accepts a request URL. */
final class ActivationUrlPolicy
{
    public static function allows(mixed $url, string $environment, mixed $lanHost = null): bool
    {
        if (! is_string($url) || strlen($url) > 250 || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($url);
        if (! $parts || ! isset($parts['scheme'], $parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ($parts['path'] ?? '') !== '/aktivasi-akun') {
            return false;
        }
        if ($parts['scheme'] === 'https') {
            return true;
        }
        if ($parts['scheme'] !== 'http' || ! in_array($environment, ['local', 'testing'], true)) {
            return false;
        }
        $host = $parts['host'];
        if (in_array($host, ['localhost', '127.0.0.1'], true)) {
            return true;
        }
        if (! is_string($lanHost) || $host !== $lanHost || ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        // RFC1918 only, not arbitrary reserved, public, link-local or loopback addresses.
        [$a, $b] = array_map('intval', explode('.', $host));
        return $a === 10 || ($a === 172 && $b >= 16 && $b <= 31) || ($a === 192 && $b === 168);
    }
}
