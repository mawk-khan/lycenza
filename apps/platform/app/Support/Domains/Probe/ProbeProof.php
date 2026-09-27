<?php

namespace App\Support\Domains\Probe;

use Illuminate\Contracts\Config\Repository;

/**
 * ADR 0054 section 6.2 step 4: the domain probe's proof that an edge routes
 * a hostname to THIS deployment. The body of
 * `GET /.well-known/lycenza-domain-probe?n=<nonce>` is
 * HMAC-SHA256(DOMAIN_PROBE_KEY, "<hostname>|<nonce>") in lowercase hex --
 * bound to the canonical hostname and a fresh single-use nonce, so a
 * captured answer proves nothing for another hostname or a later probe.
 * The key never leaves the process. Without a key there is no proof: the
 * endpoint answers 404 and every probe fails closed.
 */
final class ProbeProof
{
    public const PATH = '.well-known/lycenza-domain-probe';

    public const NONCE_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    public function __construct(private readonly Repository $config) {}

    public function configured(): bool
    {
        return $this->key() !== null;
    }

    public function for(string $hostname, string $nonce): ?string
    {
        $key = $this->key();

        if ($key === null || preg_match(self::NONCE_PATTERN, $nonce) !== 1) {
            return null;
        }

        return hash_hmac('sha256', $hostname.'|'.$nonce, $key);
    }

    public function matches(string $hostname, string $nonce, string $body): bool
    {
        $expected = $this->for($hostname, $nonce);

        return $expected !== null && hash_equals($expected, trim($body));
    }

    /** A fresh 256-bit nonce, 43 base64url characters. */
    public static function nonce(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function key(): ?string
    {
        $key = $this->config->get('domains.probe_key');

        return is_string($key) && trim($key) !== '' ? $key : null;
    }
}
