<?php

namespace App\Domain\LMS\Application;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\LMS\Application\Exceptions\AssignmentDueDateOutsideAcademicYearException;
use App\Domain\LMS\Application\Exceptions\AssignmentDueDateRequiredException;
use App\Domain\LMS\Application\Exceptions\AssignmentIllegalTransitionException;
use App\Domain\LMS\Application\Ownership\SectionAudience;
use App\Domain\LMS\Application\Ownership\SectionAudienceWriter;
use App\Domain\LMS\Infrastructure\Assignment;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0I.3 -- the ONE sanctioned write path for `assignments` (ADR
 * 0039). Neither the API controller nor the Inertia controller writes
 * the model directly (proven by
 * Tests\Feature\LMS\AssignmentArchitectureGuardTest). Structurally
 * mirrors App\Domain\LMS\Application\LearningContentService exactly --
 * same lifecycle-service justification (CLAUDE.md rule 76: the
 * transition state machine plus its aggregate-local lock), same
 * single-parent shape, same "ordinary edits never touch status"
 * split.
 *
 * LIFECYCLE. Exactly three legal transitions: draft->published,
 * published->closed, closed->published -- ADR 0039 §10's own frozen
 * contract ("closed -> published reopening is an ordinary status
 * transition, not a one-way door"). `assertLegalTransition()` is the
 * single, uniform check for all nine (status, status) pairs; no
 * separate no-op branch is needed because the legal-targets map for
 * each status never contains that same status (GradeScaleService's
 * exact precedent).
 *
 * DUE DATE. `due_on` may be null while `draft` (still being prepared)
 * but is REQUIRED before `publish()` -- the one real invariant this
 * service enforces at a lifecycle transition, the identical shape
 * GradeScaleService's `assertComplete()` already established for
 * `active`. When set, it must fall inside the owning SubjectOffering's
 * AcademicYear's inclusive range (mirroring
 * `AcademicTermService`/`ExaminationService`'s parent-range check) --
 * no not-in-the-past restriction, matching `Examination`'s own
 * "future dates permitted and expected" reasoning, since a due date is
 * inherently forward-looking. `due_on` may be changed by `update()` at
 * ANY status, including `published`/`closed` -- ADR 0039 §10's
 * explicit "no frozen-after-publish rule" for due date.
 *
 * EDITABILITY. Ordinary field edits (title/instructions/due_on) via
 * `update()` are permitted at ANY status, mirroring
 * LearningContentService's identical "no frozen-after-publish rule"
 * precedent. `update()` never accepts `status` -- a transition always
 * goes through the dedicated `publish()`/`close()` methods.
 *
 * CONCURRENCY. Every mutating operation against an EXISTING Assignment
 * reloads it with `lockForUpdate()` inside `DB::transaction()` -- the
 * same aggregate-local row lock GradeScaleService/LearningContentService
 * already use, never a School-wide `TenantLock`.
 */
class AssignmentService
{
    /**
     * @var array<string, list<string>>
     */
    private const LEGAL_TRANSITIONS = [
        Assignment::STATUS_DRAFT => [Assignment::STATUS_PUBLISHED],
        Assignment::STATUS_PUBLISHED => [Assignment::STATUS_CLOSED],
        Assignment::STATUS_CLOSED => [Assignment::STATUS_PUBLISHED],
    ];

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly SectionAudienceWriter $audiences,
    ) {}

    /**
     * Administrative (Tier 1) creation, or -- given a SectionAudience -- a
     * teacher-owned row written by trusted internal code. Every route calls
     * it without one, so administrative rows stay Offering-wide.
     *
     * @param  array{title: string, instructions?: string|null, due_on?: string|null}  $attributes
     */
    public function create(School $school, string $subjectOfferingId, array $attributes, User $actor, ?SectionAudience $audience = null): Assignment
    {
        return $this->context->withSchool($school, function () use ($school, $subjectOfferingId, $attributes, $actor, $audience) {
            // Resolved through the tenant-scoped query (SchoolScope +
            // RLS), so another School's Offering id is a clean 404 here
            // rather than an empty/misleading later failure.
            $offering = SubjectOffering::query()->where('school_id', $school->id)->findOrFail($subjectOfferingId);

            $dueOn = null;
            if (! empty($attributes['due_on'])) {
                $dueOn = $this->assertDueDateWithinYear($school, $offering, $attributes['due_on']);
            }

            return $this->audiences->refusingForeignOwner('assignments', fn () => DB::transaction(
                fn () => $this->insert($school, $offering, $attributes, $dueOn, $actor, $audience),
            ));
        });
    }

    /**
     * TCH.5D (ADR 0063 section 37) -- a teacher-owned Assignment. The guard
     * runs first in this transaction: it holds the ActingEmployee and the
     * TeachingAssignment of every requested Section and returns the ownership
     * -- the owner is always the ActingEmployee, never client input. Only
     * then is the (informational) due date validated, so an untaught
     * Offering is a 404 before anything else. The same insert() as
     * administrative creation writes the row and its audience atomically.
     *
     * @param  array{title: string, instructions?: string|null, due_on?: string|null}  $attributes
     * @param  list<string>  $sectionIds
     */
    public function createOwned(School $school, string $subjectOfferingId, array $attributes, array $sectionIds, User $actor, AssignmentWriteGuard $guard): Assignment
    {
        return $this->context->withSchool($school, function () use ($school, $subjectOfferingId, $attributes, $sectionIds, $actor, $guard) {
            $offering = SubjectOffering::query()->where('school_id', $school->id)->findOrFail($subjectOfferingId);

            return $this->audiences->refusingForeignOwner('assignments', fn () => DB::transaction(function () use ($school, $offering, $attributes, $sectionIds, $actor, $guard) {
                $audience = $guard->beforeCreate($school, $offering, $sectionIds);
                $dueOn = empty($attributes['due_on']) ? null : $this->assertDueDateWithinYear($school, $offering, $attributes['due_on']);

                return $this->insert($school, $offering, $attributes, $dueOn, $actor, $audience);
            }));
        });
    }

    /** @param  array{title: string, instructions?: string|null, due_on?: string|null}  $attributes */
    private function insert(School $school, SubjectOffering $offering, array $attributes, ?string $dueOn, User $actor, ?SectionAudience $audience): Assignment
    {
        $assignment = new Assignment;
        $assignment->forceFill([
            'school_id' => $school->id,
            'subject_offering_id' => $offering->id,
            'title' => $attributes['title'],
            'instructions' => $attributes['instructions'] ?? null,
            'due_on' => $dueOn,
            'status' => Assignment::STATUS_DRAFT,
            // TCH.5B: an owner only for a teacher-owned row, whose Section
            // audience is written in this same transaction. Without a
            // SectionAudience the row is Offering-wide (owner NULL, no audience).
            'owner_employee_id' => $audience?->ownerEmployeeId,
        ]);
        $assignment->save();

        if ($audience !== null) {
            $this->audiences->attach($assignment, $offering, $audience);
        }

        // Bounded metadata: ids and the due date only. `title`/`instructions`
        // are School-authored content and are deliberately never copied into
        // audit metadata. The actor is always the authenticated User.
        $this->audit->school($school, 'lms.assignment.created', actor: $actor, subject: $assignment, metadata: [
            'assignmentId' => $assignment->id,
            'subjectOfferingId' => $assignment->subject_offering_id,
            'dueOn' => $assignment->due_on?->toDateString(),
        ] + ($audience === null ? [] : [
            'ownerEmployeeId' => $audience->ownerEmployeeId,
            'audienceSectionIds' => $audience->sectionIds,
        ]));

        return $assignment;
    }

    /**
     * Ordinary field edit -- title/instructions/due_on only, at ANY
     * status. Never accepts `status`: a lifecycle change always goes
     * through `publish()`/`close()`.
     *
     * @param  array{title?: string, instructions?: string|null, due_on?: string|null}  $attributes
     */
    public function update(School $school, Assignment $assignment, array $attributes, User $actor, ?AssignmentWriteGuard $guard = null): Assignment
    {
        return $this->context->withSchool($school, function () use ($school, $assignment, $attributes, $actor, $guard) {
            $offering = SubjectOffering::query()->where('school_id', $school->id)->findOrFail($assignment->subject_offering_id);

            $dueOnProvided = array_key_exists('due_on', $attributes);
            $dueOn = $dueOnProvided && $attributes['due_on'] !== null
                ? $this->assertDueDateWithinYear($school, $offering, $attributes['due_on'])
                : null;

            return DB::transaction(function () use ($school, $assignment, $attributes, $dueOnProvided, $dueOn, $actor, $guard) {
                // TCH.5D Tier 2 only: identity and ownership held before the row lock.
                $guard?->beforeWrite($school, $assignment);

                $locked = Assignment::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();

                $changedFields = array_keys($attributes);

                if ($changedFields === []) {
                    return $locked;
                }

                $toFill = array_intersect_key($attributes, array_flip(['title', 'instructions']));
                if ($dueOnProvided) {
                    $toFill['due_on'] = $dueOn;
                }

                $locked->forceFill($toFill);
                $locked->save();

                $this->audit->school($school, 'lms.assignment.updated', actor: $actor, subject: $locked, metadata: [
                    'assignmentId' => $locked->id,
                    // Field NAMES only -- `title`/`instructions` VALUES
                    // never appear in audit metadata; `due_on` is a
                    // bounded administrative fact and safe to record.
                    'changedFields' => $changedFields,
                    'dueOn' => $locked->due_on?->toDateString(),
                ]);

                return $locked->refresh();
            });
        });
    }

    /**
     * Publishes the Assignment -- legal from `draft` (first
     * publication) or `closed` (reopening), matching
     * LearningContentService's identical two-verb (publish/close)
     * design rather than a separate "reopen" action.
     */
    public function publish(School $school, Assignment $assignment, User $actor, ?AssignmentWriteGuard $guard = null): Assignment
    {
        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $assignment, $actor, $guard) {
            $guard?->beforeWrite($school, $assignment);

            $locked = Assignment::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();

            $this->assertLegalTransition($locked->status, Assignment::STATUS_PUBLISHED);

            if ($locked->due_on === null) {
                throw new AssignmentDueDateRequiredException;
            }

            $previous = $locked->status;
            $locked->forceFill(['status' => Assignment::STATUS_PUBLISHED])->save();

            $this->audit->school($school, 'lms.assignment.published', actor: $actor, subject: $locked, metadata: [
                'assignmentId' => $locked->id,
                'previousStatus' => $previous,
                'newStatus' => $locked->status,
            ]);

            return $locked->refresh();
        }));
    }

    /**
     * Closes the Assignment -- legal from `published` only. The row is
     * never deleted (rule 73, ADR 0039 §10): a closed Assignment
     * remains readable/correctable, and can be republished later.
     */
    public function close(School $school, Assignment $assignment, User $actor, ?AssignmentWriteGuard $guard = null): Assignment
    {
        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $assignment, $actor, $guard) {
            $guard?->beforeWrite($school, $assignment);

            $locked = Assignment::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();

            $this->assertLegalTransition($locked->status, Assignment::STATUS_CLOSED);

            $previous = $locked->status;
            $locked->forceFill(['status' => Assignment::STATUS_CLOSED])->save();

            $this->audit->school($school, 'lms.assignment.closed', actor: $actor, subject: $locked, metadata: [
                'assignmentId' => $locked->id,
                'previousStatus' => $previous,
                'newStatus' => $locked->status,
            ]);

            return $locked->refresh();
        }));
    }

    private function assertLegalTransition(string $from, string $to): void
    {
        if (! in_array($to, self::LEGAL_TRANSITIONS[$from] ?? [], true)) {
            throw new AssignmentIllegalTransitionException($from, $to);
        }
    }

    /**
     * The only date invariant this service enforces: `due_on`, when
     * given, must fall inside the owning SubjectOffering's
     * AcademicYear's inclusive [starts_on, ends_on] range. Deliberately
     * NO not-in-the-past check -- a due date is scheduled ahead by
     * nature, the same "future dates permitted and expected" reasoning
     * `Examination` already established (unlike `CurriculumDelivery`,
     * a record of what already happened).
     */
    private function assertDueDateWithinYear(School $school, SubjectOffering $offering, string $dueOn): string
    {
        $parsed = CarbonImmutable::parse($dueOn)->startOfDay();
        $value = $parsed->toDateString();

        $year = AcademicYear::query()->where('school_id', $school->id)->findOrFail($offering->academic_year_id);

        if ($value < $year->starts_on->toDateString() || $value > $year->ends_on->toDateString()) {
            throw new AssignmentDueDateOutsideAcademicYearException($value);
        }

        return $value;
    }
}
