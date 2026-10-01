<?php

namespace App\Support\Retention\Erasure\Subjects;

use App\Domain\Attendance\Application\Retention\AttendanceRetentionService;
use App\Domain\Guardians\Application\Retention\GuardianRelationshipRetentionService;
use App\Domain\Students\Application\Retention\StudentExit;
use App\Domain\Students\Application\Retention\StudentRecordRetentionService;
use App\Domain\Students\Application\Retention\StudentRetentionEligibility;
use App\Models\School;
use App\Support\Retention\Erasure\ErasureCategory;
use App\Support\Retention\Erasure\ErasurePeriods;
use App\Support\Retention\Erasure\ErasureSubjectAdapter;
use App\Support\Retention\RetentionHolds;
use App\Support\Retention\RetentionPeriod;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * E21.2F (E21-D10): a reviewed erasure case for one Student. An erasure
 * request never shortens D7: the Student's operational history (7 y) and
 * core record (25 y) go only once their periods have passed since final
 * exit (StudentRetentionEligibility). They go through the very same locked
 * purges the scheduled run uses, for this one Student, so every rule
 * applies unchanged:
 * - the exit is rechecked under the Student-row lock;
 * - any retained dependent blocks (Finance included);
 * - the School hold wins;
 * - Documents follow the record.
 *
 * Minimising the identity of a retained record earlier has no adopted
 * basis (`policy_unresolved`, E21.2G).
 *
 * It lives in Compliance, the orchestrator above Students, Attendance and
 * Guardians, because it composes their purges (a Students-side adapter
 * would invert the Attendance -> Students dependency). Every eligibility
 * rule stays in the owning domain; this only composes them.
 */
final class StudentErasureAdapter implements ErasureSubjectAdapter
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly StudentRetentionEligibility $eligibility,
        private readonly StudentRecordRetentionService $records,
        private readonly AttendanceRetentionService $attendance,
        private readonly GuardianRelationshipRetentionService $relationships,
        private readonly RetentionHolds $holds,
    ) {}

    public function subjectType(): string
    {
        return 'student';
    }

    public function exists(?School $school, string $subjectId): bool
    {
        return $school !== null && $this->context->withSchool($school, fn (): bool => DB::table('students')->where('id', $subjectId)->exists());
    }

    public function plan(?School $school, string $subjectId): array
    {
        if ($school === null || ! $this->exists($school, $subjectId)) {
            return [new ErasureCategory('student_record', ErasureCategory::COMPLETED, 'subject_absent')];
        }

        if ($this->holds->isHeld($school->id)) {
            return array_map(fn (string $c) => new ErasureCategory($c, ErasureCategory::LEGAL_HOLD, 'school_hold'), ['student_operational', 'student_core', 'student_identity_minimization']);
        }

        $exit = $this->eligibility->exitOf($school, $subjectId);
        $operationalYears = ErasurePeriods::years('student_operational_years');
        $coreYears = ErasurePeriods::years('student_core_years');

        $operational = $this->period($school, 'student_operational', $exit, $operationalYears, function () use ($school, $subjectId, $operationalYears): ErasureCategory {
            $cutoff = ErasurePeriods::cutoff($school, (int) $operationalYears);
            $counts = [
                $this->attendance->prune($school, $cutoff, 1, true, $subjectId),
                $this->records->pruneRolloverItems($school, $cutoff, 1, true, $subjectId),
                $this->relationships->prune($school, $cutoff, 1, true, $subjectId),
            ];

            return match (true) {
                array_sum(array_column($counts, 'dependency_blocked')) > 0 => new ErasureCategory('student_operational', ErasureCategory::DEPENDENCY_BLOCKED, 'retained_dependency'),
                array_sum(array_column($counts, 'eligible')) > 0 => new ErasureCategory('student_operational', ErasureCategory::ELIGIBLE, 'period_passed'),
                default => new ErasureCategory('student_operational', ErasureCategory::COMPLETED, 'nothing_left'),
            };
        });

        $core = $this->period($school, 'student_core', $exit, $coreYears, function () use ($school, $subjectId, $operational): ErasureCategory {
            // The operational rows go first in the same execution, unless they stay for a reason.
            $cleared = in_array($operational->outcome, [ErasureCategory::ELIGIBLE, ErasureCategory::COMPLETED], true) ? StudentRecordRetentionService::OPERATIONAL_TABLES : [];
            $authorityYears = RetentionPeriod::years(config('retention.authority_history_years'));
            $blocker = $this->records->coreBlockerFor($school, $subjectId, $authorityYears === null ? null : RetentionPeriod::yearsBeforeNow($authorityYears), $cleared);

            return $blocker === null
                ? new ErasureCategory('student_core', ErasureCategory::ELIGIBLE, 'period_passed')
                : new ErasureCategory('student_core', ErasureCategory::DEPENDENCY_BLOCKED, $blocker);
        });

        return [$operational, $core, new ErasureCategory('student_identity_minimization', ErasureCategory::POLICY_UNRESOLVED, 'no_adopted_basis')];
    }

    public function execute(?School $school, string $subjectId): array
    {
        $plan = $this->plan($school, $subjectId);
        $eligible = fn (string $category): bool => collect($plan)->contains(fn (ErasureCategory $c) => $c->category === $category && $c->outcome === ErasureCategory::ELIGIBLE);

        if ($school !== null && $eligible('student_operational')) {
            $cutoff = ErasurePeriods::cutoff($school, (int) ErasurePeriods::years('student_operational_years'));
            $this->attendance->prune($school, $cutoff, 100, false, $subjectId);
            $this->records->pruneRolloverItems($school, $cutoff, 100, false, $subjectId);
            $this->relationships->prune($school, $cutoff, 100, false, $subjectId);
        }

        if ($school !== null && $eligible('student_core')) {
            $authorityYears = RetentionPeriod::years(config('retention.authority_history_years'));
            $this->records->pruneCore($school, ErasurePeriods::cutoff($school, (int) ErasurePeriods::years('student_core_years')),
                $authorityYears === null ? null : RetentionPeriod::yearsBeforeNow($authorityYears), 100, false, false, $subjectId);
        }

        return $this->plan($school, $subjectId);
    }

    /**
     * The outcome of one period-bound category: retained while the exit is
     * not past its period, otherwise `$whenPast`.
     *
     * @param  callable(): ErasureCategory  $whenPast
     */
    private function period(School $school, string $category, ?StudentExit $exit, ?int $years, callable $whenPast): ErasureCategory
    {
        return match (true) {
            $years === null => new ErasureCategory($category, ErasureCategory::RETAINED_UNTIL, 'period_not_configured'),
            $exit === null || $exit->state === StudentExit::CURRENT => new ErasureCategory($category, ErasureCategory::RETAINED_UNTIL, 'subject_current'),
            $exit->state === StudentExit::UNRESOLVED => new ErasureCategory($category, ErasureCategory::RETAINED_UNTIL, 'exit_unresolved'),
            ! $exit->exitedBefore(ErasurePeriods::cutoff($school, $years)) => new ErasureCategory($category, ErasureCategory::RETAINED_UNTIL, 'period_running', ErasurePeriods::firstEligibleDay((string) $exit->exitDate, $years)),
            default => $whenPast(),
        };
    }
}
