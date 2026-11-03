<?php

namespace App\Domain\Leave\Application;

use App\Domain\HR\Application\DepartmentReference;
use App\Domain\HR\Application\EmployeeCategoryReference;
use App\Domain\Leave\Application\Accrual\LeaveAccrualTiming;
use App\Domain\Leave\Application\Accrual\LeaveEntitlementMethod;
use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Application\Policy\LeaveEligibilityCriteria;
use App\Domain\Leave\Infrastructure\LeaveEligibilityRule;
use App\Domain\Leave\Infrastructure\LeavePolicy;
use App\Domain\Leave\Infrastructure\LeaveType;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * HRX.1 (ADR 0065 §4.2, §4.6): leave policies -- one leave type's terms in
 * integer half-day units. A policy is immutable once created (database
 * trigger); the only change is retiring it. A new version is created with
 * `supersedes` (same leave type), which retires the old one in the same
 * transaction, so every assignment and ledger entry keeps pointing at the
 * terms it was made under.
 *
 * HR.9 (ADR 0065 §28): a version also carries
 * - its request-timing rules (`min_notice_days`, `max_backdate_days`, in
 *   calendar days; `max_units_per_request`, in half-day units; NULL = none),
 *   frozen with it by the same trigger;
 * - its eligibility rules (staff type / Employee Category / Department),
 *   created only with the version and declared in `eligibility_rule_count`.
 * There is no draft status: an unsent version is the form's state, and
 * LeaveEligibilityService::previewDraft() shows its effect without writing.
 * To change a rule, create a new version that supersedes the old one.
 *
 * Eligibility is never ambiguous: a version's rules may not overlap the rules
 * of another ACTIVE version of the same leave type (checked here under the
 * leave type's eligibility lock; `trg_leave_eligibility_rules_guard` repeats
 * it). No precedence order exists -- an overlap is refused, never ranked.
 *
 * Lock order: School (operational guard), the leave type's eligibility lock,
 * the leave type row (FOR SHARE), the superseded version (FOR UPDATE), the
 * referenced Categories and Departments (FOR SHARE, HR readers).
 *
 * No statutory entitlement is assumed: the numbers are the School's own
 * policy (HRX-L3 stays open). Never a gender or marital rule (HR-L8).
 */
class LeavePolicyService
{
    use AuthorizesCapability;

