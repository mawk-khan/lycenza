<?php

namespace App\Domain\Communications\Application\Policy;

/**
 * Phase 5A.5 §19 -- the structured output of
 * App\Domain\Communications\Application\Policy\CommunicationChannelPolicyService::evaluate().
 * Contains no PII (no destination address, no message content) --
 * only enough to plan delivery, audit it, and summarize it in the UI.
 */
final class CommunicationPolicyDecision
{
    private function __construct(
        public readonly bool $allowed,
        public readonly CommunicationPolicyReason $reason,
    ) {}

    public static function allow(CommunicationPolicyReason $reason = CommunicationPolicyReason::Allowed): self
    {
        return new self(true, $reason);
    }

    public static function suppress(CommunicationPolicyReason $reason): self
    {
        return new self(false, $reason);
    }
}
