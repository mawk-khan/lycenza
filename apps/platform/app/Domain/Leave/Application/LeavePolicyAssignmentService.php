<?php

namespace App\Domain\Leave\Application;

use App\Domain\HR\Application\EmploymentCoverage;
use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Infrastructure\LeavePolicy;
use App\Domain\Leave\Infrastructure\LeavePolicyAssignment;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * HRX.1 (ADR 0065 §4.2): leave policies on an EmploymentRecord -- never a
 * User, role or EmployeeAssignment -- so a rehire starts fresh and E21-D9
 * follows the employment. Effective-dated, inclusive School-local dates.
 *
 * The database refuses an overlapping assignment of the same leave type on
 * one employment (under its own advisory lock) and lets an assignment only
 * be ended (shortened), never extended or repointed. The employment must be
 * planned or current over the period (HR's EmploymentCoverage, read FOR
 * SHARE in this transaction).
 */
class LeavePolicyAssignmentService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
        private readonly SchoolOperationalGuard $guard,
        private readonly EmploymentCoverage $coverage,
    ) {}

    public function assign(School $school, string $employmentRecordId, string $policyId, string $from, ?string $to, User $actor): LeavePolicyAssignment
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::MANAGE, $school);
        if ($to !== null && $to < $from) {
            throw LeaveException::invalid('LEAVE_ASSIGNMENT_DATES_INVALID', 'The assignment cannot end before it starts.');
        }

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $employmentRecordId, $policyId, $from, $to, $actor) {
            $this->guard->requireOperational($school->id);
            $policy = LeavePolicy::query()->where('school_id', $school->id)->sharedLock()->findOrFail($policyId);
            if ($policy->status !== 'active') {
                throw LeaveException::conflict('LEAVE_POLICY_RETIRED', 'A retired policy cannot be assigned.');
            }
            $this->requireEmployment($school, $employmentRecordId, $from, $to);

            try {
                $assignment = LeavePolicyAssignment::query()->create([
                    'school_id' => $school->id, 'employment_record_id' => $employmentRecordId, 'leave_policy_id' => $policy->id,
                    'leave_type_id' => $policy->leave_type_id, 'effective_from' => $from, 'effective_to' => $to, 'created_by_user_id' => $actor->id,
                ]);
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), 'leave_assignment_overlap')) {
                    throw LeaveException::conflict('LEAVE_ASSIGNMENT_OVERLAP', 'This employment already has a policy of this leave type for part of that period.');
                }
                throw $e;
            }
            $this->audit->school($school, 'leave.policy.assigned', actor: $actor, subject: $assignment, metadata: [
                'employmentRecordId' => $employmentRecordId, 'leavePolicyId' => $policy->id, 'effectiveFrom' => $from, 'effectiveTo' => $to,
            ]);

            return $assignment;
        }));
    }

    public function end(School $school, string $assignmentId, string $to, User $actor): LeavePolicyAssignment
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::MANAGE, $school);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $assignmentId, $to, $actor) {
            $this->guard->requireOperational($school->id);
            $assignment = LeavePolicyAssignment::query()->where('school_id', $school->id)->lockForUpdate()->findOrFail($assignmentId);
            if ($assignment->ended_at !== null) {
                throw LeaveException::conflict('LEAVE_ASSIGNMENT_ENDED', 'This assignment has already ended.');
            }
            $from = $assignment->effective_from->toDateString();
            $current = $assignment->effective_to?->toDateString();
            if ($to < $from || ($current !== null && $to > $current)) {
                throw LeaveException::invalid('LEAVE_ASSIGNMENT_DATES_INVALID', 'Ending may shorten an assignment, never extend it or end it before it starts.');
            }
            $assignment->forceFill(['effective_to' => $to, 'ended_at' => now(), 'ended_by_user_id' => $actor->id])->save();
            $this->audit->school($school, 'leave.policy.assignment_ended', actor: $actor, subject: $assignment, metadata: ['effectiveTo' => $to]);

            return $assignment;
        }));
    }

    private function requireEmployment(School $school, string $employmentRecordId, string $from, ?string $to): void
    {
        match ($this->coverage->holdRecord($school, $employmentRecordId, $from, $to)) {
            EmploymentCoverage::COVERED => null,
            EmploymentCoverage::RECORD_NOT_FOUND => throw new ModelNotFoundException('No query results for the employment.'),
            default => throw LeaveException::conflict('LEAVE_EMPLOYMENT_NOT_ELIGIBLE', 'The employment is not planned or current over that period.'),
        };
    }
}
