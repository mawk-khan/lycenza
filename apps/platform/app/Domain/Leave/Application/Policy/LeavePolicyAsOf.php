<?php

namespace App\Domain\Leave\Application\Policy;

use App\Domain\Leave\Application\LeaveCapabilities;
use App\Domain\Leave\Infrastructure\LeavePolicy;
use App\Domain\Leave\Infrastructure\LeavePolicyAssignment;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;

/**
 * HR.R1-C22 (WO-5, D6-6, R1-03): the Leave policy that applied to one employment and leave type on a
 * date. A pure read over what is already immutable: policy versions never change (rule 133), and an
 * assignment is dated and never overlaps another of the same leave type (database-enforced). No
 * schema, no formula and no state change; the answer is the dated assignment's own version, never
 * today's policy.
 */
final class LeavePolicyAsOf
{
    use AuthorizesCapability;

    public function __construct(private readonly TenantContext $context) {}

    public function forEmployment(School $school, string $employmentRecordId, string $leaveTypeId, string $date, User $actor): ?LeavePolicy
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::VIEW, $school);

        return $this->context->withSchool($school, function () use ($school, $employmentRecordId, $leaveTypeId, $date) {
            $assignment = LeavePolicyAssignment::query()->where('school_id', $school->id)->where('employment_record_id', $employmentRecordId)
                ->where('leave_type_id', $leaveTypeId)->where('effective_from', '<=', $date)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))->first();

            return $assignment === null ? null : LeavePolicy::query()->where('school_id', $school->id)->find($assignment->leave_policy_id);
        });
    }
}
