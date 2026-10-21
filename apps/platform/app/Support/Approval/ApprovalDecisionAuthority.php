<?php

namespace App\Support\Approval;

use App\Models\School;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\SchoolOperationalGuard;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * ORG.8A (ADR 0076 §58): the decider's CURRENT authority, re-checked inside
 * the decision transaction after the domain has locked its item -- the
 * School is operational (FOR SHARE) and the decider holds the decision
 * capability whole-School NOW, read fresh with the module entitlement
 * (`CapabilityResolver::holdsInSchoolNow`), never the request-time or cached
 * answer. The domain's earlier capability check stays the cheap floor; this
 * is the decision-time truth. Refuses with the ordinary 403.
 */
final class ApprovalDecisionAuthority
{
    public function __construct(
        private readonly SchoolOperationalGuard $operational,
        private readonly CapabilityResolver $capabilities,
    ) {}

    public function requireCurrent(User $decider, string $capability, School $school): void
    {
        $this->operational->requireOperational($school->id);

        if (! $this->capabilities->holdsInSchoolNow($decider, $capability, $school)) {
            throw new AuthorizationException('This action is unauthorized.');
        }
    }
}
