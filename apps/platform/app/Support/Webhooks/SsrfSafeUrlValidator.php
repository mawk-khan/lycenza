<?php

namespace App\Support\Webhooks;

/**
 * Section 22. Rejects: unsupported schemes, credentials embedded in
 * the URL, unresolvable hosts, and hosts resolving to loopback/
 * private/link-local/reserved ranges (which covers the cloud metadata
 * address 169.254.169.254 -- it falls within link-local). Returns the
 * validated IP so the caller can pin the actual HTTP connection to it
 * (see DeliverWebhookJob) rather than re-resolving DNS a second time at
 * connect time, which narrows (but per the note below, does not
 * perfectly eliminate) the DNS-rebinding window between validation and
 * connection.
 *
 * Test-mode loopback exception is double-guarded exactly like
 * App\Http\Middleware\DevOnlySchoolHeaderResolver (config flag AND
 * environment(['local','testing'])) -- production webhook validation
 * is never weakened by this class alone; a misconfigured env var can't
 * enable it on its own.
 */
class SsrfSafeUrlValidator
{
    public function assertSafeAndResolve(string $url): string
    {
        $parsed = parse_url($url);

        if ($parsed === false || ! isset($parsed['scheme'], $parsed['host'])) {
            throw new SsrfRejectedException("Malformed webhook URL: {$url}");
        }

        if (! in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
            throw new SsrfRejectedException("Unsupported webhook URL scheme: {$parsed['scheme']}");
        }

        if (isset($parsed['user']) || isset($parsed['pass'])) {
            throw new SsrfRejectedException('Credentials embedded in the webhook URL are not allowed.');
        }

        $host = $parsed['host'];
        $ip = $this->resolve($host);

        if ($ip === null) {
            throw new SsrfRejectedException("Could not resolve webhook host: {$host}");
        }

        $overridden = $this->isLoopback($ip) && $this->testModeAllowsLoopback();

        if ($this->isBlockedIp($ip) && ! $overridden) {
            throw new SsrfRejectedException("Webhook host {$host} resolves to a blocked address range ({$ip}).");
        }

        return $ip;
    }

    private function resolve(string $host): ?string
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }

        $ip = gethostbyname($host);

        // gethostbyname() returns the input unchanged on failure to
        // resolve -- that is not a valid IP, so it's caught below.
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }

    private function isBlockedIp(string $ip): bool
    {
        // Excludes loopback (127.0.0.0/8, ::1), link-local (169.254.0.0/16
        // -- covers the cloud metadata address 169.254.169.254 -- and
        // fe80::/10), private ranges (10/8, 172.16/12, 192.168/16,
        // fc00::/7), and other reserved ranges (0.0.0.0/8, multicast, ...).
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }

    private function testModeAllowsLoopback(): bool
    {
        return (bool) config('webhooks.allow_loopback_for_tests')
            && app()->environment(['local', 'testing']);
    }

    /**
     * Deliberately narrower than isBlockedIp(): the test override
     * (section 29) exists ONLY so a local receiver process (127.0.0.1)
     * can be exercised in Proof B -- it must NOT also relax the check
     * for private/link-local/reserved ranges (e.g. the cloud metadata
     * address 169.254.169.254), even in local/testing.
     */
    private function isLoopback(string $ip): bool
    {
        return str_starts_with($ip, '127.') || $ip === '::1';
    }
}
