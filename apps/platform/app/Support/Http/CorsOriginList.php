<?php

namespace App\Support\Http;

use InvalidArgumentException;

/**
 * Phase 0O.3 (ADR 0049 section 10): parses the ONE explicit CORS origin
 * setting (`CORS_ALLOWED_ORIGINS`, comma-separated) into exact origins.
 *
 * Empty (the default) means no cross-origin browser access. Each entry
 * must be exactly `scheme://host[:port]` -- https, or http for a loopback
 * host only -- with no path, query, credentials or wildcard. Anything else
 * THROWS, so a malformed value fails configuration loading loudly instead
 * of silently widening (or quietly narrowing) access. No patterns, no
 * suffix matching, no `*.domain`, no automatic School custom domains (O9).
 */
final class CorsOriginList
{
    /**
     * @return list<string>
     */
    public static function parse(?string $value): array
    {
        $origins = [];

        foreach (explode(',', (string) $value) as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            $origins[] = self::origin($entry);
        }

        return array_values(array_unique($origins));
    }

    private static function origin(string $entry): string
    {
        if (str_contains($entry, '*')) {
            throw new InvalidArgumentException('CORS_ALLOWED_ORIGINS must list exact origins; wildcards are not allowed.');
        }

        $parts = parse_url($entry);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])
            || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('CORS_ALLOWED_ORIGINS entries must be exactly scheme://host[:port].');
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $loopback = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);

        if ($scheme !== 'https' && ! ($scheme === 'http' && $loopback)) {
            throw new InvalidArgumentException('CORS_ALLOWED_ORIGINS entries must use https (http only for a loopback host).');
        }

        if (preg_match('/^(\[[0-9a-f:]+\]|[a-z0-9.-]+)$/', $host) !== 1) {
            throw new InvalidArgumentException('CORS_ALLOWED_ORIGINS contains an invalid host.');
        }

        return $scheme.'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
