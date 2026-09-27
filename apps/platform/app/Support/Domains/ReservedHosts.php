<?php

namespace App\Support\Domains;

use Illuminate\Contracts\Config\Repository;

/**
 * ADR 0054 section 3.4: hostnames no School may claim. Exact names and
 * "this name and everything under it" suffixes, all owned by the
 * deployment -- never a pattern, never a School:
 *
 * - the platform host (APP_URL) and its registrable domain (every name
 *   under the platform's own domain), and each platform alias with its
 *   registrable domain;
 * - the internal hosts, and the edge CNAME target with every name under it;
 * - DOMAIN_RESERVED_HOSTS (exact) and DOMAIN_RESERVED_SUFFIXES (subtrees).
 */
final class ReservedHosts
{
    public function __construct(
        private readonly Repository $config,
        private readonly HostnameNormalizer $names,
        private readonly PublicSuffixPolicy $suffixes,
    ) {}

    public function isReserved(string $hostname): bool
    {
        if (in_array($hostname, $this->exact(), true)) {
            return true;
        }

        foreach ($this->subtrees() as $suffix) {
            if ($hostname === $suffix || str_ends_with($hostname, '.'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function exact(): array
    {
        return array_values(array_unique(array_map(fn (string $h) => $this->names->fold($h), [
            ...$this->platformHosts(),
            ...(array) $this->config->get('domains.internal_hosts', []),
            ...(array) $this->config->get('domains.reserved_hosts', []),
        ])));
    }

    /** @return list<string> */
    public function subtrees(): array
    {
        $subtrees = array_map(fn (string $h) => $this->names->fold($h), (array) $this->config->get('domains.reserved_suffixes', []));

        foreach ($this->platformHosts() as $host) {
            $canonical = $this->names->canonicalRequestHost($host);
            $registrable = $canonical !== null ? $this->suffixes->registrableDomain($canonical) : null;
            $subtrees[] = $registrable ?? $this->names->fold($host);
        }

        $edge = trim((string) $this->config->get('domains.edge.cname_target'));
        if ($edge !== '') {
            $subtrees[] = $this->names->fold($edge);
        }

        return array_values(array_unique(array_filter($subtrees, fn (string $s) => $s !== '')));
    }

    /** @return list<string> */
    private function platformHosts(): array
    {
        $host = (string) parse_url((string) $this->config->get('app.url'), PHP_URL_HOST);

        return array_values(array_filter([$host, ...(array) $this->config->get('domains.platform_aliases', [])], fn ($h) => is_string($h) && $h !== ''));
    }
}