    public const MAX_ELIGIBILITY_RULES = 20;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
        private readonly SchoolOperationalGuard $guard,
        private readonly EmployeeCategoryReference $categories,
        private readonly DepartmentReference $departments,
    ) {}

    /**
     * @param  array{name: string, annual_allocation_units: int, carry_forward_allowed: bool, carry_forward_cap_units: ?int, carry_forward_expiry_days: ?int, min_notice_days?: ?int, max_backdate_days?: ?int, max_units_per_request?: ?int, entitlement_method?: string, monthly_accrual_units?: ?int, accrual_timing?: ?string}  $terms
     * @param  list<LeaveEligibilityCriteria>  $rules
     */
    public function create(School $school, string $leaveTypeId, array $terms, ?string $supersedesPolicyId, User $actor, array $rules = []): LeavePolicy
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::CONFIGURE, $school);
        if (! Str::isUuid($leaveTypeId)) {
            throw new ModelNotFoundException('No query results for the leave type.');
        }
        // One spelling per id: the eligibility lock key must equal the trigger's (uuid::text, lower case).
        $leaveTypeId = strtolower($leaveTypeId);
        $terms += ['min_notice_days' => null, 'max_backdate_days' => null, 'max_units_per_request' => null,
            'entitlement_method' => LeaveEntitlementMethod::ANNUAL->value, 'monthly_accrual_units' => null, 'accrual_timing' => null];
        self::validateRuleSet($rules);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $leaveTypeId, $terms, $supersedesPolicyId, $actor, $rules) {
            $this->guard->requireOperational($school->id);
            LeaveLocks::eligibility($school, $leaveTypeId);
            $type = LeaveType::query()->where('school_id', $school->id)->sharedLock()->findOrFail($leaveTypeId);
            $this->validate($type, $terms);

            $superseded = null;
            if ($supersedesPolicyId !== null) {
                $superseded = LeavePolicy::query()->where('school_id', $school->id)->lockForUpdate()->findOrFail($supersedesPolicyId);
                if ($superseded->leave_type_id !== $type->id || $superseded->status !== 'active') {
                    throw LeaveException::conflict('LEAVE_POLICY_NOT_SUPERSEDABLE', 'Only an active policy of the same leave type can be superseded.');
                }
            }
            $this->requireReferences($school, $rules);
            if ($this->overlappingPolicyId($school, $type->id, $rules, $superseded?->id) !== null) {
                throw self::overlap();
            }

            // The superseded version retires first, so its rules are inert before the new rules are checked.
            if ($superseded !== null) {
                $this->retireLocked($school, $superseded, $actor);
            }
            $policy = LeavePolicy::query()->create([
                'school_id' => $school->id, 'leave_type_id' => $type->id, 'name' => trim($terms['name']),
                'annual_allocation_units' => $terms['annual_allocation_units'],
                'carry_forward_allowed' => $terms['carry_forward_allowed'],
                'carry_forward_cap_units' => $terms['carry_forward_allowed'] ? $terms['carry_forward_cap_units'] : null,
                'carry_forward_expiry_days' => $terms['carry_forward_allowed'] ? $terms['carry_forward_expiry_days'] : null,
                'min_notice_days' => $terms['min_notice_days'], 'max_backdate_days' => $terms['max_backdate_days'],
                'max_units_per_request' => $terms['max_units_per_request'], 'eligibility_rule_count' => count($rules),
                'entitlement_method' => $terms['entitlement_method'], 'monthly_accrual_units' => $terms['monthly_accrual_units'],
                'accrual_timing' => $terms['accrual_timing'],
                'status' => 'active', 'supersedes_policy_id' => $superseded?->id, 'created_by_user_id' => $actor->id,
            ]);
            $created = [];
            foreach ($rules as $rule) {
                try {
                    $created[] = LeaveEligibilityRule::query()->create([
                        'school_id' => $school->id, 'leave_policy_id' => $policy->id, 'leave_type_id' => $type->id,
                        'staff_type' => $rule->staffType?->value, 'employee_category_id' => $rule->employeeCategoryId,
                        'department_id' => $rule->departmentId, 'created_by_user_id' => $actor->id,
                    ]);
                } catch (QueryException $e) {
                    if (str_contains($e->getMessage(), 'leave_eligibility_overlap')) {
                        throw self::overlap();
                    }
                    throw $e;
                }
            }

            $this->audit->school($school, 'leave.policy.created', actor: $actor, subject: $policy, metadata: [
                'leaveTypeId' => $type->id, 'annualAllocationUnits' => $policy->annual_allocation_units,
                'carryForwardAllowed' => $policy->carry_forward_allowed, 'carryForwardCapUnits' => $policy->carry_forward_cap_units,
                'carryForwardExpiryDays' => $policy->carry_forward_expiry_days, 'supersedesPolicyId' => $superseded?->id,
                'minNoticeDays' => $policy->min_notice_days, 'maxBackdateDays' => $policy->max_backdate_days,
                'maxUnitsPerRequest' => $policy->max_units_per_request,
                'entitlementMethod' => $policy->entitlement_method, 'monthlyAccrualUnits' => $policy->monthly_accrual_units,
                'accrualTiming' => $policy->accrual_timing,
                'eligibilityRules' => array_map(fn (LeaveEligibilityRule $r) => ['id' => $r->id, 'staffType' => $r->staff_type,
                    'employeeCategoryId' => $r->employee_category_id, 'departmentId' => $r->department_id], $created),
            ]);

            return $policy;
        }));
    }

    public function retire(School $school, string $policyId, User $actor): LeavePolicy
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::CONFIGURE, $school);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $policyId, $actor) {
            $this->guard->requireOperational($school->id);
            $typeId = LeavePolicy::query()->where('school_id', $school->id)->whereKey($policyId)->value('leave_type_id')
                ?? throw new ModelNotFoundException('No query results for the leave policy.');
            LeaveLocks::eligibility($school, $typeId);
            $policy = LeavePolicy::query()->where('school_id', $school->id)->lockForUpdate()->findOrFail($policyId);
            if ($policy->status === 'retired') {
                return $policy;
            }

            return $this->retireLocked($school, $policy, $actor);
        }));
    }

    /**
     * The first ACTIVE version (other than $except) of the leave type whose
     * rules an employment could match together with one of $rules, or null.
     * Callers hold the eligibility lock when the answer must stay true.
     *
     * @param  list<LeaveEligibilityCriteria>  $rules
     */
    public function overlappingPolicyId(School $school, string $leaveTypeId, array $rules, ?string $except): ?string
    {
        if ($rules === []) {
            return null;
        }
        $existing = LeaveEligibilityRule::query()->where('leave_eligibility_rules.school_id', $school->id)
            ->where('leave_eligibility_rules.leave_type_id', $leaveTypeId)
            ->join('leave_policies as p', fn ($j) => $j->on('p.id', '=', 'leave_eligibility_rules.leave_policy_id')->on('p.school_id', '=', 'leave_eligibility_rules.school_id'))
            ->where('p.status', 'active')
            ->when($except !== null, fn ($q) => $q->where('leave_eligibility_rules.leave_policy_id', '<>', $except))
            ->orderBy('leave_eligibility_rules.leave_policy_id')->orderBy('leave_eligibility_rules.id')
            ->get(['leave_eligibility_rules.*']);
        foreach ($existing as $other) {
            $criteria = LeaveEligibilityCriteria::ofRule($other);
            foreach ($rules as $rule) {
                if ($rule->overlaps($criteria)) {
                    return $other->leave_policy_id;
                }
            }
        }

        return null;
    }

    /**
     * Shape checks that need no database: at most MAX_ELIGIBILITY_RULES, no
     * duplicate rule in one version.
     *
     * @param  list<LeaveEligibilityCriteria>  $rules
     */
    public static function validateRuleSet(array $rules): void
    {
        if (count($rules) > self::MAX_ELIGIBILITY_RULES) {
            throw LeaveException::invalid('LEAVE_ELIGIBILITY_RULES_TOO_MANY', 'A policy version holds at most '.self::MAX_ELIGIBILITY_RULES.' eligibility rules.');
        }
        $keys = array_map(fn (LeaveEligibilityCriteria $r) => $r->key(), $rules);
        if (count($keys) !== count(array_unique($keys))) {
            throw LeaveException::invalid('LEAVE_ELIGIBILITY_RULE_DUPLICATE', 'Each eligibility rule of a version must differ.');
        }
    }

    private static function overlap(): LeaveException
    {
        return LeaveException::conflict('LEAVE_ELIGIBILITY_OVERLAP', 'An employment could match these eligibility rules and another active policy of this leave type. Supersede or retire that policy, or narrow the rules.');
    }

    /** @param  list<LeaveEligibilityCriteria>  $rules */
    private function requireReferences(School $school, array $rules): void
    {
        foreach ($rules as $rule) {
            if (($rule->employeeCategoryId !== null && ! $this->categories->lockActive($school, $rule->employeeCategoryId))
                || ($rule->departmentId !== null && ! $this->departments->lockActive($school, $rule->departmentId))) {
                throw LeaveException::invalid('LEAVE_ELIGIBILITY_REFERENCE_INVALID', 'An eligibility rule names an Employee Category or Department that is not active in this School.');
            }
        }
    }

    private function retireLocked(School $school, LeavePolicy $policy, User $actor): LeavePolicy
    {
        $policy->forceFill(['status' => 'retired', 'retired_at' => now()])->save();
        $this->audit->school($school, 'leave.policy.retired', actor: $actor, subject: $policy);

        return $policy;
    }

    /** @param  array{annual_allocation_units: int, carry_forward_allowed: bool, carry_forward_cap_units: ?int, carry_forward_expiry_days: ?int, min_notice_days: ?int, max_backdate_days: ?int, max_units_per_request: ?int, entitlement_method: string, monthly_accrual_units: ?int, accrual_timing: ?string}  $terms */
    private function validate(LeaveType $type, array $terms): void
    {
        // HR.10 (ADR 0065 §29.2): annual and monthly are exclusive alternatives, never added together.
        $method = LeaveEntitlementMethod::tryFrom((string) $terms['entitlement_method']);
        if ($method === null
            || ($method === LeaveEntitlementMethod::ANNUAL && ($terms['monthly_accrual_units'] !== null || $terms['accrual_timing'] !== null))
            || ($method === LeaveEntitlementMethod::MONTHLY && ($terms['annual_allocation_units'] !== 0 || $terms['monthly_accrual_units'] === null
                || $terms['monthly_accrual_units'] < 1 || $terms['monthly_accrual_units'] > 62 || LeaveAccrualTiming::tryFrom((string) $terms['accrual_timing']) === null))) {
            throw LeaveException::invalid('LEAVE_ENTITLEMENT_METHOD_INVALID', 'An annual version has an annual allocation only; a monthly version has 0 annual units, 1-62 half-day units a month and a posting timing.');
        }
        if ($method === LeaveEntitlementMethod::MONTHLY && ! $type->tracks_balance) {
            throw LeaveException::invalid('LEAVE_POLICY_UNTRACKED_TYPE', 'A leave type that does not track a balance has no accrual.');
        }
        if (! $type->tracks_balance && ($terms['annual_allocation_units'] !== 0 || $terms['carry_forward_allowed'])) {
            throw LeaveException::invalid('LEAVE_POLICY_UNTRACKED_TYPE', 'A leave type that does not track a balance has no allocation or carry-forward.');
        }
        $units = array_filter([$terms['annual_allocation_units'], $terms['carry_forward_allowed'] ? $terms['carry_forward_cap_units'] : null, $terms['max_units_per_request'], $terms['monthly_accrual_units']], fn ($u) => $u !== null);
        if (! $type->allows_half_day && array_filter($units, fn (int $u) => $u % 2 !== 0) !== []) {
            throw LeaveException::invalid('LEAVE_HALF_DAY_NOT_ALLOWED', 'This leave type is granted in whole days (even half-day units).');
        }
        if (($terms['min_notice_days'] !== null && ($terms['min_notice_days'] < 1 || $terms['min_notice_days'] > 366))
            || ($terms['max_backdate_days'] !== null && ($terms['max_backdate_days'] < 0 || $terms['max_backdate_days'] > 366))
            || ($terms['max_units_per_request'] !== null && ($terms['max_units_per_request'] < 1 || $terms['max_units_per_request'] > 734))) {
            throw LeaveException::invalid('LEAVE_POLICY_RULE_INVALID', 'Notice is 1-366 calendar days, backdating 0-366 calendar days and a request at most 734 half-day units.');
        }
    }
}
