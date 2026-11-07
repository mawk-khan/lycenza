<?php

namespace App\Domain\Leave\Application;

use App\Domain\HR\Application\EmploymentCoverage;
use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Application\Policy\LeaveEligibilityCriteria;
use App\Domain\Leave\Application\Policy\LeaveEligibilityOutcome;
use App\Domain\Leave\Application\Policy\LeaveEligibilityResolver;
use App\Domain\Leave\Application\Policy\LeaveEligibilityRow;
use App\Domain\Leave\Application\Policy\LeaveEligibilityVersion;
use App\Domain\Leave\Infrastructure\LeaveEligibilityRun;
use App\Domain\Leave\Infrastructure\LeavePolicy;
use App\Domain\Leave\Infrastructure\LeavePolicyAssignment;
use App\Domain\Leave\Infrastructure\LeaveType;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * HR.9 (ADR 0065 §28.4-§28.6): leave policy eligibility.
 *
 * - applicability() (`hr.leave.view`): for one leave type and date, every
 *   employment's closed outcome -- the "assigned / unassigned staff" view
 *   (D12-05) and the per-employment explanation (facts, version, rule).
 * - previewDraft() (`hr.leave.configure`): the same evaluation for a version
 *   that does not exist yet (the draft replaces the version it would
 *   supersede). Writes nothing; reports a rule overlap the create would
 *   refuse.
 * - executeRun() (`hr.leave.manage`, the assignment capability): assigns the
 *   resolved version to every ELIGIBLE employment, effective from the as-of
 *   date to the employment's end (or the day before a later assignment). It
 *   only adds: an assigned, ambiguous, unmatched, ineligible or own
 *   employment is skipped, and nothing is ended, extended or repointed.
 *   Re-running it assigns nothing twice (a covered employment is ASSIGNED),
 *   and two runs serialize on the leave type's eligibility lock.
 *
 * Eligibility follows the workforce facts ON THE AS-OF DATE (HR.1A dated
 * terms and primary assignment). A later change of staff type, Category or
 * Department never moves an existing assignment; applicability() shows it
 * as `assignedMatchesRules = false` for an administrator to act on
 * explicitly (end the assignment, then assign).
 *
 * Lock order (executeRun): School (operational guard), the eligibility lock,
 * the leave type (FOR SHARE), then per employment ascending: HR rows (FOR
 * SHARE, EmploymentCoverage) and the assignment key -- ADR 0065 §23.10.
 * Once every candidate's HR rows are held, the run resolves once more and
 * keeps only rows whose answer is unchanged, so a terms or placement change
 * that committed just before the locks is never assigned on stale facts.
 * Ids are lower-cased first: one UUID, one lock key (security review).
 */
class LeaveEligibilityService
{
    use AuthorizesCapability;

