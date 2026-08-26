<?php

namespace App\Domain\Communications\Application\Policy;

/**
 * Phase 5D.1 §16 -- the resolved private-conversation safeguarding
 * policy for one School, whether it came from a persisted
 * App\Domain\Communications\Infrastructure\CommunicationConversationPolicy
 * override row or from
 * App\Domain\Communications\Application\Policy\CommunicationConversationPolicyService::defaultPolicy().
 */
final class CommunicationConversationPolicyView
{
    public function __construct(
        public readonly bool $allowGuardianConversations,
        public readonly bool $allowStudentConversations,
        public readonly bool $isOverride,
    ) {}
}
