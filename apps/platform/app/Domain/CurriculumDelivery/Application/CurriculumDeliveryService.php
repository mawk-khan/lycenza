<?php

namespace App\Domain\CurriculumDelivery\Application;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\CurriculumDelivery\Application\Exceptions\AcademicYearNotActiveException;
use App\Domain\CurriculumDelivery\Application\Exceptions\CompletionDateNotAllowedException;
use App\Domain\CurriculumDelivery\Application\Exceptions\CompletionDateRequiredException;
use App\Domain\CurriculumDelivery\Application\Exceptions\DeliveryContextMismatchException;
use App\Domain\CurriculumDelivery\Application\Exceptions\DeliveryDateInFutureException;
use App\Domain\CurriculumDelivery\Application\Exceptions\DeliveryDateOrderException;
use App\Domain\CurriculumDelivery\Application\Exceptions\DeliveryDateOutsideAcademicYearException;
use App\Domain\CurriculumDelivery\Application\Exceptions\DeliveryStatusChangedException;
use App\Domain\CurriculumDelivery\Application\Exceptions\DuplicateDeliveryException;
use App\Domain\CurriculumDelivery\Application\Exceptions\IllegalTransitionException;
use App\Domain\CurriculumDelivery\Application\Exceptions\NoOpTransitionException;
use App\Domain\CurriculumDelivery\Application\Exceptions\RequiredSubjectOfferingOnlyException;
use App\Domain\CurriculumDelivery\Infrastructure\CurriculumDelivery;
use App\Domain\Syllabus\Infrastructure\SyllabusUnit;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0H.3B -- the ONE sanctioned write path for
 * `curriculum_deliveries`. Neither the API controller nor the Inertia
 * controller writes the model directly (proven by
 * Tests\Feature\CurriculumDelivery\CurriculumDeliveryArchitectureGuardTest).
 *
 * A dedicated Application service is required here, unlike Phase
 * 0H.3A's SyllabusUnit which correctly used a thin controller
 * (CLAUDE.md rule 76 draws the line at "once real invariants exist").
 * All four of that rule's triggers are present:
 *
 *   1. multi-parent consistency -- Section, SyllabusUnit and the
 *      Unit's SubjectOffering must describe one coherent class;
 *   2. date validation -- School-local, not-future, inside the
 *      AcademicYear, correctly ordered;
 *   3. a state machine -- in_progress <-> completed, closed to exactly
 *      those two edges, with expected-status compare-and-swap;
 *   4. a transaction + row lock for that compare-and-swap.
 *
 * SERVER-DERIVED CONTEXT. Callers supply only the three facts a user
 * actually chooses -- `section_id`, `syllabus_unit_id`, `started_on`.
 * `subject_offering_id`, `academic_year_id`, `campus_id` and
 * `grade_level_id` are derived here from the RESOLVED parents and
 * written with forceFill(); they are never accepted from request data
 * (CLAUDE.md rule 19's principle applied to structural context, and
 * rule 68's "school_id never comes from request payload" extended to
 * every integrity pin). The database's three composite foreign keys
 * remain the final enforcement layer -- the checks here exist so the
 * ORDINARY mistake returns a clean 422 instead of a raw constraint
 * violation, exactly as Syllabus's `Rule::unique` does for codes.
 *
 * HISTORICAL-CORRECTION DISCIPLINE. Starting a NEW delivery requires
 * an active AcademicYear; correcting or transitioning an EXISTING one
 * deliberately does not, because a closed year must never make a
 * genuine clerical correction impossible. This is the identical rule
 * Attendance established (docs/modules/ATTENDANCE.md §13/§14).
 *
 * TWO AUTHORIZATION TIERS (TCH.3, ADR 0063 section 11). Callers authorize
 * first: Tier 1 holds the School-wide `curriculum.delivery.manage` and passes
 * no guard; Tier 2 (an owned-scope teacher) passes a DeliveryWriteGuard,
 * which this service runs INSIDE its transaction before the insert or row
 * lock -- so the identity and ownership holds (ActingEmployee, then
 * TeachingAssignment, both FOR SHARE) precede this module's own row lock,
 * the ADR 0063 section 20 order. Every rule below applies identically to
 * both tiers; there is no teacher copy of this logic.
 *
 * NO TenantLock, no advisory lock, no School-wide lock: there is no
 * multi-row invariant here. The only two races are a duplicate create
 * -- settled by `curriculum_deliveries_section_unit_unique` alone --
 * and a concurrent transition, settled by the row lock plus CAS.
 */
