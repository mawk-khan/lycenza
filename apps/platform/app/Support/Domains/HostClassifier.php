<?php

namespace App\Support\Domains;

use App\Support\Domains\Probe\ProbeProof;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;

/**
 * ADR 0054 section 8.1: classifies the canonical request Host (after the
 * unchanged ADR 0050 trusted-proxy processing) into exactly one class.
 * Exact names only -- no regex, no wildcard, no "first School" fallback:
 *
 * 1. platform  the APP_URL host or a configured DOMAIN_PLATFORM_ALIASES entry;
 *              in local/testing with DOMAIN_ALLOW_DEVELOPMENT_HOSTS also
 *              localhost, 127.0.0.1, ::1 and *.ddev.site (never production);
 * 2. internal  a configured INTERNAL_HOSTS entry;
 * 3. health    a health path on an IP-literal Host; a health path on any
 *              other name is `not_served` (404) and costs no lookup, so
 *              liveness never depends on PostgreSQL (CLAUDE.md rule 55);
 * 4. with custom domains enabled, a syntactically valid name is looked up
 *    (DomainDirectory): `probe` for the probe path on a tls_pending, active
 *    or suspended row of an active School; `school` for the ACTIVE primary
 *    of an active School; `school_alias` for another ACTIVE row;
 * 5. unknown   everything else -- unknown names, pending, verified,
 *              tls_pending, suspended, revoked or expired domains and any
 *              domain of a non-active School look identical (421).
 */
final class HostClassifier
{
    public const HEALTH_PATHS = ['up', 'api/health/live', 'api/health/ready'];

    public const DEVELOPMENT_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]'];

    public const DEVELOPMENT_SUFFIX = '.ddev.site';

    public function __construct(
        private readonly Repository $config,
        private readonly HostnameNormalizer $names,
        private readonly DomainDirectory $directory,
    ) {}

    public function classify(Request $request): HostClassification
    {
        try {
            $raw = $request->getHost();
        } catch (SuspiciousOperationException) {
            return HostClassification::of(HostClassification::UNKNOWN);
        }

        $host = $this->names->fold($raw);
        $path = trim($request->path(), '/');

        if ($host === '') {
            return HostClassification::of(HostClassification::UNKNOWN);
        }

        $development = $this->developmentHostsAllowed() && $this->isDevelopmentHost($host);

        if ($development || in_array($host, $this->platformHosts(), true)) {
            return HostClassification::platform(development: $development);
        }

        if (in_array($host, $this->internalHosts(), true)) {
            return HostClassification::of(HostClassification::INTERNAL);
        }

        if (in_array($path, self::HEALTH_PATHS, true)) {
            return HostClassification::of($this->names->isIpLiteral($host) ? HostClassification::HEALTH : HostClassification::NOT_SERVED);
        }

        if (! (bool) $this->config->get('domains.enabled')) {
            return HostClassification::of(HostClassification::UNKNOWN);
        }

        $canonical = $this->names->canonicalRequestHost($host);
        $entry = $canonical !== null ? $this->directory->lookup($canonical) : null;

        if ($entry === null || ! $entry['school_active']) {
            return HostClassification::of(HostClassification::UNKNOWN);
        }

        if ($path === ProbeProof::PATH && in_array($entry['state'], DomainState::PROBE_ELIGIBLE, true)) {
            return HostClassification::probe($entry['hostname'], $entry['domain_id']);
        }

        if ($entry['state'] !== DomainState::Active->value) {
            return HostClassification::of(HostClassification::UNKNOWN);
        }

        if ($entry['is_primary']) {
            return HostClassification::school($entry['hostname'], $entry['school_id'], $entry['domain_id']);
        }

        return $entry['primary_hostname'] !== null
            ? HostClassification::schoolAlias($entry['hostname'], $entry['school_id'], $entry['domain_id'], $entry['primary_hostname'])
            : HostClassification::of(HostClassification::UNKNOWN);
    }

    /** @return list<string> */
    public function platformHosts(): array
    {
        $appHost = (string) parse_url((string) $this->config->get('app.url'), PHP_URL_HOST);

        return array_values(array_filter(array_map(
            fn (string $h) => $this->names->fold($h),
            [$appHost, ...(array) $this->config->get('domains.platform_aliases', [])],
        ), fn (string $h) => $h !== ''));
    }

    /** @return list<string> */
    public function internalHosts(): array
    {
        return array_values(array_map(fn (string $h) => $this->names->fold($h), (array) $this->config->get('domains.internal_hosts', [])));
    }

    /** The DevOnlySchoolHeaderResolver double guard: config flag AND local/testing. */
    public function developmentHostsAllowed(): bool
    {
        return (bool) $this->config->get('domains.allow_development_hosts') && app()->environment(['local', 'testing']);
    }

    private function isDevelopmentHost(string $host): bool
    {
        return in_array($host, self::DEVELOPMENT_HOSTS, true) || str_ends_with($host, self::DEVELOPMENT_SUFFIX);
    }
}
