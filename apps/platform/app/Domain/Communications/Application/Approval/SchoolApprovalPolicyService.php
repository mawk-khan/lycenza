<?php

namespace App\Domain\Communications\Application\Approval;

use App\Domain\Communications\Infrastructure\CommunicationApprovalPolicy;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;

/**
 * Phase 5A.12 §41 -- the sole write path for a School's approval
 * policy, mirroring
 * App\Domain\Communications\Application\Policy\SchoolChannelPolicyService's
 * exact validate -> write -> audit shape. Read access
 * (App\Domain\Communications\Http\Controllers\CommunicationChannelPolicyController::show())
 * goes through a direct query -- this class only handles writes.
 */
class SchoolApprovalPolicyService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    public function setPolicy(
        School $school,
        User $actor,
        bool $requireSchoolWideApproval,
        bool $requireRequiredCommunicationApproval,
        bool $requireNonPrivilegedSenderApproval,
    ): CommunicationApprovalPolicy {
        return $this->context->withSchool($school, function () use ($school, $actor, $requireSchoolWideApproval, $requireRequiredCommunicationApproval, $requireNonPrivilegedSenderApproval) {
            $policy = CommunicationApprovalPolicy::query()->updateOrCreate(
                ['school_id' => $school->id],
                [
                    'require_school_wide_approval' => $requireSchoolWideApproval,
                    'require_required_communication_approval' => $requireRequiredCommunicationApproval,
                    'require_non_privileged_sender_approval' => $requireNonPrivilegedSenderApproval,
                ],
            );

            $this->audit->school($school, 'communication.approval_policy.updated', actor: $actor, subject: $policy, metadata: [
                'requireSchoolWideApproval' => $requireSchoolWideApproval,
                'requireRequiredCommunicationApproval' => $requireRequiredCommunicationApproval,
                'requireNonPrivilegedSenderApproval' => $requireNonPrivilegedSenderApproval,
            ]);

            return $policy;
        });
    }
}
