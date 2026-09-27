<?php

namespace App\Support\Domains\Probe;

/**
 * ADR 0054 section 6.2: the provider-neutral TLS readiness probe. It reads
 * what the deployment edge actually serves for a hostname, connecting ONLY
 * to the given, already routing-validated public edge addresses (IP-pinned;
 * ordinary DNS is never consulted during the probe), with SNI and hostname
 * verification against the public CA bundle, TLS >= 1.2, and then fetches
 * exactly one fixed path with a fresh nonce. Redirects are never followed.
 */
interface DomainProber
{
    /** @param list<string> $addresses */
    public function probe(string $hostname, array $addresses): ProbeResult;
}
