<?php

namespace App\Domain\Students\Application;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\Exceptions\StaleRolloverDecisionsException;
use App\Domain\Students\Infrastructure\EnrollmentRolloverItem;
use App\Domain\Students\Infrastructure\EnrollmentRolloverMapping;
use App\Domain\Students\Infrastructure\EnrollmentRolloverPlan;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * UAT-C2 (UAT-YR-01b): Promote or Exclude several Students of one rollover
 * plan at once, without changing what a decision means.
 *
 * - Nothing new decides placement. A decision for a Student is judged by
 *   EnrollmentRolloverDryRunService::simulate() -- the same rules
 *   validation applies -- with every selected Student given the requested
 *   decision at the same time. A Promote is written only when that
 *   Student's placement outcome is clean: mapping, target Section, Roll
 *   Number and existing target-year placement all resolve. Everything
 *   else stays exactly as it was and is reported per Student.
 * - Promote keeps the Student's current Roll Number (`preserve_source`), or
 *   an explicit number already set on that Student. A Roll Number that
 *   collides -- with another selected Student, anyone else in the plan, or
 *   an existing placement -- leaves that Student undecided: never "first
 *   one wins", never a new number.
 * - Promote keeps any target Section already chosen for that Student; the
 *   mapping supplies it otherwise. Exclude changes only the decision.
 * - Selection is by Item id, at most MAX_ITEMS per request, re-read inside
 *   the plan's own scope: an id from another plan or another School is
 *   reported, never written.
 * - apply() locks the plan row and refuses the whole request when the plan
 *   changed since the operator's page loaded (StaleRolloverDecisionsException),
 *   then re-evaluates under that lock. Writes go through
 *   EnrollmentRolloverPlanService::writeItemDecision(): same columns, one
 *   configuration version bump and one audit event per Student, exactly as
 *   a single decision.
 * - Authorization-neutral, like the other rollover services; the
 *   controller checks `enrollments.manage` + `enrollments.rollovers.manage`.
 */
class EnrollmentRolloverBulkDecisionService
{
    public const int MAX_ITEMS = 100;

    public const array DECISIONS = ['promote', 'exclude'];

    // Per-Student outcomes.
    public const string READY = 'ready';

    public const string APPLIED = 'applied';

    public const string ALREADY_DECIDED = 'already_decided';

    public const string NOT_IN_PLAN = 'not_in_plan';

    public const string ALREADY_EXECUTED = 'already_executed';

    public const string NO_MAPPING = 'no_mapping';

    public const string INVALID_MAPPING = 'invalid_mapping';

    public const string MISSING_TARGET_SECTION = 'missing_target_section';

    public const string ROLL_NUMBER_CONFLICT = 'roll_number_conflict';

    public const string TARGET_PLACEMENT_CONFLICT = 'target_placement_conflict';

    public const string SOURCE_NEEDS_REVIEW = 'source_needs_review';

    /** Validation reasons that mean the requested decision cannot be placed as-is. */
    private const array UNRESOLVED_REASONS = [
        'multiple_source_candidates' => self::SOURCE_NEEDS_REVIEW,
        'source_status_ineligible' => self::SOURCE_NEEDS_REVIEW,
        'terminal_grade' => self::NO_MAPPING,
        'missing_mapping' => self::NO_MAPPING,
        'target_grade_mismatch' => self::INVALID_MAPPING,
        'target_wrong_academic_year' => self::INVALID_MAPPING,
        'missing_target_section' => self::MISSING_TARGET_SECTION,
        'invalid_roll_number' => self::ROLL_NUMBER_CONFLICT,
        'roll_number_conflict_in_plan' => self::ROLL_NUMBER_CONFLICT,
        'roll_number_conflict_existing' => self::ROLL_NUMBER_CONFLICT,
        'already_enrolled_conflict' => self::TARGET_PLACEMENT_CONFLICT,
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly EnrollmentRolloverPlanService $plans,
        private readonly EnrollmentRolloverDryRunService $dryRun,
    ) {}

    /**
     * What the decision would do for each selected Student. Writes nothing.
     *
     * @param  list<string>  $itemIds
     * @return list<array{itemId: string, outcome: string, target: ?string}>
     */
    public function preview(EnrollmentRolloverPlan $plan, array $itemIds, string $decision): array
    {
        $this->guard($plan, $itemIds, $decision);

        return $this->context->withSchool($plan->school, fn () => $this->evaluate($plan, $itemIds, $decision)['rows']);
    }

    /**
     * Writes the decision for every selected Student whose outcome is ready;
     * every other Student is left unchanged and reported.
     *
     * @param  list<string>  $itemIds
     * @return list<array{itemId: string, outcome: string, target: ?string}>
     */
    public function apply(EnrollmentRolloverPlan $plan, array $itemIds, string $decision, int $expectedConfigurationVersion, ?User $actor = null): array
    {
        $this->guard($plan, $itemIds, $decision);

        return $this->context->withSchool($plan->school, fn () => DB::transaction(function () use ($plan, $itemIds, $decision, $expectedConfigurationVersion, $actor) {
            $lockedPlan = EnrollmentRolloverPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $this->plans->assertConfigurable($lockedPlan);

            if ($lockedPlan->configuration_version !== $expectedConfigurationVersion) {
                throw new StaleRolloverDecisionsException;
            }

            // Re-evaluated under the plan lock: every configuration write
            // takes the same lock, so nothing can change in between.
            $evaluation = $this->evaluate($lockedPlan, $itemIds, $decision);

            foreach ($evaluation['rows'] as $i => $row) {
                if ($row['outcome'] !== self::READY) {
                    continue;
                }

                /** @var EnrollmentRolloverItem $item */
                $item = $evaluation['items'][$row['itemId']];
                $planned = $evaluation['planned'][$row['itemId']];
                $this->plans->writeItemDecision(
                    $lockedPlan,
                    $item,
                    $decision,
                    $item->target_section_id,
                    $planned['roll_number_strategy'],
                    $planned['target_roll_number'],
                    $actor,
                    ['bulk' => true],
                );
                $evaluation['rows'][$i]['outcome'] = self::APPLIED;
            }

            return $evaluation['rows'];
        }));
    }

