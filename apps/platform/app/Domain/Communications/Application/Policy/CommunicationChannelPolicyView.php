<?php

namespace App\Domain\Communications\Application\Policy;

/**
 * Phase 5A.5 §15/§16 -- the resolved policy for one School+channel,
 * whether it came from a persisted
 * App\Domain\Communications\Infrastructure\CommunicationChannelPolicy
 * override row or from
 * App\Domain\Communications\Application\Policy\CommunicationChannelPolicyService::defaultPolicy().
 * Callers (the evaluator, the settings UI) never need to know which.
 */
final class CommunicationChannelPolicyView
{
    public function __construct(
        public readonly bool $optionalAllowed,
        public readonly bool $requiredAllowed,
        public readonly bool $recipientCanOptOut,
        public readonly bool $isOverride,
    ) {}
}
