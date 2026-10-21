<?php

namespace App\Support\Approval;

use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaProof;
use LogicException;

/**
 * ORG.8A (ADR 0076 §58): the approval facts a domain adds to its own audit
 * event, in one vocabulary -- workflow, decision (requested / approved /
 * rejected), the participants and the decider by User id, the decider's
 * scope as the domain states it, and whether a fresh MFA code was verified
 * for the decision. Ids and closed codes only: never a payload, amount,
 * Student detail or free text. The domain's own event name is unchanged.
 */
final class ApprovalProvenance
{
    public const REQUESTED = 'requested';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /** @return array<string, mixed> */
    public static function metadata(ApprovalParticipants $participants, string $decision, ?User $decider = null, ?string $decisionScope = null, ?FreshMfaProof $mfa = null): array
    {
        if (! in_array($decision, [self::REQUESTED, self::APPROVED, self::REJECTED], true)) {
            throw new LogicException("Unknown approval decision '{$decision}'.");
        }
        if ($mfa !== null && ($decider === null || ! $mfa->isFor($decider->id))) {
            throw new LogicException('A fresh MFA proof is recorded only for the User who decided.');
        }

        return array_filter([
            'approvalWorkflow' => $participants->workflow,
            'approvalDecision' => $decision,
            'participants' => $participants->all(),
            'decidedByUserId' => $decider?->id,
            'decisionScope' => $decisionScope,
            'freshMfa' => $decider === null ? null : $mfa !== null,
        ], fn ($value) => $value !== null);
    }
}
