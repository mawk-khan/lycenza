<?php

namespace App\Support\Operations;

/**
 * Phase 0O.4A: one operator verification result -- a stable code, a
 * status, and at most a short non-sensitive note (never a credential,
 * payload, filename or personal data).
 */
final class CheckResult
{
    public const PASS = 'PASS';

    public const FAIL = 'FAIL';

    /** Cannot be proved here; needs operator/provider evidence. */
    public const EVIDENCE = 'OPERATOR_EVIDENCE_REQUIRED';

    public function __construct(
        public readonly string $code,
        public readonly string $status,
        public readonly string $note = '',
    ) {}

    public static function of(string $code, bool $ok, string $note = ''): self
    {
        return new self($code, $ok ? self::PASS : self::FAIL, $note);
    }

    public function failed(): bool
    {
        return $this->status === self::FAIL;
    }
}
