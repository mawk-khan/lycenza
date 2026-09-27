<?php

namespace App\Support\Domains;

/**
 * The ONE canonical hostname form (ADR 0054 section 3.1): lowercase ASCII
 * letters, digits and hyphens, one terminal dot removed, hostname only.
 * Every comparison -- claim uniqueness, the stored row, Host
 * classification and elevation lookup -- goes through this class, so a
 * second normalization can never disagree with it.
 *
 * - normalize(): the claim path. Refuses, with a closed reason, anything
 *   that is not a plain DNS hostname a School could own: schemes, ports,
 *   paths, queries, fragments, userinfo, whitespace, wildcards, IP
 *   literals, non-ASCII input and `xn--` A-labels (v1 is ASCII-only: the
 *   production runtime has no `intl`, section 3.2), single labels, bad
 *   label syntax or length, an all-numeric last label, and local/special
 *   names. Public suffixes and reserved hosts are HostnamePolicy's job.
 * - canonicalRequestHost(): the request path. The same folding and the
 *   same syntax rules, but it answers null instead of throwing; the
 *   local/special-name and IDN rules do not apply (a request Host is only
 *   ever compared for equality with configured or stored names).
 */
final class HostnameNormalizer
{
    public const MAX_LENGTH = 253;

    public const MAX_LABEL = 63;

    /** Names under these (or equal to them) are never a School's (ADR 0054 section 3.1). */
    public const LOCAL_SUFFIXES = [
        'localhost', 'local', 'localdomain', 'internal', 'lan', 'home.arpa', 'test',
        'example', 'invalid', 'onion', 'arpa', 'ddev.site',
    ];

    private const LABEL = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    /**
     * @throws HostnameRejected
     */
    public function normalize(string $input): string
    {
        // Anything that is not the bare name -- a URL, host:port, a path,
        // userinfo, whitespace or a wildcard -- is refused, never "cleaned".
        if ($input === '' || preg_match('#[\s:/?\#@*\[\]\\\\]#', $input) === 1) {
            throw new HostnameRejected(filter_var(trim($input, '[]'), FILTER_VALIDATE_IP) !== false ? HostnameRejected::IP_LITERAL : HostnameRejected::SYNTAX);
        }

        if (preg_match('/[^\x21-\x7e]/', $input) === 1) {
            throw new HostnameRejected(HostnameRejected::IDN);
        }

        $host = $this->fold($input);

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            throw new HostnameRejected(HostnameRejected::IP_LITERAL);
        }

        // v1 is ASCII-only: any A-label (`xn--`), even one that would
        // otherwise be malformed, is an IDN refusal (section 3.2).
        foreach (explode('.', $host) as $label) {
            if (str_starts_with($label, 'xn--')) {
                throw new HostnameRejected(HostnameRejected::IDN);
            }
        }

        if ($this->labels($host) === null) {
            throw new HostnameRejected(HostnameRejected::SYNTAX);
        }

        foreach (self::LOCAL_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                throw new HostnameRejected(HostnameRejected::LOCAL_NAME);
            }
        }

        return $host;
    }

    /** The canonical form of an incoming Host, or null when it can never match a configured or stored name. */
    public function canonicalRequestHost(string $host): ?string
    {
        if ($host === '' || preg_match('/[^\x21-\x7e]/', $host) === 1) {
            return null;
        }

        $host = $this->fold($host);

        return $this->labels($host) !== null ? $host : null;
    }

    /** True for an IPv4 or IPv6 literal (with or without brackets). */
    public function isIpLiteral(string $host): bool
    {
        return filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false;
    }

    /** Lowercase with one terminal dot removed: exact comparison with configured names (which may be single-label in development). */
    public function fold(string $host): string
    {
        $host = strtolower($host);

        return str_ends_with($host, '.') ? substr($host, 0, -1) : $host;
    }

    /**
     * @return list<string>|null the labels, or null when the syntax is invalid
     */
    private function labels(string $host): ?array
    {
        if ($host === '' || strlen($host) > self::MAX_LENGTH) {
            return null;
        }

        $labels = explode('.', $host);

        if (count($labels) < 2) {
            return null;
        }

        foreach ($labels as $label) {
            if (strlen($label) > self::MAX_LABEL || preg_match(self::LABEL, $label) !== 1) {
                return null;
            }
        }

        // The last label is alphabetic: never an all-numeric "TLD".
        return preg_match('/^[a-z]+$/', end($labels)) === 1 ? $labels : null;
    }
}
