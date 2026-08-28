<?php

namespace App\Domain\Students\Application;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Students\Application\Exceptions\CrossSchoolRolloverPlanException;
use App\Domain\Students\Application\Exceptions\CrossSchoolSubjectMappingException;
use App\Domain\Students\Application\Exceptions\InvalidRollNumberStrategyException;
use App\Domain\Students\Application\Exceptions\InvalidRolloverItemDecisionException;
use App\Domain\Students\Application\Exceptions\InvalidRolloverPlanYearsException;
use App\Domain\Students\Application\Exceptions\InvalidSubjectMappingYearException;
use App\Domain\Students\Application\Exceptions\OpenRolloverPlanConflictException;
use App\Domain\Students\Application\Exceptions\RequiredSubjectOfferingRolloverMappingException;
use App\Domain\Students\Application\Exceptions\RolloverItemAlreadyExecutedException;
use App\Domain\Students\Application\Exceptions\RolloverPlanNoLongerConfigurableException;
use App\Domain\Students\Infrastructure\EnrollmentRolloverItem;
use App\Domain\Students\Infrastructure\EnrollmentRolloverMapping;
use App\Domain\Students\Infrastructure\EnrollmentRolloverPlan;
use App\Domain\Students\Infrastructure\EnrollmentRolloverSubjectMapping;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase 1B.7A/1B.7B: the ONLY sanctioned write path for an
 * EnrollmentRolloverPlan's header (`createDraft()`) AND for the plan's
 * configuration (`upsertMapping()`/`setItemDecision()`) -- both
 * configuration methods increment `configuration_version` in the SAME
 * transaction as their write, the one mechanism the accepted
 * architecture relies on to invalidate a prior dry-run
 * (docs/modules/STUDENT-ENROLLMENT.md, "Validation invalidation").
 * Never mutate a mapping/item row directly from anywhere else --
 * `EnrollmentRolloverItem`/`EnrollmentRolloverMapping` population
 * during dry-run (`EnrollmentRolloverDryRunService`) is the one
 * deliberate exception, and it never touches `configuration_version`
 * (discovering a new eligible Student is not an operator decision).
 *
 * Deliberately authorization-neutral, matching every other Application
 * service in this codebase -- a future controller must call
 * `Gate::authorize`/`authorizeCapability` before ever reaching this
 * service; no capability check lives here.
 */
