<?php

namespace App\Support\Approval;

use App\Models\User;

/**
 * ORG.8A (ADR 0076 §58): the one maker/checker rule for request/approve
 * workflows -- the decider never MATERIALLY took part in the item being
 * decided. Per item and per actor, from the participants the domain
 * supplies; never a role-name comparison and never satisfied by holding
 * both capabilities. It decides nothing else: the domain owns its state,
 * its refusal and its database backstop (which must be at least as strict).
 */
final class MakerCheckerPolicy
{
    /** The participation that disqualifies $decider, or null when they may decide. */
    public function disqualification(User $decider, ApprovalParticipants $participants): ?string
    {
        return $participants->participationOf($decider->id);
    }

    public function permits(User $decider, ApprovalParticipants $participants): bool
    {
        return $this->disqualification($decider, $participants) === null;
    }
}