class CurriculumDeliveryService
{
    /**
     * The closed transition map. Both endpoints belonging to
     * CurriculumDelivery::STATUSES is deliberately NOT sufficient --
     * an edge must be listed here, so the machine can never silently
     * grow a new one if the vocabulary is ever extended.
     *
     * @var array<string, list<string>>
     */
    private const LEGAL_TRANSITIONS = [
        CurriculumDelivery::STATUS_IN_PROGRESS => [CurriculumDelivery::STATUS_COMPLETED],
        CurriculumDelivery::STATUS_COMPLETED => [CurriculumDelivery::STATUS_IN_PROGRESS],
    ];

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * Start recording delivery of one SyllabusUnit to one Section.
     * Always creates an `in_progress` row -- a delivery is never born
     * completed, because "started and finished on the same day" is
     * still a start followed by an explicit, audited completion.
     */
    public function start(
        School $school,
        string $subjectOfferingId,
        string $sectionId,
        string $syllabusUnitId,
        string $startedOn,
        User $actor,
        ?DeliveryWriteGuard $guard = null,
    ): CurriculumDelivery {
        return $this->context->withSchool($school, function () use ($school, $subjectOfferingId, $sectionId, $syllabusUnitId, $startedOn, $actor, $guard) {
            // Resolved through the tenant-scoped query (SchoolScope +
            // RLS), so another School's id is a clean 404 rather than a
            // silent mismatch later.
            $section = Section::query()->where('school_id', $school->id)->findOrFail($sectionId);
            $unit = SyllabusUnit::query()->where('school_id', $school->id)->findOrFail($syllabusUnitId);
            $offering = SubjectOffering::query()->where('school_id', $school->id)->findOrFail($subjectOfferingId);

            // The caller's Offering context must be the one that
            // actually owns the unit. Checked BEFORE any write, so a
            // mismatched request can never leave a stray row behind;
            // composite FK (3) would reject it at the database anyway.
            if ($unit->subject_offering_id !== $offering->id) {
                throw new DeliveryContextMismatchException('the SyllabusUnit belongs to a different SubjectOffering');
            }

            $this->assertOfferingIsRequired($offering);
            $this->assertSameContext($section, $offering);

            $year = AcademicYear::query()->where('school_id', $school->id)->findOrFail($offering->academic_year_id);

            if ($year->status !== 'active') {
                throw new AcademicYearNotActiveException($year->status);
            }

            $started = $this->assertUsableDate($school, $year, 'started_on', $startedOn);

            return DB::transaction(function () use ($school, $section, $unit, $offering, $started, $actor, $guard) {
                $guard?->beforeStart($school, $section, $offering, $started->toDateString());

                $delivery = new CurriculumDelivery;
                // Integrity pins are force-filled from resolved parents
                // and are absent from $fillable, so no mass assignment
                // path can reach them.
                $delivery->forceFill([
                    'school_id' => $school->id,
                    'section_id' => $section->id,
                    'syllabus_unit_id' => $unit->id,
                    'subject_offering_id' => $offering->id,
                    'academic_year_id' => $offering->academic_year_id,
                    'campus_id' => $offering->campus_id,
                    'grade_level_id' => $offering->grade_level_id,
                    'started_on' => $started->toDateString(),
                    'completed_on' => null,
                    'status' => CurriculumDelivery::STATUS_IN_PROGRESS,
                ]);

                try {
                    $delivery->save();
                } catch (UniqueConstraintViolationException $e) {
                    // ONLY this specific named constraint means "this
                    // Section already has a row for this Unit". Any
                    // other unique violation is genuinely unexpected
                    // and must stay a 500, never be mislabelled a
                    // duplicate.
                    if (! str_contains($e->getMessage(), 'curriculum_deliveries_section_unit_unique')) {
                        throw $e;
                    }

                    throw new DuplicateDeliveryException($section->id, $unit->id);
                }

                // Bounded metadata: ids and the start date only. The
                // SyllabusUnit's `title` is deliberately never copied
                // here -- an audit row must not become a second copy of
                // curriculum content (docs/modules/ACADEMICS.md §14).
                $this->audit->school($school, 'curriculum.delivery.created', actor: $actor, subject: $delivery, metadata: [
                    'deliveryId' => $delivery->id,
                    'sectionId' => $delivery->section_id,
                    'subjectOfferingId' => $delivery->subject_offering_id,
                    'syllabusUnitId' => $delivery->syllabus_unit_id,
                    'startedOn' => $delivery->started_on->toDateString(),
                ]);

                return $delivery;
            });
        });
    }