class EnrollmentRolloverPlanService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * Creates a new plan in `draft` status for the given source/target
     * AcademicYear pair. Does NOT validate chronology (which year is
     * "earlier") -- the accepted architecture explicitly defers that to
     * a future dry-run step rather than guessing at calendar
     * conventions here; it DOES enforce that both years belong to the
     * same School as the plan and that they are not identical, exactly
     * like `StudentEnrollmentService::enroll()`'s own same-School check
     * runs before any write is attempted.
     */
    public function createDraft(School $school, AcademicYear $sourceYear, AcademicYear $targetYear, ?User $actor = null): EnrollmentRolloverPlan
    {
        if ($sourceYear->school_id !== $school->id || $targetYear->school_id !== $school->id) {
            throw new CrossSchoolRolloverPlanException;
        }

        if ($sourceYear->id === $targetYear->id) {
            throw new InvalidRolloverPlanYearsException;
        }

        return $this->context->withSchool($school, function () use ($school, $sourceYear, $targetYear, $actor) {
            try {
                return DB::transaction(function () use ($school, $sourceYear, $targetYear, $actor) {
                    $plan = EnrollmentRolloverPlan::query()->create([
                        'school_id' => $school->id,
                        'source_academic_year_id' => $sourceYear->id,
                        'target_academic_year_id' => $targetYear->id,
                        'status' => 'draft',
                        'configuration_version' => 1,
                    ]);

                    $this->audit->school($school, 'enrollment_rollover_plan.created', actor: $actor, subject: $plan, metadata: [
                        'sourceAcademicYearId' => $sourceYear->id,
                        'targetAcademicYearId' => $targetYear->id,
                    ]);

                    return $plan;
                });
            } catch (UniqueConstraintViolationException $e) {
                throw $this->translateUniqueViolation($e);
            }
        });
    }

    /**
     * Creates (or updates) either a Grade-level default mapping
     * (`$sourceSection === null`) or a Section-specific override
     * (`$sourceSection` given) -- one row shape, two granularities,
     * exactly per the accepted architecture. `$targetSection === null`
     * is valid -- it fixes only the target Grade, deferring the exact
     * target Section to a future per-Item override.
     */
    public function upsertMapping(
        EnrollmentRolloverPlan $plan,
        GradeLevel $sourceGradeLevel,
        ?Section $sourceSection,
        GradeLevel $targetGradeLevel,
        ?Section $targetSection,
        ?User $actor = null,
    ): EnrollmentRolloverMapping {
        $this->assertConfigurable($plan);

        foreach ([$sourceGradeLevel, $targetGradeLevel] as $gradeLevel) {
            if ($gradeLevel->school_id !== $plan->school_id) {
                throw new CrossSchoolRolloverPlanException;
            }
        }
        foreach (array_filter([$sourceSection, $targetSection]) as $section) {
            if ($section->school_id !== $plan->school_id) {
                throw new CrossSchoolRolloverPlanException;
            }
        }

        return $this->context->withSchool($plan->school, function () use ($plan, $sourceGradeLevel, $sourceSection, $targetGradeLevel, $targetSection, $actor) {
            return DB::transaction(function () use ($plan, $sourceGradeLevel, $sourceSection, $targetGradeLevel, $targetSection, $actor) {
                $lockedPlan = EnrollmentRolloverPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

                $mapping = EnrollmentRolloverMapping::query()->updateOrCreate(
                    [
                        'plan_id' => $plan->id,
                        'source_grade_level_id' => $sourceGradeLevel->id,
                        'source_section_id' => $sourceSection?->id,
                    ],
                    [
                        'school_id' => $plan->school_id,
                        'target_grade_level_id' => $targetGradeLevel->id,
                        'target_section_id' => $targetSection?->id,
                    ],
                );

                $lockedPlan->increment('configuration_version');

                $this->audit->school($plan->school, 'enrollment_rollover_plan.configuration_changed', actor: $actor, subject: $lockedPlan, metadata: [
                    'change' => 'mapping',
                    'sourceGradeLevelId' => $sourceGradeLevel->id,
                    'sourceSectionId' => $sourceSection?->id,
                ]);

                return $mapping;
            });
        });
    }

    /**
     * Sets one Item's operator-controlled configuration -- decision,
     * target Section override, Roll Number strategy/value. Never
     * touched by dry-run's own population step (which only ever
     * INSERTS a brand-new Item with `decision = 'undecided'` and
     * leaves every configuration field exactly as the DB default
     * until an operator calls this method).
     *
     * Phase 1B.7C: refuses once the Item has already produced a real
     * target Enrollment (`target_enrollment_id` set) -- reconfiguring
     * an executed Item would silently orphan its provenance link, the
     * exact corruption `target_enrollment_id`'s FK integrity exists to
     * prevent (see RolloverItemAlreadyExecutedException).
     */
    public function setItemDecision(
        EnrollmentRolloverPlan $plan,
        EnrollmentRolloverItem $item,
        string $decision,
        ?Section $targetSectionOverride = null,
        ?string $rollNumberStrategy = null,
        ?string $targetRollNumber = null,
        ?User $actor = null,
    ): EnrollmentRolloverItem {
        $this->assertConfigurable($plan);

        if ($item->target_enrollment_id !== null) {
            throw new RolloverItemAlreadyExecutedException;
        }

        if (! in_array($decision, ['undecided', 'promote', 'repeat', 'exclude', 'manual_review'], true)) {
            throw new InvalidRolloverItemDecisionException($decision);
        }
        if ($rollNumberStrategy !== null && ! in_array($rollNumberStrategy, ['explicit', 'preserve_source'], true)) {
            throw new InvalidRollNumberStrategyException($rollNumberStrategy);
        }
        if ($targetSectionOverride !== null && $targetSectionOverride->school_id !== $plan->school_id) {
            throw new CrossSchoolRolloverPlanException;
        }

        return $this->context->withSchool($plan->school, function () use ($plan, $item, $decision, $targetSectionOverride, $rollNumberStrategy, $targetRollNumber, $actor) {
            return DB::transaction(function () use ($plan, $item, $decision, $targetSectionOverride, $rollNumberStrategy, $targetRollNumber, $actor) {
                $lockedPlan = EnrollmentRolloverPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

                $item->update([
                    'decision' => $decision,
                    'target_section_id' => $targetSectionOverride?->id,
                    'roll_number_strategy' => $rollNumberStrategy,
                    'target_roll_number' => $targetRollNumber,
                ]);

                $lockedPlan->increment('configuration_version');

                $this->audit->school($plan->school, 'enrollment_rollover_plan.configuration_changed', actor: $actor, subject: $lockedPlan, metadata: [
                    'change' => 'item_decision',
                    'itemId' => $item->id,
                ]);

                return $item->refresh();
            });
        });
    }

    /**
     * Phase 1G.1: creates (or updates) an explicit source SubjectOffering
     * -> target SubjectOffering elective carry-forward mapping.
     * `$target === null` is a valid, DISTINCT state from "no mapping
     * row at all" -- it means the operator explicitly chose NOT to
     * carry this elective forward (explicit omit), never "unconfigured"
     * (see this table's migration for the full three-state rationale).
     *
     * Idempotent by design (deliberately stronger than
     * `upsertMapping()`'s own always-bump precedent, because this
     * checkpoint's brief explicitly calls for a true no-op here): a
     * call that would leave the mapping's target unchanged (including
     * omit -> omit) makes no write, bumps no `configuration_version`,
     * and records no audit event.
     */
    public function upsertSubjectMapping(
        EnrollmentRolloverPlan $plan,
        SubjectOffering $source,
        ?SubjectOffering $target,
        ?User $actor = null,
    ): EnrollmentRolloverSubjectMapping {
        $this->assertConfigurable($plan);

        $this->assertSubjectMappingSchoolAndYear($plan, $source, $target);

        if ($source->is_required || $target?->is_required) {
            throw new RequiredSubjectOfferingRolloverMappingException;
        }

        return $this->context->withSchool($plan->school, function () use ($plan, $source, $target, $actor) {
            return DB::transaction(function () use ($plan, $source, $target, $actor) {
                $lockedPlan = EnrollmentRolloverPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

                $existing = EnrollmentRolloverSubjectMapping::query()
                    ->where('plan_id', $plan->id)
                    ->where('source_subject_offering_id', $source->id)
                    ->first();

                if ($existing !== null && $existing->target_subject_offering_id === $target?->id) {
                    return $existing;
                }

                $mapping = EnrollmentRolloverSubjectMapping::query()->updateOrCreate(
                    [
                        'plan_id' => $plan->id,
                        'source_subject_offering_id' => $source->id,
                    ],
                    [
                        'school_id' => $plan->school_id,
                        'target_subject_offering_id' => $target?->id,
                    ],
                );

                $lockedPlan->increment('configuration_version');

                $this->audit->school($plan->school, 'enrollment_rollover_plan.configuration_changed', actor: $actor, subject: $lockedPlan, metadata: [
                    'change' => 'subject_mapping',
                    'subjectMappingId' => $mapping->id,
                    'sourceSubjectOfferingId' => $source->id,
                    'targetSubjectOfferingId' => $target?->id,
                ]);

                return $mapping;
            });
        });
    }

    /**
     * Phase 1G.1: returns a source SubjectOffering to UNCONFIGURED --
     * distinct from `upsertSubjectMapping($plan, $source, null, ...)`,
     * which records an explicit OMIT row. This requires deleting the
     * mapping row entirely; without it the three-state model would have
     * no way back to its first state. Idempotent: removing an
     * already-unconfigured source Offering is a no-op (no version bump,
     * no audit), mirroring `upsertSubjectMapping()`'s own no-op
     * precedent.
     */
    public function removeSubjectMapping(
        EnrollmentRolloverPlan $plan,
        SubjectOffering $source,
        ?User $actor = null,
    ): void {
        $this->assertConfigurable($plan);

        if ($source->school_id !== $plan->school_id) {
            throw new CrossSchoolSubjectMappingException;
        }

        $this->context->withSchool($plan->school, function () use ($plan, $source, $actor) {
            DB::transaction(function () use ($plan, $source, $actor) {
                $lockedPlan = EnrollmentRolloverPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

                $mapping = EnrollmentRolloverSubjectMapping::query()
                    ->where('plan_id', $plan->id)
                    ->where('source_subject_offering_id', $source->id)
                    ->first();

                if ($mapping === null) {
                    return;
                }

                $mappingId = $mapping->id;
                $mapping->delete();

                $lockedPlan->increment('configuration_version');

                $this->audit->school($plan->school, 'enrollment_rollover_plan.configuration_changed', actor: $actor, subject: $lockedPlan, metadata: [
                    'change' => 'subject_mapping_removed',
                    'subjectMappingId' => $mappingId,
                    'sourceSubjectOfferingId' => $source->id,
                ]);
            });
        });
    }

    /**
     * Shared same-School / source-year / target-year validation for
     * `upsertSubjectMapping()`, checked BEFORE any write is attempted
     * (defense-in-depth ahead of the composite FKs -- see this table's
     * migration for exactly why year membership is application-,
     * rather than database-, validated).
     */
    private function assertSubjectMappingSchoolAndYear(EnrollmentRolloverPlan $plan, SubjectOffering $source, ?SubjectOffering $target): void
    {
        if ($source->school_id !== $plan->school_id) {
            throw new CrossSchoolSubjectMappingException;
        }

        if ($target !== null && $target->school_id !== $plan->school_id) {
            throw new CrossSchoolSubjectMappingException;
        }

        if ($source->academic_year_id !== $plan->source_academic_year_id) {
            throw new InvalidSubjectMappingYearException('source');
        }

        if ($target !== null && $target->academic_year_id !== $plan->target_academic_year_id) {
            throw new InvalidSubjectMappingYearException('target');
        }
    }

    /**
     * Shared gate for every configuration mutation AND for dry-run
     * itself (`EnrollmentRolloverDryRunService::run()` calls this too)
     * -- both share the identical allowed-status set (`draft`/
     * `validated`), per the accepted Plan lifecycle.
     */
    public function assertConfigurable(EnrollmentRolloverPlan $plan): void
    {
        if (! in_array($plan->status, ['draft', 'validated'], true)) {
            throw new RolloverPlanNoLongerConfigurableException;
        }
    }

    private function translateUniqueViolation(UniqueConstraintViolationException $e): Throwable
    {
        return match ($e->index) {
            'enrollment_rollover_plans_one_open_per_year_pair' => new OpenRolloverPlanConflictException,
            default => $e,
        };
    }
}
