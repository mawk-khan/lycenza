<?php

namespace App\Support\Domains\Probe;

use Carbon\CarbonImmutable;

/**
 * The bounded evidence of one TLS readiness probe (ADR 0054 section 6.2
 * step 5): a closed outcome and reason, and PUBLIC certificate facts only
 * (notAfter, the SHA-256 fingerprint of the leaf, the issuer's name). Never
 * a private key, a chain, or a transcript.
 */
final class ProbeResult
{
    public const PASS = 'pass';

    /** TLS itself is unacceptable: untrusted, expired, not yet valid, wrong name, protocol below 1.2, handshake failure. */
    public const TLS_INVALID = 'tls_invalid';

    /** TLS was fine but the answer did not prove this deployment (wrong/absent HMAC, redirect, 421, 404, ...). */
    public const PROOF_MISMATCH = 'proof_mismatch';

    /** Could not tell (connect timeout, refused, read timeout, no address). Never suspends by itself. */
    public const INDETERMINATE = 'indeterminate';

    public const OUTCOMES = [self::PASS, self::TLS_INVALID, self::PROOF_MISMATCH, self::INDETERMINATE];

    public const REASONS = [
        'ok', 'untrusted', 'expired', 'not_yet_valid', 'hostname_mismatch', 'protocol', 'handshake',
        'proof', 'status', 'connect', 'timeout', 'no_address',
    ];

    public function __construct(
        public readonly string $outcome,
        public readonly string $reason,
        public readonly ?CarbonImmutable $notAfter = null,
        public readonly ?string $fingerprint = null,
        public readonly ?string $issuer = null,
    ) {}

    public function passed(): bool
    {
        return $this->outcome === self::PASS;
    }

    /** A conclusive TLS or proof failure (counts toward suspension). */
    public function failed(): bool
    {
        return $this->outcome === self::TLS_INVALID || $this->outcome === self::PROOF_MISMATCH;
    }
}
