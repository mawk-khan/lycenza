<?php

namespace App\Support\Retention\Erasure\Subjects;

use App\Domain\HR\Application\Retention\EmployeeRecordRetentionService;
use App\Domain\HR\Application\Retention\EmployeeRetentionEligibility;
use App\Domain\HR\Application\Retention\EmployeeSeparation;
use App\Domain\Payroll\Application\Retention\PayrollEmployeeRetentionService;
use App\Models\School;
use App\Support\Retention\Erasure\ErasureCategory;
use App\Support\Retention\Erasure\ErasurePeriods;
use App\Support\Retention\Erasure\ErasureSubjectAdapter;
use App\Support\Retention\RetentionHolds;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * E21.2F (E21-D10): a reviewed erasure case for one Employee. An erasure
 * request never shortens D9, and it is never a path around the 8-year
 * evidence period. Ancillary details (2 y) and employment and payroll
 * evidence (8 y) go only once those periods have passed since final
 * separation (EmployeeRetentionEligibility), through the same locked purges
 * the scheduled run uses, for this one Employee:
 * - active, future-employed and rehired Employees are current and kept;
 * - payroll results (ledger, D8), teaching history and a linked User
 *   block;
 * - the School hold wins.
 *
 * Personal details are never minimised earlier: they go with the
 * employment evidence (E21.2G decision).
 */
final class EmployeeErasureAdapter implements ErasureSubjectAdapter
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EmployeeRetentionEligibility $eligibility,
        private readonly EmployeeRecordRetentionService $records,
        private readonly PayrollEmployeeRetentionService $payroll,
        private readonly RetentionHolds $holds,
    ) {}

    public function subjectType(): string
    {
        return 'employee';
    }

    public function exists(?School $school, string $subjectId): bool
    {
        return $school !== null && $this->context->withSchool($school, fn (): bool => DB::table('employees')->where('id', $subjectId)->exists());
    }

    public function plan(?School $school, string $subjectId): array
    {
        if ($school === null || ! $this->exists($school, $subjectId)) {
            return [new ErasureCategory('employee_record', ErasureCategory::COMPLETED, 'subject_absent')];
        }

        if ($this->holds->isHeld($school->id)) {
            return array_map(fn (string $c) => new ErasureCategory($c, ErasureCategory::LEGAL_HOLD, 'school_hold'), ['employee_ancillary', 'employee_evidence', 'employee_personal_minimization']);
        }

        $separation = $this->eligibility->separationOf($school, $subjectId);
        $ancillaryYears = ErasurePeriods::years('employee_ancillary_years');
        $evidenceYears = ErasurePeriods::years('employee_evidence_years');

        $ancillary = $this->period($school, 'employee_ancillary', $separation, $ancillaryYears, function () use ($school, $subjectId, $ancillaryYears): ErasureCategory {
            $counts = $this->records->pruneAncillary($school, ErasurePeriods::cutoff($school, (int) $ancillaryYears), 1, true, $subjectId);

            return match (true) {
                $counts['dependency_blocked'] > 0 => new ErasureCategory('employee_ancillary', ErasureCategory::DEPENDENCY_BLOCKED, 'retained_dependency'),
                $counts['eligible'] > 0 => new ErasureCategory('employee_ancillary', ErasureCategory::ELIGIBLE, 'period_passed'),
                default => new ErasureCategory('employee_ancillary', ErasureCategory::COMPLETED, 'nothing_left'),
            };
        });

        $evidence = $this->period($school, 'employee_evidence', $separation, $evidenceYears, function () use ($school, $subjectId, $evidenceYears): ErasureCategory {
            $cutoff = ErasurePeriods::cutoff($school, (int) $evidenceYears);
            $payroll = $this->payroll->prune($school, $cutoff, 1, true, $subjectId);
            $record = $this->records->pruneEvidence($school, $cutoff, 1, true, PayrollEmployeeRetentionService::TABLES, $subjectId);

            return $payroll['dependency_blocked'] + $record['dependency_blocked'] > 0
                ? new ErasureCategory('employee_evidence', ErasureCategory::DEPENDENCY_BLOCKED, 'retained_dependency')
                : new ErasureCategory('employee_evidence', ErasureCategory::ELIGIBLE, 'period_passed');
        });

        // E21.2G: no earlier partial minimisation. Personal details stay with the
        // employment evidence and go with it (project decision, pending ratification).
        return [$ancillary, $evidence, new ErasureCategory('employee_personal_minimization', ErasureCategory::RETAINED_UNTIL, 'retained_with_evidence', $evidence->notBefore)];
    }

    public function execute(?School $school, string $subjectId): array
    {
        $plan = $this->plan($school, $subjectId);
        $eligible = fn (string $category): bool => collect($plan)->contains(fn (ErasureCategory $c) => $c->category === $category && $c->outcome === ErasureCategory::ELIGIBLE);

        if ($school !== null && $eligible('employee_ancillary')) {
            $this->records->pruneAncillary($school, ErasurePeriods::cutoff($school, (int) ErasurePeriods::years('employee_ancillary_years')), 100, false, $subjectId);
        }

        if ($school !== null && $eligible('employee_evidence')) {
            $cutoff = ErasurePeriods::cutoff($school, (int) ErasurePeriods::years('employee_evidence_years'));
            $this->payroll->prune($school, $cutoff, 100, false, $subjectId);
            $this->records->pruneEvidence($school, $cutoff, 100, false, [], $subjectId);
        }

        return $this->plan($school, $subjectId);
    }

    /** @param  callable(): ErasureCategory  $whenPast */
    private function period(School $school, string $category, ?EmployeeSeparation $separation, ?int $years, callable $whenPast): ErasureCategory
    {
        return match (true) {
            $years === null => new ErasureCategory($category, ErasureCategory::RETAINED_UNTIL, 'period_not_configured'),
            $separation === null || $separation->state === EmployeeSeparation::CURRENT => new ErasureCategory($category, ErasureCategory::RETAINED_UNTIL, 'subject_current'),
            $separation->state === EmployeeSeparation::UNRESOLVED => new ErasureCategory($category, ErasureCategory::RETAINED_UNTIL, 'separation_unresolved'),
            ! $separation->separatedBefore(ErasurePeriods::cutoff($school, $years)) => new ErasureCategory($category, ErasureCategory::RETAINED_UNTIL, 'period_running', ErasurePeriods::firstEligibleDay((string) $separation->separatedOn, $years)),
            default => $whenPast(),
        };
    }
}
