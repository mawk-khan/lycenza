<?php

namespace App\Support\Domains\Dns;

/**
 * ADR 0054 section 4.5: the only DNS the domain checks use. Bounded (2 s
 * per query, 2 attempts, 10 s per check, CNAME depth <= 8, <= 32 TXT RRs),
 * DNS only -- never an HTTP fetch of a customer URL. Production queries the
 * deployment's public recursive resolvers (NetDns2DomainResolver); local
 * and testing may bind FakeDomainDnsResolver (double-guarded).
 */
interface DomainDnsResolver
{
    public const QUERY_TIMEOUT_SECONDS = 2.0;

    public const ATTEMPTS = 2;

    public const BUDGET_SECONDS = 10.0;

    public const MAX_CNAME_DEPTH = 8;

    public const MAX_TXT_RECORDS = 32;

    public const MAX_ADDRESSES = 32;

    /** TXT records at the exact name (following a CNAME chain within bounds). */
    public function txt(string $name): DnsLookup;

    /** The CNAME chain and final A/AAAA addresses of a hostname. */
    public function addresses(string $hostname): DnsLookup;
}