    /**
     * Correct a clerical mistake in either date. Never changes
     * `status` -- completing and reopening are transitions, not date
     * edits -- and deliberately RE-RUNS every applicable date invariant
     * rather than assuming an existing row was already valid.
     */
    public function correctDates(
        School $school,
        string $deliveryId,
        ?string $startedOn,
        ?string $completedOn,
        User $actor,
        ?DeliveryWriteGuard $guard = null,
    ): CurriculumDelivery {
        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $deliveryId, $startedOn, $completedOn, $actor, $guard) {
            $touched = fn (CurriculumDelivery $d): array => array_values(array_filter([
                $startedOn !== null ? $d->started_on->toDateString() : null,
                $startedOn,
                $completedOn !== null ? $d->completed_on?->toDateString() : null,
                $completedOn,
            ]));

            $delivery = $this->lockGuarded($school, $deliveryId, $guard, $touched);

            $year = AcademicYear::query()->where('school_id', $school->id)->findOrFail($delivery->academic_year_id);

            $before = [
                'startedOn' => $delivery->started_on->toDateString(),
                'completedOn' => $delivery->completed_on?->toDateString(),
            ];

            $changed = [];
            $newStarted = $before['startedOn'];
            $newCompleted = $before['completedOn'];

            if ($startedOn !== null) {
                $newStarted = $this->assertUsableDate($school, $year, 'started_on', $startedOn)->toDateString();
                $changed[] = 'started_on';
            }

            if ($completedOn !== null) {
                // A correction can never bring a row INTO the completed
                // state -- that is what the transition operation is for.
                if (! $delivery->isCompleted()) {
                    throw new CompletionDateNotAllowedException;
                }

                $newCompleted = $this->assertUsableDate($school, $year, 'completed_on', $completedOn)->toDateString();
                $changed[] = 'completed_on';
            }

            if ($newCompleted !== null && $newCompleted < $newStarted) {
                throw new DeliveryDateOrderException($newStarted, $newCompleted);
            }

            if ($changed === []) {
                return $delivery;
            }

            $delivery->forceFill([
                'started_on' => $newStarted,
                'completed_on' => $newCompleted,
            ])->save();

            $after = [
                'startedOn' => $newStarted,
                'completedOn' => $newCompleted,
            ];

            $this->audit->school($school, 'curriculum.delivery.updated', actor: $actor, subject: $delivery, metadata: [
                'deliveryId' => $delivery->id,
                // Field NAMES for the full change set...
                'changedFields' => $changed,
                // ...and before/after VALUES for the date fields only.
                // There is no other mutable field, and no free text
                // exists on this entity at all.
                'before' => $before,
                'after' => $after,
            ]);

            return $delivery->refresh();
        }));
    }

    /**
     * Expected-status compare-and-swap between the two stored states.
     * Completing requires the completion date; reopening clears it, so
     * the database's completion biconditional always holds.
     */
    public function transition(
        School $school,
        string $deliveryId,
        string $expectedStatus,
        string $newStatus,
        ?string $completedOn,
        User $actor,
        ?DeliveryWriteGuard $guard = null,
    ): CurriculumDelivery {
        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $deliveryId, $expectedStatus, $newStatus, $completedOn, $actor, $guard) {
            // Completing writes the completion date; reopening erases the
            // current one -- the dates a Tier 2 teacher must own.
            $touched = fn (CurriculumDelivery $d): array => array_filter(
                $newStatus === CurriculumDelivery::STATUS_COMPLETED ? [$completedOn] : [$d->completed_on?->toDateString()],
            );

            $delivery = $this->lockGuarded($school, $deliveryId, $guard, $touched);

            // CAS: evaluated only AFTER the row lock, so the value read
            // here is the committed current one, not a stale read.
            if ($delivery->status !== $expectedStatus) {
                throw new DeliveryStatusChangedException($expectedStatus, $delivery->status);
            }

            // Rejected rather than silently accepted: a no-op would
            // still write an audit row claiming a change that never
            // happened.
            if ($newStatus === $delivery->status) {
                throw new NoOpTransitionException($delivery->status);
            }

            if (! in_array($newStatus, self::LEGAL_TRANSITIONS[$delivery->status] ?? [], true)) {
                throw new IllegalTransitionException($delivery->status, $newStatus);
            }

            $previous = $delivery->status;
            $year = AcademicYear::query()->where('school_id', $school->id)->findOrFail($delivery->academic_year_id);

            if ($newStatus === CurriculumDelivery::STATUS_COMPLETED) {
                if ($completedOn === null) {
                    throw new CompletionDateRequiredException;
                }

                $completed = $this->assertUsableDate($school, $year, 'completed_on', $completedOn);

                if ($completed->toDateString() < $delivery->started_on->toDateString()) {
                    throw new DeliveryDateOrderException($delivery->started_on->toDateString(), $completed->toDateString());
                }

                $delivery->forceFill([
                    'status' => CurriculumDelivery::STATUS_COMPLETED,
                    'completed_on' => $completed->toDateString(),
                ])->save();
            } else {
                // Reopening always clears the completion date, so the
                // biconditional CHECK can never be violated.
                $delivery->forceFill([
                    'status' => CurriculumDelivery::STATUS_IN_PROGRESS,
                    'completed_on' => null,
                ])->save();
            }

            $this->audit->school($school, 'curriculum.delivery.transitioned', actor: $actor, subject: $delivery, metadata: [
                'deliveryId' => $delivery->id,
                'previousStatus' => $previous,
                'newStatus' => $delivery->status,
                'completedOn' => $delivery->completed_on?->toDateString(),
            ]);

            return $delivery->refresh();
        }));
    }

    /**
     * The row, FOR UPDATE. With a Tier 2 guard, the guard runs first on an
     * unlocked read (so identity and ownership are held before this row's
     * lock), then again only if the locked row's relevant dates changed in
     * between -- a concurrent correction can never slip a date past it.
     *
     * @param  callable(CurriculumDelivery): list<string>  $touched
     */
    private function lockGuarded(School $school, string $deliveryId, ?DeliveryWriteGuard $guard, callable $touched): CurriculumDelivery
    {
        $query = fn () => CurriculumDelivery::query()->where('id', $deliveryId)->where('school_id', $school->id);

        if ($guard === null) {
            return $query()->lockForUpdate()->firstOrFail();
        }

        $peek = $query()->firstOrFail();
        $guard->beforeChange($school, $peek, $touched($peek));

        $delivery = $query()->lockForUpdate()->firstOrFail();

        if ($touched($delivery) !== $touched($peek)) {
            $guard->beforeChange($school, $delivery, $touched($delivery));
        }

        return $delivery;
    }

    private function assertOfferingIsRequired(SubjectOffering $offering): void
    {
        if (! $offering->is_required) {
            throw new RequiredSubjectOfferingOnlyException($offering->id);
        }
    }

    /**
     * The application-layer mirror of composite FKs (1) and (2): the
     * Section and the Offering must sit in the SAME AcademicYear,
     * Campus and GradeLevel. The database rejects a mismatch outright
     * even via raw SQL; this turns the ordinary mistake into a 422.
     */
    private function assertSameContext(Section $section, SubjectOffering $offering): void
    {
        foreach ([
            'AcademicYear' => ['academic_year_id'],
            'Campus' => ['campus_id'],
            'GradeLevel' => ['grade_level_id'],
        ] as $label => [$column]) {
            if ($section->{$column} !== $offering->{$column}) {
                throw new DeliveryContextMismatchException("the Section and the SyllabusUnit's SubjectOffering are in different {$label}s");
            }
        }
    }

    /**
     * Every date invariant that does not depend on the other date:
     * parseable, not in the future in the SCHOOL's own timezone, and
     * inside the AcademicYear's inclusive [starts_on, ends_on].
     *
     * School-local rather than UTC deliberately: the user is entering a
     * school calendar date, and for a School in Asia/Kolkata the UTC
     * date is a different calendar day for several hours daily.
     * Attendance's existing UTC comparison is untouched by this
     * checkpoint.
     */
    private function assertUsableDate(School $school, AcademicYear $year, string $field, string $date): CarbonImmutable
    {
        $parsed = CarbonImmutable::parse($date)->startOfDay();
        $value = $parsed->toDateString();

        $todayInSchool = CarbonImmutable::now(SchoolTimezone::resolve($school))->toDateString();

        if ($value > $todayInSchool) {
            throw new DeliveryDateInFutureException($field, $value);
        }

        if ($value < $year->starts_on->toDateString() || $value > $year->ends_on->toDateString()) {
            throw new DeliveryDateOutsideAcademicYearException($field, $value);
        }

        return $parsed;
    }
}
