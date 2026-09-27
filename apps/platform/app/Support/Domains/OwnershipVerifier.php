<?php

namespace App\Support\Domains;

use App\Support\Domains\Dns\DomainDnsResolver;

/**
 * ADR 0054 section 4.2: the DNS TXT ownership proof, frozen syntax.
 *
 *   name   _lycenza-verification.<canonical hostname>
 *   value  lycenza-domain-verification=<token>
 *
 * Each TXT RR's character-strings are joined with no separator (DNS splits
 * long values), then compared with the expected value EXACTLY: case-
 * sensitive, no trimming, no prefix, suffix or substring match. Any one
 * equal RR is a `match`; records present but none equal is a `mismatch`;
 * NXDOMAIN/NODATA is `absent`; anything else is `indeterminate`.
 */
final class OwnershipVerifier
{
    public const RECORD_PREFIX = '_lycenza-verification.';

    public const VALUE_PREFIX = 'lycenza-domain-verification=';

    public const MATCH = 'match';

    public const MISMATCH = 'mismatch';

    public const ABSENT = 'absent';

    public const INDETERMINATE = 'indeterminate';

    public function __construct(private readonly DomainDnsResolver $dns) {}

    public static function recordName(string $hostname): string
    {
        return self::RECORD_PREFIX.$hostname;
    }

    public static function recordValue(string $token): string
    {
        return self::VALUE_PREFIX.$token;
    }

    /** @return array{outcome: string, reason: string|null} */
    public function check(string $hostname, string $token): array
    {
        $lookup = $this->dns->txt(self::recordName($hostname));

        if ($lookup->isIndeterminate()) {
            return ['outcome' => self::INDETERMINATE, 'reason' => $lookup->reason];
        }

        if ($lookup->isAbsent()) {
            return ['outcome' => self::ABSENT, 'reason' => $lookup->reason];
        }

        $expected = self::recordValue($token);

        foreach ($lookup->txt as $record) {
            if (hash_equals($expected, $record)) {
                return ['outcome' => self::MATCH, 'reason' => null];
            }
        }

        return ['outcome' => self::MISMATCH, 'reason' => null];
    }
}