    public const string DRAFT_POLICY_ID = 'draft';

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
        private readonly SchoolOperationalGuard $guard,
        private readonly LeaveEligibilityResolver $resolver,
        private readonly EmploymentCoverage $coverage,
        private readonly LeavePolicyService $policies,
    ) {}

    /**
     * HR.10 (ADR 0065 §29.6): the COMPLETE population is evaluated (counts
     * are whole); the rows are one server page, optionally of one outcome.
     *
     * @return array{leaveTypeId: string, date: string, versions: list<array<string, mixed>>, rows: list<array<string, mixed>>, counts: array<string, int>, total: int, page: int, perPage: int}
     */
    public function applicability(School $school, string $leaveTypeId, string $date, User $actor, ?string $outcome = null, int $page = 1, int $perPage = 200): array
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::VIEW, $school);
        $date = self::day($date);
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 500));

        return $this->context->withSchool($school, function () use ($school, $leaveTypeId, $date, $actor, $outcome, $page, $perPage) {
            $type = $this->type($school, $leaveTypeId);
            $versions = $this->resolver->activeVersions($school, $type->id);
            $result = $this->result($type->id, $date, $versions, $this->resolver->resolve($school, $type->id, $date, $versions, $actor));
            $rows = $outcome === null ? $result['rows'] : array_values(array_filter($result['rows'], fn (array $r) => $r['outcome'] === $outcome));

            return ['rows' => array_slice($rows, ($page - 1) * $perPage, $perPage), 'total' => count($rows), 'page' => $page, 'perPage' => $perPage] + $result;
        });
    }

    /**
     * @param  list<LeaveEligibilityCriteria>  $rules
     * @return array{leaveTypeId: string, date: string, versions: list<array<string, mixed>>, rows: list<array<string, mixed>>, counts: array<string, int>, overlappingPolicyId: ?string}
     */
    public function previewDraft(School $school, string $leaveTypeId, string $date, ?string $supersedesPolicyId, string $name, int $annualAllocationUnits, array $rules, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::CONFIGURE, $school);
        $date = self::day($date);
        LeavePolicyService::validateRuleSet($rules);

        return $this->context->withSchool($school, function () use ($school, $leaveTypeId, $date, $supersedesPolicyId, $name, $annualAllocationUnits, $rules, $actor) {
            $type = $this->type($school, $leaveTypeId);
            if ($supersedesPolicyId !== null && ! LeavePolicy::query()->where('school_id', $school->id)->where('leave_type_id', $type->id)->whereKey($supersedesPolicyId)->exists()) {
                throw new ModelNotFoundException('No query results for the leave policy.');
            }
            $versions = array_values(array_filter($this->resolver->activeVersions($school, $type->id), fn (LeaveEligibilityVersion $v) => $v->policyId !== $supersedesPolicyId));
            $overlap = $this->policies->overlappingPolicyId($school, $type->id, $rules, $supersedesPolicyId);
            $versions[] = new LeaveEligibilityVersion(self::DRAFT_POLICY_ID, trim($name), $annualAllocationUnits, array_map(fn (LeaveEligibilityCriteria $c) => [null, $c], $rules));

            return $this->result($type->id, $date, $versions, $this->resolver->resolve($school, $type->id, $date, $versions, $actor)) + ['overlappingPolicyId' => $overlap];
        });
    }

    public function executeRun(School $school, string $leaveTypeId, string $asOf, User $actor): LeaveEligibilityRun
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::MANAGE, $school);
        $asOf = self::day($asOf);
        if (! Str::isUuid($leaveTypeId)) {
            throw new ModelNotFoundException('No query results for the leave type.');
        }
        // One spelling per id: the eligibility lock key must equal every other writer's and the trigger's.
        $leaveTypeId = strtolower($leaveTypeId);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $leaveTypeId, $asOf, $actor) {
            $this->guard->requireOperational($school->id);
            LeaveLocks::eligibility($school, $leaveTypeId);
            $type = LeaveType::query()->where('school_id', $school->id)->sharedLock()->findOrFail($leaveTypeId);
            if ($type->status !== 'active') {
                throw LeaveException::conflict('LEAVE_TYPE_INACTIVE', 'This leave type is inactive.');
            }
            $versions = $this->resolver->activeVersions($school, $type->id);
            $rows = $this->resolver->resolve($school, $type->id, $asOf, $versions, $actor);
            $outcomes = array_count_values(array_map(fn (LeaveEligibilityRow $r) => $r->outcome->value, $rows));

            $candidates = array_values(array_filter($rows, fn (LeaveEligibilityRow $r) => $r->outcome === LeaveEligibilityOutcome::ELIGIBLE));
            usort($candidates, fn (LeaveEligibilityRow $a, LeaveEligibilityRow $b) => strcmp($a->employmentRecordId, $b->employmentRecordId));
            $plan = [];
            $skipped = count($rows) - count($candidates) - ($outcomes[LeaveEligibilityOutcome::ASSIGNED->value] ?? 0);
            foreach ($candidates as $row) {
                // HR rows FOR SHARE, then the assignment key; re-checked under both.
                if ($this->coverage->holdRecord($school, $row->employmentRecordId, $asOf, $row->assignmentEndsOn) !== EmploymentCoverage::COVERED
                    || ($row->assignmentEndsOn !== null && $row->assignmentEndsOn < $asOf)) {
                    $skipped++;

                    continue;
                }
                LeaveLocks::assignment($school, $row->employmentRecordId, $type->id);
                $overlaps = LeavePolicyAssignment::query()->where('school_id', $school->id)->where('employment_record_id', $row->employmentRecordId)
                    ->where('leave_type_id', $type->id)->where('effective_from', '<=', $row->assignmentEndsOn ?? '9999-12-31')
                    ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $asOf))->exists();
                if ($overlaps) {
                    $skipped++;

                    continue;
                }
                $plan[] = $row;
            }
            // Under the HR row locks a concurrent terms or placement change has committed (or waits for us):
            // re-resolve once and keep only rows whose answer is unchanged (same version, same rule).
            if ($plan !== []) {
                $fresh = collect($this->resolver->resolve($school, $type->id, $asOf, $versions, $actor))->keyBy('employmentRecordId');
                $plan = array_values(array_filter($plan, function (LeaveEligibilityRow $row) use ($fresh, &$skipped): bool {
                    $now = $fresh->get($row->employmentRecordId);
                    $same = $now instanceof LeaveEligibilityRow && $now->outcome === LeaveEligibilityOutcome::ELIGIBLE
                        && $now->policyId === $row->policyId && $now->ruleId === $row->ruleId && $now->assignmentEndsOn === $row->assignmentEndsOn;
                    $skipped += $same ? 0 : 1;

                    return $same;
                }));
            }

            $run = LeaveEligibilityRun::query()->create([
                'school_id' => $school->id, 'leave_type_id' => $type->id, 'as_of' => $asOf, 'executed_by_user_id' => $actor->id,
                'assigned_count' => count($plan), 'skipped_count' => $skipped, 'executed_at' => now(),
            ]);
            foreach ($plan as $row) {
                try {
                    $assignment = LeavePolicyAssignment::query()->forceCreate([
                        'school_id' => $school->id, 'employment_record_id' => $row->employmentRecordId, 'leave_policy_id' => $row->policyId,
                        'leave_type_id' => $type->id, 'effective_from' => $asOf, 'effective_to' => $row->assignmentEndsOn,
                        'created_by_user_id' => $actor->id, 'eligibility_run_id' => $run->id, 'eligibility_rule_id' => $row->ruleId,
                    ]);
                } catch (QueryException $e) {
                    if (str_contains($e->getMessage(), 'leave_assignment_overlap')) {
                        throw LeaveException::conflict('LEAVE_ASSIGNMENT_OVERLAP', 'An assignment of this leave type changed while the run was resolving; run it again.');
                    }
                    throw $e;
                }
                $this->audit->school($school, 'leave.policy.assigned', actor: $actor, subject: $assignment, metadata: [
                    'employmentRecordId' => $row->employmentRecordId, 'leavePolicyId' => $row->policyId, 'effectiveFrom' => $asOf,
                    'effectiveTo' => $row->assignmentEndsOn, 'source' => 'eligibility_run', 'eligibilityRunId' => $run->id, 'eligibilityRuleId' => $row->ruleId,
                ]);
            }
            $this->audit->school($school, 'leave.eligibility_run.executed', actor: $actor, subject: $run, metadata: [
                'leaveTypeId' => $type->id, 'asOf' => $asOf, 'assigned' => count($plan), 'skipped' => $skipped, 'outcomes' => $outcomes,
            ]);

            return $run;
        }));
    }

    private function type(School $school, string $leaveTypeId): LeaveType
    {
        if (! Str::isUuid($leaveTypeId)) {
            throw new ModelNotFoundException('No query results for the leave type.');
        }

        return LeaveType::query()->where('school_id', $school->id)->findOrFail($leaveTypeId);
    }

    /**
     * @param  list<LeaveEligibilityVersion>  $versions
     * @param  list<LeaveEligibilityRow>  $rows
     * @return array{leaveTypeId: string, date: string, versions: list<array<string, mixed>>, rows: list<array<string, mixed>>, counts: array<string, int>}
     */
    private function result(string $leaveTypeId, string $date, array $versions, array $rows): array
    {
        $counts = array_fill_keys(array_map(fn (LeaveEligibilityOutcome $o) => $o->value, LeaveEligibilityOutcome::cases()), 0);
        foreach ($rows as $row) {
            $counts[$row->outcome->value]++;
        }

        return [
            'leaveTypeId' => $leaveTypeId,
            'date' => $date,
            'versions' => array_map(fn (LeaveEligibilityVersion $v) => [
                'policyId' => $v->policyId, 'name' => $v->name, 'annualAllocationUnits' => $v->annualAllocationUnits,
                'rules' => array_map(fn (array $r) => ['id' => $r[0]] + $r[1]->toArray(), $v->rules),
            ], $versions),
            'rows' => array_map(fn (LeaveEligibilityRow $r) => $r->toArray(), $rows),
            'counts' => $counts,
        ];
    }

    private static function day(string $date): string
    {
        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        } catch (\Throwable) {
            $parsed = null;
        }
        if (! $parsed instanceof CarbonImmutable || $parsed->format('Y-m-d') !== $date) {
            throw LeaveException::invalid('LEAVE_DATE_INVALID', 'Give the date as YYYY-MM-DD.');
        }

        return $date;
    }
}
