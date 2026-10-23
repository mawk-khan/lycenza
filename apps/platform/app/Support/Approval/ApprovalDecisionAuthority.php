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
 *
 * ORG.9C-2 (ADR 0076 §70): a direct-assured action on ONE Campus's record
 * passes that record's Campus: a whole-School grant, or an active grant on
 * exactly that active Campus for a Campus-adopted capability
 * (`CapabilityResolver::holdsInCampusNow`). Without a Campus -- every
 * approval workflow, and a School-wide record -- it stays whole-School.
 */
final class ApprovalDecisionAuthority
{
    public function __construct(
        private readonly SchoolOperationalGuard $operational,
        private readonly CapabilityResolver $capabilities,
    ) {}

    public function requireCurrent(User $decider, string $capability, School $school, ?string $campusId = null): void
    {
        $this->operational->requireOperational($school->id);

        $holds = $campusId === null
            ? $this->capabilities->holdsInSchoolNow($decider, $capability, $school)
            : $this->capabilities->holdsInCampusNow($decider, $capability, $school, $campusId);
        if (! $holds) {
            throw new AuthorizationException('This action is unauthorized.');
        }
    }
}
