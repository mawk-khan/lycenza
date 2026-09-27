<?php

namespace App\Support\Domains\Dns;

/**
 * One bounded DNS answer (ADR 0054 section 4.5). `absent` is conclusive
 * (NXDOMAIN, or no record of the type: NODATA); `indeterminate` (timeout,
 * SERVFAIL, REFUSED, truncation beyond bounds, a malformed answer, a CNAME
 * loop or chain beyond depth 8, too many records) never is -- a timeout is
 * never collapsed into "absent". `reason` is a closed code, safe to log.
 */
final class DnsLookup
{
    public const OK = 'ok';

    public const ABSENT = 'absent';

    public const INDETERMINATE = 'indeterminate';

    public const REASONS = [
        'nxdomain', 'nodata', 'timeout', 'servfail', 'refused', 'rcode', 'network', 'truncated', 'malformed',
        'cname_loop', 'cname_depth', 'too_many_records', 'not_configured', 'budget',
    ];

    /**
     * @param  list<string>  $txt  each TXT RR's character-strings joined with no separator
     * @param  list<string>  $chain  the CNAME chain, starting with the queried name
     * @param  list<string>  $addresses  A/AAAA addresses at the end of the chain
     */
    private function __construct(
        public readonly string $status,
        public readonly array $txt = [],
        public readonly array $chain = [],
        public readonly array $addresses = [],
        public readonly ?string $reason = null,
    ) {}

    /** @param list<string> $records */
    public static function txtRecords(array $records): self
    {
        return new self(self::OK, txt: $records);
    }

    /**
     * @param  list<string>  $chain
     * @param  list<string>  $addresses
     */
    public static function resolved(array $chain, array $addresses): self
    {
        return new self(self::OK, chain: $chain, addresses: $addresses);
    }

    public static function absent(string $reason = 'nxdomain'): self
    {
        return new self(self::ABSENT, reason: $reason);
    }

    public static function indeterminate(string $reason): self
    {
        return new self(self::INDETERMINATE, reason: $reason);
    }

    public function isOk(): bool
    {
        return $this->status === self::OK;
    }

    public function isAbsent(): bool
    {
        return $this->status === self::ABSENT;
    }

    public function isIndeterminate(): bool
    {
        return $this->status === self::INDETERMINATE;
    }
}
