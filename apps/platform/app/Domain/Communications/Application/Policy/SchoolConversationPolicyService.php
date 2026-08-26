<?php

namespace App\Domain\Communications\Application\Policy;

use App\Domain\Communications\Infrastructure\CommunicationConversationPolicy;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;

/**
 * Phase 5D.1 §16 -- the sole write path for a School's explicit
 * private-conversation policy override, mirroring
 * App\Domain\Communications\Application\Policy\SchoolChannelPolicyService's
 * validate -> write -> audit shape exactly.
 */
class SchoolConversationPolicyService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    public function setPolicy(
        School $school,
        User $actor,
        bool $allowGuardianConversations,
        bool $allowStudentConversations,
    ): CommunicationConversationPolicy {
        return $this->context->withSchool($school, function () use ($school, $actor, $allowGuardianConversations, $allowStudentConversations) {
            $policy = CommunicationConversationPolicy::query()->updateOrCreate(
                ['school_id' => $school->id],
                [
                    'allow_guardian_conversations' => $allowGuardianConversations,
                    'allow_student_conversations' => $allowStudentConversations,
                ],
            );

            $this->audit->school($school, 'communication.conversation_policy.updated', actor: $actor, subject: $policy, metadata: [
                'allowGuardianConversations' => $allowGuardianConversations,
                'allowStudentConversations' => $allowStudentConversations,
            ]);

            return $policy;
        });
    }
}
