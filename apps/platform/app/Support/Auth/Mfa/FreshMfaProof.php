<?php

namespace App\Support\Auth\Mfa;

use Carbon\CarbonImmutable;

/**
 * ORG.8A (ADR 0076 §58): the fact that `FreshMfaRequirement` re-verified a
 * fresh code for this User just now. A domain records it as assurance
 * provenance (e.g. an approval decision) instead of trusting a flag.
 *
 * @internal Constructed ONLY by FreshMfaRequirement (pinned by
 *           ApprovalWorkflowRegistryTest); never from request input.
 */
final readonly class FreshMfaProof
{
    public function __construct(
        public string $userId,
        public CarbonImmutable $verifiedAt,
    ) {}

    public function isFor(string $userId): bool
    {
        return $this->userId === $userId;
    }
}