    /** @param  list<string>  $itemIds */
    private function guard(EnrollmentRolloverPlan $plan, array $itemIds, string $decision): void
    {
        if (! in_array($decision, self::DECISIONS, true)) {
            throw new InvalidArgumentException("Bulk decisions are Promote or Exclude, not '{$decision}'.");
        }
        if ($itemIds === [] || count($itemIds) > self::MAX_ITEMS) {
            throw new InvalidArgumentException('Choose between 1 and '.self::MAX_ITEMS.' Students.');
        }

        $this->plans->assertConfigurable($plan);
    }

    /**
     * @param  list<string>  $itemIds
     * @return array{rows: list<array{itemId: string, outcome: string, target: ?string}>, items: Collection<string, EnrollmentRolloverItem>, planned: array<string, array{decision: string, roll_number_strategy: ?string, target_roll_number: ?string}>}
     */
    private function evaluate(EnrollmentRolloverPlan $plan, array $itemIds, string $decision): array
    {
        $itemIds = array_values(array_unique($itemIds));

        /** @var Collection<string, EnrollmentRolloverItem> $items */
        $items = EnrollmentRolloverItem::query()
            ->where('plan_id', $plan->id)
            ->whereIn('id', $itemIds)
            ->with('sourceEnrollment')
            ->get()
            ->keyBy('id');

        $planned = [];
        foreach ($items as $item) {
            // Already decided: the same decision, and for Promote a Roll Number
            // rule too (a Promote with none still needs one, so it is completed).
            if ($item->hasExecuted() || ($item->decision === $decision && ($decision !== 'promote' || $item->roll_number_strategy !== null))) {
                continue;
            }
            $keepsExplicit = $item->roll_number_strategy === 'explicit' && trim((string) $item->target_roll_number) !== '';
            $planned[$item->id] = $decision === 'promote'
                ? ['decision' => 'promote', 'roll_number_strategy' => $keepsExplicit ? 'explicit' : 'preserve_source', 'target_roll_number' => $keepsExplicit ? $item->target_roll_number : null]
                : ['decision' => 'exclude', 'roll_number_strategy' => $item->roll_number_strategy, 'target_roll_number' => $item->target_roll_number];
        }

        $simulated = $planned === [] ? [] : $this->dryRun->simulate($plan, $planned);
        $targets = $decision === 'promote' ? $this->targetLabels($plan, $items) : [];

        $rows = [];
        foreach ($itemIds as $id) {
            $item = $items->get($id);
            $outcome = match (true) {
                $item === null => self::NOT_IN_PLAN,
                $item->hasExecuted() => self::ALREADY_EXECUTED,
                ! isset($planned[$id]) => self::ALREADY_DECIDED,
                default => self::outcomeOf($simulated[$id] ?? null),
            };
            $rows[] = ['itemId' => $id, 'outcome' => $outcome, 'target' => $targets[$id] ?? null];
        }

        return ['rows' => $rows, 'items' => $items, 'planned' => $planned];
    }

    /**
     * Ready unless the simulated validation names a placement problem the
     * decision itself cannot get past. A clean result (ready, excluded,
     * already enrolled exactly) is ready whatever its informational reason;
     * an elective-mapping block is plan configuration, not this decision.
     *
     * @param  array<string, mixed>|null  $simulated
     */
    private static function outcomeOf(?array $simulated): string
    {
        if ($simulated === null || in_array($simulated['result'], ['ready', 'excluded', 'already_enrolled'], true)) {
            return self::READY;
        }

        return self::UNRESOLVED_REASONS[$simulated['reason'] ?? ''] ?? self::READY;
    }

    /**
     * "Grade 7 · Section A" for each selected Student's Promote target: the
     * Student's own target Section if one was chosen, otherwise the
     * mapping's (Section-specific first, then the Grade default). Display
     * only -- simulate() is what judges whether it is valid.
     *
     * @param  Collection<string, EnrollmentRolloverItem>  $items
     * @return array<string, string>
     */
    private function targetLabels(EnrollmentRolloverPlan $plan, Collection $items): array
    {
        $mappings = EnrollmentRolloverMapping::query()->where('plan_id', $plan->id)->with('targetGradeLevel')->get();
        $bySection = $mappings->whereNotNull('source_section_id')->keyBy('source_section_id');
        $byGrade = $mappings->whereNull('source_section_id')->keyBy('source_grade_level_id');

        $targetIds = [];
        foreach ($items as $item) {
            $mapping = $bySection->get($item->sourceEnrollment?->section_id) ?? $byGrade->get($item->sourceEnrollment?->grade_level_id);
            $targetIds[$item->id] = ['section' => $item->target_section_id ?? $mapping?->target_section_id, 'grade' => $mapping?->targetGradeLevel?->name];
        }
        $sections = Section::query()->whereIn('id', array_filter(array_column($targetIds, 'section')))->with('gradeLevel')->get()->keyBy('id');

        $labels = [];
        foreach ($targetIds as $itemId => $t) {
            $section = $t['section'] !== null ? $sections->get($t['section']) : null;
            $labels[$itemId] = $section !== null
                ? "{$section->gradeLevel->name} · Section {$section->name}"
                : ($t['grade'] !== null ? "{$t['grade']} · no Section chosen" : 'No mapping');
        }

        return $labels;
    }
}
