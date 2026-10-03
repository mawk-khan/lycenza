<?php

namespace App\Domain\Leave\Application;

use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Infrastructure\LeavePolicy;
use App\Domain\Leave\Infrastructure\LeaveType;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * HRX.1 (ADR 0065 §4.2, §4.6): leave policies -- one leave type's terms in
 * integer half-day units. A policy is immutable once created (database
 * trigger); the only change is retiring it. A new version is created with
 * `supersedes` (same leave type), which retires the old one in the same
 * transaction, so every assignment and ledger entry keeps pointing at the
 * terms it was made under.
 *
 * No statutory entitlement is assumed: the numbers are the School's own
 * policy (HRX-L3 stays open).
 */
class LeavePolicyService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
        private readonly SchoolOperationalGuard $guard,
    ) {}

    /**
     * @param  array{name: string, annual_allocation_units: int, carry_forward_allowed: bool, carry_forward_cap_units: ?int, carry_forward_expiry_days: ?int}  $terms
     */
    public function create(School $school, string $leaveTypeId, array $terms, ?string $supersedesPolicyId, User $actor): LeavePolicy
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::CONFIGURE, $school);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $leaveTypeId, $terms, $supersedesPolicyId, $actor) {
            $this->guard->requireOperational($school->id);
            $type = LeaveType::query()->where('school_id', $school->id)->sharedLock()->findOrFail($leaveTypeId);
            $this->validate($type, $terms);

            $superseded = null;
            if ($supersedesPolicyId !== null) {
                $superseded = LeavePolicy::query()->where('school_id', $school->id)->lockForUpdate()->findOrFail($supersedesPolicyId);
                if ($superseded->leave_type_id !== $type->id || $superseded->status !== 'active') {
                    throw LeaveException::conflict('LEAVE_POLICY_NOT_SUPERSEDABLE', 'Only an active policy of the same leave type can be superseded.');
                }
            }

            $policy = LeavePolicy::query()->create([
                'school_id' => $school->id, 'leave_type_id' => $type->id, 'name' => trim($terms['name']),
                'annual_allocation_units' => $terms['annual_allocation_units'],
                'carry_forward_allowed' => $terms['carry_forward_allowed'],
                'carry_forward_cap_units' => $terms['carry_forward_allowed'] ? $terms['carry_forward_cap_units'] : null,
                'carry_forward_expiry_days' => $terms['carry_forward_allowed'] ? $terms['carry_forward_expiry_days'] : null,
                'status' => 'active', 'supersedes_policy_id' => $superseded?->id, 'created_by_user_id' => $actor->id,
            ]);
            if ($superseded !== null) {
                $this->retireLocked($school, $superseded, $actor);
            }
            $this->audit->school($school, 'leave.policy.created', actor: $actor, subject: $policy, metadata: [
                'leaveTypeId' => $type->id, 'annualAllocationUnits' => $policy->annual_allocation_units,
                'carryForwardAllowed' => $policy->carry_forward_allowed, 'carryForwardCapUnits' => $policy->carry_forward_cap_units,
                'carryForwardExpiryDays' => $policy->carry_forward_expiry_days, 'supersedesPolicyId' => $superseded?->id,
            ]);

            return $policy;
        }));
    }

    public function retire(School $school, string $policyId, User $actor): LeavePolicy
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::CONFIGURE, $school);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $policyId, $actor) {
            $this->guard->requireOperational($school->id);
            $policy = LeavePolicy::query()->where('school_id', $school->id)->lockForUpdate()->findOrFail($policyId);
            if ($policy->status === 'retired') {
                return $policy;
            }

            return $this->retireLocked($school, $policy, $actor);
        }));
    }

    private function retireLocked(School $school, LeavePolicy $policy, User $actor): LeavePolicy
    {
        $policy->forceFill(['status' => 'retired', 'retired_at' => now()])->save();
        $this->audit->school($school, 'leave.policy.retired', actor: $actor, subject: $policy);

        return $policy;
    }

    /** @param  array{annual_allocation_units: int, carry_forward_allowed: bool, carry_forward_cap_units: ?int, carry_forward_expiry_days: ?int}  $terms */
    private function validate(LeaveType $type, array $terms): void
    {
        if (! $type->tracks_balance && ($terms['annual_allocation_units'] !== 0 || $terms['carry_forward_allowed'])) {
            throw LeaveException::invalid('LEAVE_POLICY_UNTRACKED_TYPE', 'A leave type that does not track a balance has no allocation or carry-forward.');
        }
        $units = array_filter([$terms['annual_allocation_units'], $terms['carry_forward_allowed'] ? $terms['carry_forward_cap_units'] : null], fn ($u) => $u !== null);
        if (! $type->allows_half_day && array_filter($units, fn (int $u) => $u % 2 !== 0) !== []) {
            throw LeaveException::invalid('LEAVE_HALF_DAY_NOT_ALLOWED', 'This leave type is granted in whole days (even half-day units).');
        }
    }
}
