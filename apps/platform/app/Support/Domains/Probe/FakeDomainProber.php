<?php

namespace App\Support\Domains\Probe;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;

/**
 * DDEV/test DomainProber (ADR 0054 section 11), bound under the same double
 * guard as FakeDomainDnsResolver. No network and no TLS: by default it
 * behaves as a healthy edge that routes the hostname to this deployment --
 * it computes the answer the real endpoint would give (ProbeProof) and
 * checks it the way StreamDomainProber does, so a missing DOMAIN_PROBE_KEY
 * still fails closed. A test or the demo command can script any other
 * outcome per hostname. The certificate facts are synthetic.
 */
final class FakeDomainProber implements DomainProber
{
    private const PREFIX = 'domains-fake-probe:';

    public function __construct(private readonly Repository $store, private readonly ProbeProof $proof) {}

    public function probe(string $hostname, array $addresses): ProbeResult
    {
        if ($addresses === []) {
            return new ProbeResult(ProbeResult::INDETERMINATE, 'no_address');
        }

        $scenario = $this->store->get(self::PREFIX.$hostname);
        $scenario = is_array($scenario) ? $scenario : ['outcome' => ProbeResult::PASS];
        $notAfter = CarbonImmutable::now('UTC')->addDays((int) ($scenario['days'] ?? 60))->startOfSecond();
        $fingerprint = hash('sha256', 'fake-certificate:'.$hostname);
        $issuer = 'Lycenza Local Fake CA (not a real certificate)';

        if (($scenario['outcome'] ?? null) !== ProbeResult::PASS) {
            return new ProbeResult((string) $scenario['outcome'], (string) ($scenario['reason'] ?? 'handshake'), $notAfter, $fingerprint, $issuer);
        }

        $nonce = ProbeProof::nonce();
        $answer = (string) $this->proof->for($hostname, $nonce);

        return $this->proof->matches($hostname, $nonce, $answer)
            ? new ProbeResult(ProbeResult::PASS, 'ok', $notAfter, $fingerprint, $issuer)
            : new ProbeResult(ProbeResult::PROOF_MISMATCH, 'proof', $notAfter, $fingerprint, $issuer);
    }

    /** Script the next probes of one hostname (default: a healthy edge). */
    public function script(string $hostname, string $outcome, string $reason = 'ok', int $days = 60): void
    {
        $this->store->forever(self::PREFIX.$hostname, ['outcome' => $outcome, 'reason' => $reason, 'days' => $days]);
    }

    public function forget(string $hostname): void
    {
        $this->store->forget(self::PREFIX.$hostname);
    }
}
