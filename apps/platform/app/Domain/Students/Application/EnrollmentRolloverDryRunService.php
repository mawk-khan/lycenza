<?php

namespace App\Domain\Students\Application;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Students\Application\Exceptions\InvalidRolloverPlanChronologyException;
use App\Domain\Students\Application\Exceptions\StaleRolloverConfigurationException;
use App\Domain\Students\Infrastructure\EnrollmentRolloverItem;
use App\Domain\Students\Infrastructure\EnrollmentRolloverPlan;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Domain\Students\Infrastructure\StudentSubjectEnrollment;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1B.7B: the persistent rollover DRY-RUN / VALIDATION engine --
 * see docs/modules/STUDENT-ENROLLMENT.md ("Academic-Year Rollover &
 * Promotion — Architecture Decision (Phase 1B.7)") for the accepted
 * design this implements. Answers "if this exact configuration were
 * executed now, what would happen for every Student?" by writing ONLY
 * rollover planning state (`EnrollmentRolloverItem` validation/
 * snapshot columns, `EnrollmentRolloverPlan` validation/status
 * columns) -- it creates or modifies ZERO `StudentEnrollment`/
 * `Student`/`AcademicYear`/`Section`/`Campus`/`GradeLevel` rows. No
 * target Enrollment is created here; that is Phase 1B.7C's job.
 *
 * `run()` does two things in one pass:
 *
 *   1. POPULATION -- discovers every Student with an eligible
 *      (`active`/`completed`) source-year Enrollment who does not yet
 *      have an Item in this plan, and creates one anchored to their
 *      resolved source Enrollment (`decision` left at its DB default,
 *      `undecided`). This is bookkeeping, not an operator decision --
 *      it never touches `configuration_version`. An ambiguous Student
 *      (more than one eligible candidate) still gets exactly one Item
 *      (the schema requires a non-null `source_enrollment_id`), anchored
 *      to the lowest-id candidate purely as a technical placeholder --
 *      it carries no decision weight because the ambiguity itself
 *      immediately classifies the Item `review`/`multiple_source_candidates`,
 *      which can never become `ready`.
 *   2. VALIDATION -- re-evaluates EVERY Item (newly populated or
 *      pre-existing) against the plan's CURRENT configuration and the
 *      database's CURRENT state, in the deterministic priority order
 *      documented on `evaluateItem()`, then persists a validation
 *      result + reason + staleness snapshots for every Item and
 *      updates the Plan's own validation state -- but ONLY if the
 *      Plan's `configuration_version` is still exactly what was
 *      captured at the start of this run (`StaleRolloverConfigurationException`
 *      otherwise, and NOTHING is persisted).
 *
 * `target_enrollment_id` is deliberately left NULL by this service even
 * for an exact already-enrolled match -- see `evaluateAlreadyEnrolled()`'s
 * docblock for why conflating "resolved" with "executed" would corrupt
 * `EnrollmentRolloverItem::hasExecuted()`'s meaning for the future
 * execution checkpoint (Phase 1B.7C).
 *
 * Deliberately authorization-neutral, matching every other Application
 * service in this codebase.
 */
class EnrollmentRolloverDryRunService
{
    /**
     * validation_result values. "already_enrolled" and "excluded" are
     * non-blocking NO-OPs; "ready" is executable; "review" and
     * "blocked" both prevent the Plan from becoming `validated"` (the
     * accepted architecture's brief groups CONFLICT under BLOCKED --
     * see this class's own docblock on `evaluateItem()`).
     */
    private const RESULT_READY = 'ready';

    private const RESULT_EXCLUDED = 'excluded';

    private const RESULT_ALREADY_ENROLLED = 'already_enrolled';

    private const RESULT_REVIEW = 'review';

    private const RESULT_BLOCKED = 'blocked';

    /**
     * Phase 1G.2 (docs/students/PHASE-1G-0-SUBJECT-ENROLLMENT-ROLLOVER-ARCHITECTURE.md
     * §8, plus one addition -- `elective_target_duplicate_mapping`, not
     * foreseen by that table, see this checkpoint's own documentation
     * for why): the fixed deterministic priority a Item's several
     * elective participations are resolved to ONE winning
     * `validation_reason` by, when more than one subject-level issue is
     * found across the Item's elective set. Never "first one found in
     * query order" -- always this fixed rank. `legacy_source_anchor_ambiguous`
     * is deliberately LAST -- it is the least specific signal ("we
     * could not prove which source Enrollment a legacy row belongs
     * to"), so a concrete, actionable blocking problem elsewhere in the
     * same Item's elective set always wins the single reason slot.
     */
    private const SUBJECT_REASON_PRECEDENCE = [
        'elective_target_duplicate_mapping',
        'elective_target_group_conflict',
        'elective_target_existing_conflict',
        'missing_subject_mapping',
        'elective_target_required',
        'elective_target_inactive',
        'elective_target_context_mismatch',
        'legacy_source_anchor_ambiguous',
    ];

    /**
     * Every code above except `legacy_source_anchor_ambiguous` blocks
     * outright; that one is `review` (mirrors `multiple_source_candidates`'s
     * own review classification for the analogous placement-side
     * ambiguity -- architecture doc §8's "Yes (review)" annotation).
     */
    private const SUBJECT_REASON_RESULT = [
        'elective_target_duplicate_mapping' => self::RESULT_BLOCKED,
        'elective_target_group_conflict' => self::RESULT_BLOCKED,
        'elective_target_existing_conflict' => self::RESULT_BLOCKED,
        'missing_subject_mapping' => self::RESULT_BLOCKED,
        'elective_target_required' => self::RESULT_BLOCKED,
        'elective_target_inactive' => self::RESULT_BLOCKED,
        'elective_target_context_mismatch' => self::RESULT_BLOCKED,
        'legacy_source_anchor_ambiguous' => self::RESULT_REVIEW,
    ];

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly EnrollmentRolloverPlanService $planService,
    ) {}

    /**
     * @param  callable(): void|null  $beforePersist  Test-only seam: invoked after the (potentially
     *                                                expensive) calculation completes but before the
     *                                                persistence transaction begins/re-checks
     *                                                `configuration_version` -- lets a test deterministically
     *                                                simulate the "configuration changed mid-validation" race
     *                                                (section 97 of this checkpoint's brief) without relying on
     *                                                real concurrency/timing.
     * @return array{total: int, ready: int, excluded: int, already_enrolled: int, review: int, blocked: int, validated: bool, configurationVersion: int}
     */
    public function run(EnrollmentRolloverPlan $plan, ?User $actor = null, ?callable $beforePersist = null): array
    {
        $this->planService->assertConfigurable($plan);

        return $this->context->withSchool($plan->school, function () use ($plan, $actor, $beforePersist) {
            $capturedVersion = $plan->configuration_version;

            $sourceYear = $plan->sourceAcademicYear;
            $targetYear = $plan->targetAcademicYear;
            if (! ($targetYear->starts_on > $sourceYear->starts_on)) {
                throw new InvalidRolloverPlanChronologyException;
            }

            $candidatesByStudent = StudentEnrollment::query()
                ->where('academic_year_id', $plan->source_academic_year_id)
                ->whereIn('status', ['active', 'completed'])
                ->get()
                ->groupBy('student_id');

            $this->populateItems($plan, $candidatesByStudent);

            $items = EnrollmentRolloverItem::query()->where('plan_id', $plan->id)->get();
            $mappings = $plan->mappings()->get();
            $mappingsBySection = $mappings->whereNotNull('source_section_id')->keyBy('source_section_id');
            $mappingsByGrade = $mappings->whereNull('source_section_id')->keyBy('source_grade_level_id');

            $freshSources = StudentEnrollment::query()
                ->whereIn('id', $items->pluck('source_enrollment_id')->unique()->all())
                ->get()->keyBy('id');

            [$results, $readyCandidates] = $this->evaluateStructural($items, $candidatesByStudent, $freshSources, $mappingsBySection, $mappingsByGrade);

            [$newResults, $readyCandidates] = $this->resolveTargetSections($plan, $readyCandidates);
            $results += $newResults;

            [$newResults, $readyCandidates] = $this->evaluateAlreadyEnrolled($plan, $readyCandidates);
            $results += $newResults;

            [$newResults, $readyCandidates] = $this->evaluateInPlanConflicts($readyCandidates);
            $results += $newResults;

            $results += $this->evaluatePersistedConflicts($plan, $readyCandidates);

            $results = $this->evaluateSubjectParticipation($plan, $items, $results, $readyCandidates);

            if ($beforePersist !== null) {
                $beforePersist();
            }

            return DB::transaction(function () use ($plan, $capturedVersion, $items, $results, $actor) {
                $lockedPlan = EnrollmentRolloverPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

                if ($lockedPlan->configuration_version !== $capturedVersion) {
                    throw new StaleRolloverConfigurationException;
                }

                foreach ($items as $item) {
                    $r = $results[$item->id];
                    $item->update([
                        'validation_result' => $r['result'],
                        'validation_reason' => $r['reason'],
                        'source_enrollment_status_snapshot' => $r['sourceStatusSnapshot'] ?? null,
                        'source_enrollment_updated_at_snapshot' => $r['sourceUpdatedAtSnapshot'] ?? null,
                        'target_section_status_snapshot' => $r['targetStatusSnapshot'] ?? null,
                        'target_section_updated_at_snapshot' => $r['targetUpdatedAtSnapshot'] ?? null,
                    ]);
                }

                $summary = $this->summarize($results);
                $isFullyValidated = ($summary['review'] + $summary['blocked']) === 0;

                if ($isFullyValidated) {
                    $lockedPlan->update([
                        'status' => 'validated',
                        'validated_configuration_version' => $capturedVersion,
                        'validated_at' => now(),
                    ]);
                    $this->audit->school($lockedPlan->school, 'enrollment_rollover_plan.validated', actor: $actor, subject: $lockedPlan, metadata: [
                        'configurationVersion' => $capturedVersion,
                        'summary' => $summary,
                    ]);
                } else {
                    $this->audit->school($lockedPlan->school, 'enrollment_rollover_plan.validation_completed', actor: $actor, subject: $lockedPlan, metadata: [
                        'configurationVersion' => $capturedVersion,
                        'summary' => $summary,
                    ]);
                }

                return $summary + ['validated' => $isFullyValidated, 'configurationVersion' => $capturedVersion];
            });
        });
    }

    /**
     * Discovers Students eligible for this plan's source Academic Year
     * who don't yet have an Item, and creates one for each -- never
     * touches an EXISTING Item's configuration, never touches
     * `configuration_version` (this is bookkeeping, not a decision).
     */
    private function populateItems(EnrollmentRolloverPlan $plan, Collection $candidatesByStudent): void
    {
        $existingStudentIds = EnrollmentRolloverItem::query()
            ->where('plan_id', $plan->id)
            ->pluck('student_id')
            ->flip();

        foreach ($candidatesByStudent as $studentId => $candidates) {
            if ($existingStudentIds->has($studentId)) {
                continue;
            }

            // Ambiguity (>1 candidate) is resolved to `review` during
            // evaluation regardless of which row is anchored here --
            // the lowest-id row is a deterministic placeholder only,
            // never a silent "pick the winner" (this checkpoint's
            // brief, section 76).
            $chosen = $candidates->sortBy('id')->first();

            EnrollmentRolloverItem::query()->create([
                'school_id' => $plan->school_id,
                'plan_id' => $plan->id,
                'student_id' => $studentId,
                'source_enrollment_id' => $chosen->id,
                'decision' => 'undecided',
            ]);
        }
    }

    /**
     * Pass 1 -- structural/source/decision/mapping resolution, in the
     * exact deterministic priority this checkpoint's brief (section
     * 43) requires: source ambiguity/eligibility -> decision -> mapping
     * -> (Roll Number resolved but NOT yet conflict-checked). Never
     * queries per-Item -- `$candidatesByStudent`/`$freshSources`/
     * `$mappingsBySection`/`$mappingsByGrade` are all pre-batched by
     * the caller.
     *
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, array<string, mixed>>}
     */
    private function evaluateStructural(
        Collection $items,
        Collection $candidatesByStudent,
        Collection $freshSources,
        Collection $mappingsBySection,
        Collection $mappingsByGrade,
    ): array {
        $results = [];
        $readyCandidates = [];

        foreach ($items as $item) {
            $freshSource = $freshSources->get($item->source_enrollment_id);
            $candidates = $candidatesByStudent->get($item->student_id, collect());

            $snapshot = [
                'sourceStatusSnapshot' => $freshSource?->status,
                'sourceUpdatedAtSnapshot' => $freshSource?->updated_at,
            ];

            if ($candidates->count() > 1) {
                $results[$item->id] = $snapshot + ['result' => self::RESULT_REVIEW, 'reason' => 'multiple_source_candidates'];

                continue;
            }

            if ($freshSource === null || ! in_array($freshSource->status, ['active', 'completed'], true)) {
                $results[$item->id] = $snapshot + ['result' => self::RESULT_BLOCKED, 'reason' => 'source_status_ineligible'];

                continue;
            }

            $mapping = $mappingsBySection->get($freshSource->section_id) ?? $mappingsByGrade->get($freshSource->grade_level_id);

            if ($item->decision === 'exclude') {
                $results[$item->id] = $snapshot + ['result' => self::RESULT_EXCLUDED, 'reason' => $mapping === null ? 'terminal_grade' : null];

                continue;
            }

            if (! in_array($item->decision, ['promote', 'repeat'], true)) {
                $results[$item->id] = $snapshot + ['result' => self::RESULT_REVIEW, 'reason' => $mapping === null ? 'terminal_grade' : 'undecided'];

                continue;
            }

            if ($mapping === null) {
                $results[$item->id] = $snapshot + ['result' => self::RESULT_BLOCKED, 'reason' => 'missing_mapping'];

                continue;
            }

            if (($item->decision === 'promote' && $mapping->isRepeat()) || ($item->decision === 'repeat' && ! $mapping->isRepeat())) {
                $results[$item->id] = $snapshot + ['result' => self::RESULT_BLOCKED, 'reason' => 'target_grade_mismatch'];

                continue;
            }

            $targetSectionId = $item->target_section_id ?? $mapping->target_section_id;
            if ($targetSectionId === null) {
                $results[$item->id] = $snapshot + ['result' => self::RESULT_BLOCKED, 'reason' => 'missing_target_section'];

                continue;
            }

            $rollNumber = $this->resolveRollNumber($item, $freshSource);
            if ($rollNumber === null) {
                $results[$item->id] = $snapshot + ['result' => self::RESULT_BLOCKED, 'reason' => 'invalid_roll_number'];

                continue;
            }

            $readyCandidates[$item->id] = [
                'item' => $item,
                'targetSectionId' => $targetSectionId,
                'rollNumber' => $rollNumber,
                'mapping' => $mapping,
                'snapshot' => $snapshot,
            ];
        }

        return [$results, $readyCandidates];
    }

    /**
     * Pass 2 -- batch-fetches every distinct proposed target Section
     * exactly once, then validates each candidate's target Year/Grade
     * against it (never trusts the mapping/item alone). Sections
     * dropped here never reach conflict detection.
     *
     * @param  array<string, array<string, mixed>>  $readyCandidates
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, array<string, mixed>>}
     */
    private function resolveTargetSections(EnrollmentRolloverPlan $plan, array $readyCandidates): array
    {
        $sectionIds = array_unique(array_column($readyCandidates, 'targetSectionId'));
        $sections = Section::query()->whereIn('id', $sectionIds)->get()->keyBy('id');

        $results = [];
        $stillCandidates = [];
        foreach ($readyCandidates as $itemId => $c) {
            $targetSection = $sections->get($c['targetSectionId']);
            $snapshot = $c['snapshot'] + [
                'targetStatusSnapshot' => $targetSection?->status,
                'targetUpdatedAtSnapshot' => $targetSection?->updated_at,
            ];

            if ($targetSection === null || $targetSection->academic_year_id !== $plan->target_academic_year_id) {
                $results[$itemId] = $snapshot + ['result' => self::RESULT_BLOCKED, 'reason' => 'target_wrong_academic_year'];

                continue;
            }

            if ($targetSection->grade_level_id !== $c['mapping']->target_grade_level_id) {
                $results[$itemId] = $snapshot + ['result' => self::RESULT_BLOCKED, 'reason' => 'target_grade_mismatch'];

                continue;
            }

            $stillCandidates[$itemId] = [...$c, 'snapshot' => $snapshot];
        }

        return [$results, $stillCandidates];
    }

    /**
     * Pass 3 -- one batched query for every Student still in play,
     * checking whether they already have ANY Enrollment in the target
     * Academic Year (this checkpoint's brief, sections 35/36).
     *
     * An exact match (same Section, same Roll Number, `active`) is a
     * non-blocking NO-OP. A mismatch (different Section/Roll, or the
     * existing row is `active` but differs) is BLOCKED -- never
     * overwritten, never auto-transferred. An existing row that is
     * NOT `active` (only terminal history in the target year, no
     * currently-active placement) is REVIEW, not a hard block --
     * anomalous enough to need a human look, but not necessarily wrong.
     *
     * `target_enrollment_id` is intentionally left untouched (null)
     * here even for an exact match -- see this class's own top
     * docblock. Recording which pre-existing Enrollment satisfies the
     * proposal is Phase 1B.7C's job, at the moment it actually
     * reconciles/executes, not dry-run's.
     *
     * @param  array<string, array<string, mixed>>  $readyCandidates
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, array<string, mixed>>}
     */
    private function evaluateAlreadyEnrolled(EnrollmentRolloverPlan $plan, array $readyCandidates): array
    {
        $studentIds = array_unique(array_map(fn ($c) => $c['item']->student_id, $readyCandidates));

        $targetYearEnrollmentsByStudent = StudentEnrollment::query()
            ->whereIn('student_id', $studentIds)
            ->where('academic_year_id', $plan->target_academic_year_id)
            ->get()
            ->groupBy('student_id');

        $results = [];
        $stillCandidates = [];
        foreach ($readyCandidates as $itemId => $c) {
            $existing = $targetYearEnrollmentsByStudent->get($c['item']->student_id, collect());

            if ($existing->isEmpty()) {
                $stillCandidates[$itemId] = $c;

                continue;
            }

            $activeExisting = $existing->firstWhere('status', 'active');

            if ($activeExisting === null) {
                $results[$itemId] = $c['snapshot'] + ['result' => self::RESULT_REVIEW, 'reason' => 'already_enrolled_conflict'];

                continue;
            }

            if ($activeExisting->section_id === $c['targetSectionId'] && $activeExisting->roll_number === $c['rollNumber']) {
                $results[$itemId] = $c['snapshot'] + ['result' => self::RESULT_ALREADY_ENROLLED, 'reason' => 'already_enrolled_match'];
            } else {
                $results[$itemId] = $c['snapshot'] + ['result' => self::RESULT_BLOCKED, 'reason' => 'already_enrolled_conflict'];
            }
        }

        return [$results, $stillCandidates];
    }

    /**
     * Pass 4 -- set-based, in-memory grouping by (target Section, Roll
     * Number). Every member of a colliding group is BLOCKED -- never
     * "first Student wins," and never order-dependent (this
     * checkpoint's brief, sections 33/50).
     *
     * @param  array<string, array<string, mixed>>  $readyCandidates
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, array<string, mixed>>}
     */
    private function evaluateInPlanConflicts(array $readyCandidates): array
    {
        $results = [];
        $groups = [];
        foreach ($readyCandidates as $itemId => $c) {
            $groups[$c['targetSectionId'].':'.$c['rollNumber']][] = $itemId;
        }

        $stillCandidates = $readyCandidates;
        foreach ($groups as $itemIds) {
            if (count($itemIds) < 2) {
                continue;
            }
            foreach ($itemIds as $itemId) {
                $results[$itemId] = $stillCandidates[$itemId]['snapshot'] + ['result' => self::RESULT_BLOCKED, 'reason' => 'roll_number_conflict_in_plan'];
                unset($stillCandidates[$itemId]);
            }
        }

        return [$results, $stillCandidates];
    }

    /**
     * Pass 5 (final) -- one batched query across every distinct
     * remaining target Section for the target Academic Year, checking
     * for a Roll Number already occupied by a DIFFERENT Student
     * (already-enrolled same-Student matches were resolved in pass 3).
     * Whatever survives this pass is READY.
     *
     * @param  array<string, array<string, mixed>>  $readyCandidates
     * @return array<string, array<string, mixed>>
     */
    private function evaluatePersistedConflicts(EnrollmentRolloverPlan $plan, array $readyCandidates): array
    {
        $sectionIds = array_unique(array_column($readyCandidates, 'targetSectionId'));

        $persistedByKey = StudentEnrollment::query()
            ->where('academic_year_id', $plan->target_academic_year_id)
            ->whereIn('section_id', $sectionIds)
            ->get()
            ->keyBy(fn (StudentEnrollment $e) => $e->section_id.':'.$e->roll_number);

        $results = [];
        foreach ($readyCandidates as $itemId => $c) {
            $existing = $persistedByKey->get($c['targetSectionId'].':'.$c['rollNumber']);

            if ($existing !== null && $existing->student_id !== $c['item']->student_id) {
                $results[$itemId] = $c['snapshot'] + ['result' => self::RESULT_BLOCKED, 'reason' => 'roll_number_conflict_existing'];

                continue;
            }

            $results[$itemId] = $c['snapshot'] + ['result' => self::RESULT_READY, 'reason' => null];
        }

        return $results;
    }

    /**
     * Phase 1G.2 (architecture doc §8) -- the additional per-Item
     * elective-rollover evaluation stage, composed AFTER every existing
     * placement pass so that "existing Phase 1B placement failure
     * precedence first" (this checkpoint's brief, section 30) falls out
     * for free: only an Item that is currently `ready` (a fresh
     * promotion about to happen) or `already_enrolled` (its target
     * placement already exists) is even considered here -- any Item
     * still `blocked`/`review`/`excluded` from placement alone keeps
     * its OWN reason untouched, because this method only ever WRITES an
     * override into `$results` for an item id it actually evaluates.
     *
     * Reads ONLY `StudentSubjectEnrollment`/`SubjectOffering`/this
     * checkpoint's own `EnrollmentRolloverSubjectMapping` table (plus
     * the already-batched `StudentEnrollment` rows) -- zero academic
     * writes, unchanged invariant.
     *
     * @param  array<string, array<string, mixed>>  $results
     * @param  array<string, array<string, mixed>>  $readyCandidates  Pre-persisted-conflict-check candidates (targetSectionId/mapping still attached) -- used only to recover context for whichever of them $results now marks READY.
     * @return array<string, array<string, mixed>>
     */
    private function evaluateSubjectParticipation(EnrollmentRolloverPlan $plan, Collection $items, array $results, array $readyCandidates): array
    {
        $subjectCandidates = $this->buildSubjectCandidateContexts($plan, $items, $results, $readyCandidates);

        if ($subjectCandidates === []) {
            return $results;
        }

        $itemsById = $items->keyBy('id');
        $sourceEnrollmentIds = collect($subjectCandidates)->map(fn ($c) => $itemsById->get($c['itemId'])->source_enrollment_id)->unique()->all();
        $studentIds = collect($subjectCandidates)->map(fn ($c) => $itemsById->get($c['itemId'])->student_id)->unique()->all();

        $anchoredByEnrollment = StudentSubjectEnrollment::query()
            ->where('status', 'active')
            ->whereIn('student_enrollment_id', $sourceEnrollmentIds)
            ->with('subjectOffering')
            ->get()
            ->groupBy('student_enrollment_id');

        $legacyByStudent = StudentSubjectEnrollment::query()
            ->where('status', 'active')
            ->whereNull('student_enrollment_id')
            ->whereIn('student_id', $studentIds)
            ->where('academic_year_id', $plan->source_academic_year_id)
            ->with('subjectOffering')
            ->get()
            ->groupBy('student_id');

        $allSourceYearEnrollmentsByStudent = StudentEnrollment::query()
            ->where('academic_year_id', $plan->source_academic_year_id)
            ->whereIn('student_id', $studentIds)
            ->get()
            ->groupBy('student_id');

        $mappingsBySource = $plan->subjectMappings()->get()->keyBy('source_subject_offering_id');

        $targetOfferingIds = $mappingsBySource->pluck('target_subject_offering_id')->filter()->unique()->all();
        $targetOfferingsById = SubjectOffering::query()->whereIn('id', $targetOfferingIds)->get()->keyBy('id');

        $targetEnrollmentIds = collect($subjectCandidates)->pluck('targetEnrollmentId')->filter()->unique()->all();
        $existingTargetParticipationsByEnrollment = StudentSubjectEnrollment::query()
            ->where('status', 'active')
            ->whereIn('student_enrollment_id', $targetEnrollmentIds)
            ->get()
            ->groupBy('student_enrollment_id');

        foreach ($subjectCandidates as $ctx) {
            $itemId = $ctx['itemId'];
            $item = $itemsById->get($itemId);

            $participations = collect();
            $itemReasons = [];

            foreach ($anchoredByEnrollment->get($item->source_enrollment_id, collect()) as $row) {
                $participations->push($row);
            }

            foreach ($legacyByStudent->get($item->student_id, collect()) as $row) {
                $yearCandidates = $allSourceYearEnrollmentsByStudent->get($item->student_id, collect());
                $offering = $row->subjectOffering;

                if ($yearCandidates->count() !== 1 || $offering === null) {
                    $itemReasons[] = 'legacy_source_anchor_ambiguous';

                    continue;
                }

                $onlyCandidate = $yearCandidates->first();
                $compatible = $onlyCandidate->academic_year_id === $offering->academic_year_id
                    && $onlyCandidate->campus_id === $offering->campus_id
                    && $onlyCandidate->grade_level_id === $offering->grade_level_id;

                if (! $compatible || $onlyCandidate->id !== $item->source_enrollment_id) {
                    $itemReasons[] = 'legacy_source_anchor_ambiguous';

                    continue;
                }

                $participations->push($row);
            }

            $resolvedTargets = [];
            foreach ($participations as $row) {
                $offering = $row->subjectOffering;

                if ($offering === null || $offering->is_required) {
                    // Phase 1G.2 section 61 defense: a required-offering
                    // participation is never a valid elective rollover
                    // candidate, even if malformed/raw data made one
                    // exist -- silently excluded, no conflict raised.
                    continue;
                }

                $mapping = $mappingsBySource->get($offering->id);

                if ($mapping === null) {
                    $itemReasons[] = 'missing_subject_mapping';

                    continue;
                }

                if ($mapping->isExplicitOmit()) {
                    continue;
                }

                $target = $targetOfferingsById->get($mapping->target_subject_offering_id);

                if ($target === null) {
                    // Defensive only -- the composite same-School FK
                    // guarantees a mapped target Offering exists; this
                    // is never reachable through the sanctioned mutation
                    // service.
                    $itemReasons[] = 'elective_target_context_mismatch';

                    continue;
                }

                if ($target->is_required) {
                    $itemReasons[] = 'elective_target_required';

                    continue;
                }

                if (! $target->isActive()) {
                    $itemReasons[] = 'elective_target_inactive';

                    continue;
                }

                if ($target->academic_year_id !== $ctx['targetAcademicYearId']
                    || $target->campus_id !== $ctx['targetCampusId']
                    || $target->grade_level_id !== $ctx['targetGradeLevelId']) {
                    $itemReasons[] = 'elective_target_context_mismatch';

                    continue;
                }

                $resolvedTargets[$offering->id] = $target;
            }

            $targetIdCounts = [];
            foreach ($resolvedTargets as $target) {
                $targetIdCounts[$target->id] = ($targetIdCounts[$target->id] ?? 0) + 1;
            }
            if (collect($targetIdCounts)->contains(fn ($count) => $count > 1)) {
                $itemReasons[] = 'elective_target_duplicate_mapping';
            }

            $byGroup = [];
            foreach ($resolvedTargets as $target) {
                if ($target->elective_group_id !== null) {
                    $byGroup[$target->elective_group_id][$target->id] = true;
                }
            }
            foreach ($byGroup as $groupTargetIds) {
                if (count($groupTargetIds) > 1) {
                    $itemReasons[] = 'elective_target_group_conflict';

                    break;
                }
            }

            if ($ctx['targetEnrollmentId'] !== null) {
                $existingActive = $existingTargetParticipationsByEnrollment->get($ctx['targetEnrollmentId'], collect());
                $existingByOffering = $existingActive->keyBy('subject_offering_id');
                $existingGroups = $existingActive->whereNotNull('elective_group_id')->groupBy('elective_group_id');

                foreach ($resolvedTargets as $target) {
                    if ($existingByOffering->has($target->id)) {
                        // elective_already_enrolled_match -- non-blocking,
                        // idempotent no-op (this checkpoint's §20).
                        continue;
                    }

                    if ($target->elective_group_id !== null && $existingGroups->has($target->elective_group_id)) {
                        $itemReasons[] = 'elective_target_existing_conflict';
                    }
                }
            }

            if ($itemReasons === []) {
                continue;
            }

            $winner = collect(self::SUBJECT_REASON_PRECEDENCE)->first(fn ($reason) => in_array($reason, $itemReasons, true));

            $results[$itemId] = array_diff_key($results[$itemId], ['result' => true, 'reason' => true]) + [
                'result' => self::SUBJECT_REASON_RESULT[$winner],
                'reason' => $winner,
            ];
        }

        return $results;
    }

    /**
     * Builds the per-Item target-placement context (resolved target
     * AcademicYear/Campus/GradeLevel, plus the target StudentEnrollment
     * id when one already exists) for every Item `$results` currently
     * marks `ready` or `already_enrolled` -- the only two states for
     * which a target StudentEnrollment either will be, or already is,
     * real. Every other Item (`excluded`/`review`/`blocked`) is never
     * included -- its own placement reason already wins (section 30).
     *
     * @param  array<string, array<string, mixed>>  $results
     * @param  array<string, array<string, mixed>>  $readyCandidates
     * @return list<array{itemId: string, targetAcademicYearId: string, targetCampusId: string, targetGradeLevelId: string, targetEnrollmentId: ?string}>
     */
    private function buildSubjectCandidateContexts(EnrollmentRolloverPlan $plan, Collection $items, array $results, array $readyCandidates): array
    {
        $contexts = [];
        $itemsById = $items->keyBy('id');

        $readyItemIds = collect($results)->filter(fn ($r) => $r['result'] === self::RESULT_READY)->keys();
        $readySectionIds = $readyItemIds->map(fn ($id) => $readyCandidates[$id]['targetSectionId'])->unique()->all();
        $sectionsById = Section::query()->whereIn('id', $readySectionIds)->get()->keyBy('id');

        foreach ($readyItemIds as $itemId) {
            $section = $sectionsById->get($readyCandidates[$itemId]['targetSectionId']);

            if ($section === null) {
                continue;
            }

            $contexts[] = [
                'itemId' => $itemId,
                'targetAcademicYearId' => $plan->target_academic_year_id,
                'targetCampusId' => $section->campus_id,
                'targetGradeLevelId' => $section->grade_level_id,
                'targetEnrollmentId' => null,
            ];
        }

        $alreadyEnrolledItemIds = collect($results)->filter(fn ($r) => $r['result'] === self::RESULT_ALREADY_ENROLLED)->keys();
        $alreadyEnrolledStudentIds = $alreadyEnrolledItemIds->map(fn ($id) => $itemsById->get($id)->student_id)->unique()->all();
        $targetEnrollmentsByStudent = StudentEnrollment::query()
            ->where('status', 'active')
            ->where('academic_year_id', $plan->target_academic_year_id)
            ->whereIn('student_id', $alreadyEnrolledStudentIds)
            ->get()
            ->keyBy('student_id');

        foreach ($alreadyEnrolledItemIds as $itemId) {
            $item = $itemsById->get($itemId);
            $targetEnrollment = $targetEnrollmentsByStudent->get($item->student_id);

            if ($targetEnrollment === null) {
                continue;
            }

            $contexts[] = [
                'itemId' => $itemId,
                'targetAcademicYearId' => $targetEnrollment->academic_year_id,
                'targetCampusId' => $targetEnrollment->campus_id,
                'targetGradeLevelId' => $targetEnrollment->grade_level_id,
                'targetEnrollmentId' => $targetEnrollment->id,
            ];
        }

        return $contexts;
    }

    private function resolveRollNumber(EnrollmentRolloverItem $item, StudentEnrollment $freshSource): ?string
    {
        return RollNumberNormalizer::resolveForStrategy($item->roll_number_strategy, $item->target_roll_number, $freshSource->roll_number);
    }

    /**
     * @param  array<string, array<string, mixed>>  $results
     * @return array{total: int, ready: int, excluded: int, already_enrolled: int, review: int, blocked: int}
     */
    private function summarize(array $results): array
    {
        $counts = [
            self::RESULT_READY => 0,
            self::RESULT_EXCLUDED => 0,
            self::RESULT_ALREADY_ENROLLED => 0,
            self::RESULT_REVIEW => 0,
            self::RESULT_BLOCKED => 0,
        ];

        foreach ($results as $r) {
            $counts[$r['result']]++;
        }

        return ['total' => count($results), ...$counts];
    }
}
