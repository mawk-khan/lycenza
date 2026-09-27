<?php

namespace App\Support\Domains;

use App\Support\Domains\Dns\DomainDnsResolver;
use Illuminate\Contracts\Config\Repository;

/**
 * ADR 0054 section 5: routing readiness, a check SEPARATE from ownership (a
 * record pointing at Lycenza is never ownership proof). It passes only when
 *
 * - the hostname's CNAME chain reaches DOMAIN_EDGE_CNAME_TARGET, or
 * - its complete A/AAAA answer is non-empty and a subset of
 *   DOMAIN_EDGE_ADDRESSES (an ALIAS/ANAME appears as A/AAAA),
 *
 * AND every resolved address is public (PublicAddress): one private,
 * loopback, link-local, CGNAT, multicast, documentation or reserved answer
 * fails the check, whatever else is true. No IP address lives in code; the
 * edge is deployment configuration only.
 *
 * `addresses` (on pass) are the validated public edge addresses the TLS
 * probe pins its connection to.
 */
final class RoutingVerifier
{
    public const PASS = 'pass';

    public const FAIL = 'fail';

    public const INDETERMINATE = 'indeterminate';

    public function __construct(
        private readonly DomainDnsResolver $dns,
        private readonly Repository $config,
        private readonly HostnameNormalizer $names,
    ) {}

    /** @return array{outcome: string, reason: string|null, addresses: list<string>} */
    public function check(string $hostname): array
    {
        $lookup = $this->dns->addresses($hostname);

        if ($lookup->isIndeterminate()) {
            return ['outcome' => self::INDETERMINATE, 'reason' => $lookup->reason, 'addresses' => []];
        }

        if ($lookup->isAbsent() || $lookup->addresses === []) {
            return ['outcome' => self::FAIL, 'reason' => 'no_address', 'addresses' => []];
        }

        $addresses = [];
        foreach ($lookup->addresses as $address) {
            $canonical = PublicAddress::canonical($address);

            if ($canonical === null || ! PublicAddress::isPublic($canonical)) {
                return ['outcome' => self::FAIL, 'reason' => 'non_public_address', 'addresses' => []];
            }

            $addresses[] = $canonical;
        }

        $target = trim((string) $this->config->get('domains.edge.cname_target'));
        if ($target !== '' && in_array($this->names->fold($target), array_slice($lookup->chain, 1), true)) {
            return ['outcome' => self::PASS, 'reason' => 'cname', 'addresses' => $addresses];
        }

        $edge = array_values(array_filter(array_map(
            fn ($a) => PublicAddress::canonical((string) $a),
            (array) $this->config->get('domains.edge.addresses', []),
        )));

        if ($edge !== [] && array_diff($addresses, $edge) === []) {
            return ['outcome' => self::PASS, 'reason' => 'addresses', 'addresses' => $addresses];
        }

        return ['outcome' => self::FAIL, 'reason' => 'not_edge', 'addresses' => []];
    }
}
