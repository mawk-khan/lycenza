<?php

namespace App\Support\Http;

use InvalidArgumentException;

/**
 * Phase 0O.4A (ADR 0050 section 2): parses TRUSTED_PROXIES -- the ONE
 * explicit list of reverse proxies whose X-Forwarded-* headers the
 * application believes. Comma-separated IPv4/IPv6 addresses or CIDRs.
 *
 * Empty (the default) means trust nobody. It THROWS -- so configuration
 * loading fails loudly, in every environment -- on anything else: a
 * trust-all value (`*`, `**`, `0.0.0.0/0`, `::/0`, or any prefix too broad
 * to name a proxy: IPv4 shorter than /8, IPv6 shorter than /32, the
 * IPv4-mapped IPv6 space), a hostname (no DNS-based trust), a malformed
 * address or prefix, or an empty entry between commas.
 */
final class TrustedProxyList
{
    /**
     * @return list<string>
     */
    public static function parse(?string $value): array
    {
        $value = trim((string) $value);

        if ($value === '') {
            return [];
        }

        $proxies = [];

        foreach (explode(',', $value) as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                throw new InvalidArgumentException('TRUSTED_PROXIES contains an empty entry.');
            }

            $proxies[] = self::entry($entry);
        }

        return array_values(array_unique($proxies));
    }

    private static function entry(string $entry): string
    {
        if (in_array($entry, ['*', '**'], true)) {
            throw new InvalidArgumentException('TRUSTED_PROXIES must name proxies; trusting every client is not allowed.');
        }

        [$address, $prefix] = str_contains($entry, '/') ? explode('/', $entry, 2) : [$entry, null];

        $isV4 = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $isV6 = ! $isV4 && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

        if (! $isV4 && ! $isV6) {
            throw new InvalidArgumentException('TRUSTED_PROXIES entries must be IP addresses or CIDRs (no hostnames).');
        }

        if ($prefix !== null) {
            $max = $isV4 ? 32 : 128;
            $min = $isV4 ? 8 : 32;

            if (! ctype_digit($prefix) || (int) $prefix > $max) {
                throw new InvalidArgumentException('TRUSTED_PROXIES contains a malformed CIDR prefix.');
            }

            if ((int) $prefix < $min) {
                throw new InvalidArgumentException('TRUSTED_PROXIES contains a range too broad to be a proxy (trust-all equivalent).');
            }

            if ($isV6 && str_starts_with(strtolower(inet_ntop((string) inet_pton($address)) ?: ''), '::ffff:') && (int) $prefix <= 96) {
                throw new InvalidArgumentException('TRUSTED_PROXIES contains the IPv4-mapped IPv6 space (trust-all equivalent).');
            }

            return strtolower($address).'/'.(int) $prefix;
        }

        return strtolower($address);
    }
}
