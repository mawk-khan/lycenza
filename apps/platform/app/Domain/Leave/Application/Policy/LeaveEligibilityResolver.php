<?php

namespace App\Domain\Leave\Application\Policy;

use App\Domain\HR\Application\EmploymentCoverage;
use App\Domain\HR\Application\EmploymentRoster;
use App\Domain\HR\Application\HrSelfAdministrationGuard;
use App\Domain\HR\Application\Workforce\WorkforceAsOfReader;
use App\Domain\Leave\Infrastructure\LeaveEligibilityRule;
use App\Domain\Leave\Infrastructure\LeavePolicy;
use App\Domain\Leave\Infrastructure\LeavePolicyAssignment;
use App\Models\School;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * HR.9 (ADR 0065 §28.4): THE eligibility evaluation -- for one leave type
 * and one School-local date, which version each employment resolves to.
 *
 * Inputs, all from their owners, read in a FIXED number of queries whatever
 * the staff count (never per row):
 * - HR's EmploymentRoster::on(): the employments spanning the date (any
 *   status but draft);
 * - HR's WorkforceAsOfReader::placementsOn(): each one's staff type,
 *   Employee Category (HR.1A terms effective that day) and primary
 *   Department (the primary assignment covering that day);
 * - Leave's own assignments of the type on or after the date, and the
 *   versions to evaluate (the active ones, or a draft set for a preview).
 *
 * Outcomes (closed, LeaveEligibilityOutcome):
 * - an assignment covering the date: ASSIGNED, reporting whether the rules
 *   would resolve to the same version today (drift is shown, never acted on:
 *   an assignment is never repointed, ADR 0065 §4.2);
 * - a draft, separated or ended employment, or an inactive Employee:
 *   EMPLOYMENT_NOT_ELIGIBLE (Employee existence is never eligibility);
 * - otherwise the matching active versions: none -> NO_MATCHING_RULE; more
 *   than one -> AMBIGUOUS (never ranked); exactly one -> ELIGIBLE, or
 *   OWN_EMPLOYMENT for the acting User's own employment (G-4).
 *
 * An ELIGIBLE row also gives the date the assignment would end: the
 * employment's end, or the day before a LATER assignment of the type, so a
 * run never overlaps, extends or repoints anything.
 *
 * Data, never authorization: callers authorize first and run it inside the
 * School's TenantContext.
 */
final class LeaveEligibilityResolver
{
    public function __construct(
        private readonly EmploymentRoster $roster,
        private readonly WorkforceAsOfReader $workforce,
        private readonly HrSelfAdministrationGuard $selfAdministration,
    ) {}

    /**
     * The ACTIVE versions of the leave type with their rules (two queries).
     *
     * @return list<LeaveEligibilityVersion>
     */
    public function activeVersions(School $school, string $leaveTypeId): array
    {
        $policies = LeavePolicy::query()->where('school_id', $school->id)->where('leave_type_id', $leaveTypeId)->where('status', 'active')
            ->orderBy('created_at')->orderBy('id')->get();
        $rules = LeaveEligibilityRule::query()->where('school_id', $school->id)->whereIn('leave_policy_id', $policies->pluck('id')->all())
            ->orderBy('id')->get()->groupBy('leave_policy_id');

        return $policies->map(fn (LeavePolicy $p) => new LeaveEligibilityVersion(
            $p->id,
            $p->name,
            $p->annual_allocation_units,
            ($rules->get($p->id) ?? collect())->map(fn (LeaveEligibilityRule $r) => [$r->id, LeaveEligibilityCriteria::ofRule($r)])->values()->all(),
        ))->values()->all();
    }

