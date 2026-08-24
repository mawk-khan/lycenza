<?php

namespace App\Domain\Communications\Application\Approval;

use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationRequirement;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Domain\Communications\Infrastructure\CommunicationApprovalPolicy;
use App\Models\School;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;

/**
 * Phase 5A.12 §50 -- the ONE authoritative "does this announcement
 * require approval, and why?" decision point. Every submit()/publish()/
 * schedule() call in
 * App\Domain\Communications\Application\Approval\CommunicationApprovalService
 * and App\Domain\Communications\Application\AnnouncementService goes
 * through this class -- never a scattered controller-branch re-check
 * (brief §50).
 *
 * Section 10/40: Emergency communications are evaluated OUTSIDE this
 * policy entirely -- always `required: false` -- they are governed by
 * the dedicated `communications.emergency` capability and Phase 5A.10's
 * own policy foundation, never blocked by a normal approval queue.
 */
class CommunicationApprovalPolicyService
{
    public function __construct(private readonly TenantContext $context) {}

    public function evaluate(School $school, CommunicationAnnouncement $announcement): CommunicationApprovalRequirement
    {
        if ($announcement->isEmergency()) {
            return new CommunicationApprovalRequirement(false, []);
        }

        return $this->context->withSchool($school, function () use ($school, $announcement) {
            $policy = CommunicationApprovalPolicy::query()->where('school_id', $school->id)->first();

            // Brief §8: no row means "approval not required for
            // anything" -- the mandatory safe default.
            if ($policy === null) {
                return new CommunicationApprovalRequirement(false, []);
            }

            $reasons = [];

            if ($policy->require_school_wide_approval && $announcement->audienceTypeEnum() === CommunicationAudienceType::SchoolWide) {
                $reasons[] = 'school_wide';
            }

            if ($policy->require_required_communication_approval && $announcement->requirementEnum() === CommunicationRequirement::Required) {
                $reasons[] = 'required_communication';
            }

            if ($policy->require_non_privileged_sender_approval && $announcement->createdBy !== null
                && ! app(CapabilityResolver::class)->canInSchool($announcement->createdBy, 'communications.manage', $school)) {
                $reasons[] = 'non_privileged_sender';
            }

            return new CommunicationApprovalRequirement($reasons !== [], $reasons);
        });
    }
}