    /**
     * @param  list<LeaveEligibilityVersion>  $versions
     * @return list<LeaveEligibilityRow> sorted by name, then employment id
     */
    public function resolve(School $school, string $leaveTypeId, string $date, array $versions, ?User $actor): array
    {
        $date = CarbonImmutable::parse($date)->toDateString();
        $roster = $this->roster->on($school, $date);
        if ($roster === []) {
            return [];
        }
        $employmentIds = array_column($roster, 'employmentRecordId');
        $placements = $this->workforce->placementsOn($school, array_column($roster, 'employeeId'), $date);
        $spans = $this->roster->spans($school, $employmentIds);
        $assignments = LeavePolicyAssignment::query()->where('school_id', $school->id)->where('leave_type_id', $leaveTypeId)
            ->whereIn('employment_record_id', $employmentIds)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->orderBy('effective_from')->get()->groupBy('employment_record_id');
        $own = $actor === null ? [] : array_flip($this->selfAdministration->ownedEmploymentIds($actor, $school, $employmentIds));

        $rows = [];
        foreach ($roster as $entry) {
            $id = $entry['employmentRecordId'];
            $placement = $placements[strtolower($entry['employeeId'])] ?? null;
            $staffType = $placement?->staffType;
            $categoryId = $placement?->employeeCategoryId;
            $departmentId = $placement?->departmentId;
            [$policyId, $ruleId, $matching] = self::match($versions, $staffType, $categoryId, $departmentId);

            $covering = null;
            $later = null;
            foreach ($assignments->get($id) ?? [] as $a) {
                /** @var LeavePolicyAssignment $a */
                if ($a->effective_from->toDateString() <= $date) {
                    $covering = $a;
                } elseif ($later === null) {
                    $later = $a;
                }
            }

            $outcome = match (true) {
                $covering !== null => LeaveEligibilityOutcome::ASSIGNED,
                ! in_array($entry['status'], EmploymentCoverage::PLANNED_OR_CURRENT_STATUSES, true) || ! $entry['employeeActive'] => LeaveEligibilityOutcome::EMPLOYMENT_NOT_ELIGIBLE,
                count($matching) === 0 => LeaveEligibilityOutcome::NO_MATCHING_RULE,
                count($matching) > 1 => LeaveEligibilityOutcome::AMBIGUOUS,
                isset($own[$id]) => LeaveEligibilityOutcome::OWN_EMPLOYMENT,
                default => LeaveEligibilityOutcome::ELIGIBLE,
            };
            $endsOn = $spans[$id]['endsOn'] ?? null;
            if ($later !== null) {
                $dayBefore = $later->effective_from->copy()->subDay()->toDateString();
                $endsOn = $endsOn === null || $dayBefore < $endsOn ? $dayBefore : $endsOn;
            }

            $rows[] = new LeaveEligibilityRow(
                employmentRecordId: $id,
                employeeId: $entry['employeeId'],
                employeeNumber: $entry['employeeNumber'],
                fullName: $entry['fullName'],
                employmentStatus: $entry['status'],
                staffType: $staffType,
                employeeCategoryId: $categoryId,
                departmentId: $departmentId,
                outcome: $outcome,
                policyId: count($matching) === 1 ? $policyId : null,
                ruleId: count($matching) === 1 ? $ruleId : null,
                matchingPolicyIds: $matching,
                assignmentId: $covering?->id,
                assignedPolicyId: $covering?->leave_policy_id,
                assignmentEndsOn: $outcome === LeaveEligibilityOutcome::ELIGIBLE ? $endsOn : null,
            );
        }
        usort($rows, fn (LeaveEligibilityRow $a, LeaveEligibilityRow $b) => [$a->fullName ?? '', $a->employmentRecordId] <=> [$b->fullName ?? '', $b->employmentRecordId]);

        return $rows;
    }

    /**
     * @param  list<LeaveEligibilityVersion>  $versions
     * @return array{0: ?string, 1: ?string, 2: list<string>} [policy id, first matching rule id, every matching policy id]
     */
    private static function match(array $versions, ?string $staffType, ?string $categoryId, ?string $departmentId): array
    {
        $policyId = null;
        $ruleId = null;
        $matching = [];
        foreach ($versions as $version) {
            foreach ($version->rules as [$id, $criteria]) {
                if ($criteria->matches($staffType, $categoryId, $departmentId)) {
                    $matching[] = $version->policyId;
                    $policyId ??= $version->policyId;
                    $ruleId ??= $id;
                    break;
                }
            }
        }

        return [$policyId, $ruleId, $matching];
    }
}
