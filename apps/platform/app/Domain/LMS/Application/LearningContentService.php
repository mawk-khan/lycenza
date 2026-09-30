<?php

namespace App\Domain\LMS\Application;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\LMS\Application\Exceptions\LearningContentIllegalTransitionException;
use App\Domain\LMS\Application\Ownership\SectionAudience;
use App\Domain\LMS\Application\Ownership\SectionAudienceWriter;
use App\Domain\LMS\Infrastructure\LearningContent;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0I.2 -- the ONE sanctioned write path for `learning_content`
 * (ADR 0039). Neither the API controller nor the Inertia controller
 * writes the model directly (proven by
 * Tests\Feature\LMS\LearningContentArchitectureGuardTest).
 *
 * A dedicated Application service is required here, unlike Phase
 * 0H.3A's SyllabusUnit which correctly used a thin controller
 * (CLAUDE.md rule 76 draws the line at "once real invariants exist").
 * The trigger present here is the lifecycle state machine plus the
 * aggregate-local lock its transitions need -- the identical shape ADR
 * 0035's GradeScaleService already established (a single-row aggregate
 * with a closed transition map, not CurriculumDelivery's multi-parent
 * consistency + date validation). There is no cross-parent invariant:
 * `subject_offering_id` is this entity's only parent (mirroring
 * SyllabusUnit exactly), so no `assertSameContext()`-shaped check
 * exists here.
 *
 * LIFECYCLE. Exactly three legal transitions:
 * draft->published, published->archived, archived->published. Every
 * other transition, including every no-op, is illegal --
 * `assertLegalTransition()` is the single, uniform check for all nine
 * (status, status) pairs; no separate no-op branch is needed because
 * the legal-targets map for each status never contains that same
 * status (GradeScaleService's exact precedent).
 *
 * EDITABILITY. Ordinary field edits (title/description/sequence) via
 * `update()` are permitted at ANY status, including `archived` --
 * mirroring the "no frozen-after-publish rule" every reference-entity
 * precedent in this codebase already establishes (Examination/
 * ExaminationPaper/SyllabusUnit all permit correction post-publication)
 * and CurriculumDelivery's own "a closed year must never make a
 * genuine clerical correction impossible" historical-correction
 * discipline extended to status generally. `update()` never accepts
 * `status` -- a transition is always requested through the dedicated
 * `transition()` method, matching CurriculumDeliveryService's identical
 * split (PATCH never accepts status).
 *
 * CONCURRENCY. Every mutating operation against an EXISTING
 * LearningContent reloads it with `lockForUpdate()` inside
 * `DB::transaction()` before checking status or mutating anything -- a
 * genuine PostgreSQL row lock scoped to exactly one LearningContent
 * row, never a School-wide `TenantLock` (which would needlessly
 * serialize unrelated LearningContent rows against each other). This is
 * the same mechanism `GradeScaleService`/`CurriculumDeliveryService`
 * already use for their own aggregate-local CAS.
 */
class LearningContentService
{
    /**
     * @var array<string, list<string>>
     */
    private const LEGAL_TRANSITIONS = [
        LearningContent::STATUS_DRAFT => [LearningContent::STATUS_PUBLISHED],
        LearningContent::STATUS_PUBLISHED => [LearningContent::STATUS_ARCHIVED],
        LearningContent::STATUS_ARCHIVED => [LearningContent::STATUS_PUBLISHED],
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
     * @param  array{title: string, description?: string|null, sequence?: int}  $attributes
     */
    public function create(School $school, string $subjectOfferingId, array $attributes, User $actor, ?SectionAudience $audience = null): LearningContent
    {
        return $this->context->withSchool($school, function () use ($school, $subjectOfferingId, $attributes, $actor, $audience) {
            // Resolved through the tenant-scoped query (SchoolScope +
            // RLS), so another School's Offering id is a clean 404 here
            // rather than an empty/misleading later failure.
            $offering = SubjectOffering::query()->where('school_id', $school->id)->findOrFail($subjectOfferingId);

            return $this->audiences->refusingForeignOwner('learning_content', fn () => DB::transaction(
                fn () => $this->insert($school, $offering, $attributes, $actor, $audience),
            ));
        });
    }

    /**
     * TCH.5C (ADR 0063 section 36) -- a teacher-owned row. The guard runs in
     * this transaction: it holds the ActingEmployee and the TeachingAssignment
     * of every requested Section, and returns the ownership -- the owner is
     * always the ActingEmployee, never client input. The same insert() as
     * administrative creation writes the row and its audience atomically.
     *
     * @param  array{title: string, description?: string|null, sequence?: int}  $attributes
     * @param  list<string>  $sectionIds
     */
    public function createOwned(School $school, string $subjectOfferingId, array $attributes, array $sectionIds, User $actor, LearningContentWriteGuard $guard): LearningContent
    {
        return $this->context->withSchool($school, function () use ($school, $subjectOfferingId, $attributes, $sectionIds, $actor, $guard) {
            $offering = SubjectOffering::query()->where('school_id', $school->id)->findOrFail($subjectOfferingId);

            return $this->audiences->refusingForeignOwner('learning_content', fn () => DB::transaction(
                fn () => $this->insert($school, $offering, $attributes, $actor, $guard->beforeCreate($school, $offering, $sectionIds)),
            ));
        });
    }

    /** @param  array{title: string, description?: string|null, sequence?: int}  $attributes */
    private function insert(School $school, SubjectOffering $offering, array $attributes, User $actor, ?SectionAudience $audience): LearningContent
    {
        $content = new LearningContent;
        $content->forceFill([
            'school_id' => $school->id,
            'subject_offering_id' => $offering->id,
            'title' => $attributes['title'],
            'description' => $attributes['description'] ?? null,
            'sequence' => $attributes['sequence'] ?? 0,
            'status' => LearningContent::STATUS_DRAFT,
            // TCH.5B: an owner only for a teacher-owned row, whose Section
            // audience is written in this same transaction. Without a
            // SectionAudience the row is Offering-wide (owner NULL, no audience).
            'owner_employee_id' => $audience?->ownerEmployeeId,
        ]);
        $content->save();

        if ($audience !== null) {
            $this->audiences->attach($content, $offering, $audience);
        }

        // Bounded metadata: ids and sequence only. `title`/`description` are
        // School-authored content and are deliberately never copied into
        // audit metadata -- an audit row must not become a second copy of
        // instructional content (docs/modules/ACADEMICS.md §14's rule
        // applied here). The actor is always the authenticated User.
        $this->audit->school($school, 'lms.learning_content.created', actor: $actor, subject: $content, metadata: [
            'learningContentId' => $content->id,
            'subjectOfferingId' => $content->subject_offering_id,
            'sequence' => $content->sequence,
        ] + ($audience === null ? [] : [
            'ownerEmployeeId' => $audience->ownerEmployeeId,
            'audienceSectionIds' => $audience->sectionIds,
        ]));

        return $content;
    }

    /**
     * Ordinary field edit -- title/description/sequence only, at ANY
     * status. Never accepts `status`: a lifecycle change always goes
     * through `transition()`.
     *
     * @param  array{title?: string, description?: string|null, sequence?: int}  $attributes
     */
    public function update(School $school, LearningContent $content, array $attributes, User $actor, ?LearningContentWriteGuard $guard = null): LearningContent
    {
        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $content, $attributes, $actor, $guard) {
            // TCH.5C Tier 2 only: identity and ownership held before the row lock.
            $guard?->beforeWrite($school, $content);

            $locked = LearningContent::query()->whereKey($content->id)->lockForUpdate()->firstOrFail();

            $changedFields = array_keys($attributes);

            if ($changedFields === []) {
                return $locked;
            }

            $locked->forceFill(array_intersect_key($attributes, array_flip(['title', 'description', 'sequence'])));
            $locked->save();

            $this->audit->school($school, 'lms.learning_content.updated', actor: $actor, subject: $locked, metadata: [
                'learningContentId' => $locked->id,
                // Field NAMES only -- `title`/`description` VALUES never
                // appear in audit metadata; `sequence` is a bounded
                // administrative fact and safe to record by value.
                'changedFields' => $changedFields,
                'sequence' => $locked->sequence,
            ]);

            return $locked->refresh();
        }));
    }

    /**
     * Makes this content the live/current one -- legal from `draft`
     * (first publication) or `archived` (reinstatement). Both land on
     * the same `published` state, distinguished only by `fromStatus` in
     * the audit metadata; there is no separate "reinstate" action,
     * keeping the API surface to exactly two lifecycle verbs
     * (publish/archive) rather than three.
     */
    public function publish(School $school, LearningContent $content, User $actor, ?LearningContentWriteGuard $guard = null): LearningContent
    {
        return $this->transition($school, $content, LearningContent::STATUS_PUBLISHED, 'lms.learning_content.published', $actor, $guard);
    }

    /**
     * Retires this content -- legal from `published` only. The row is
     * never deleted (rule 73, ADR 0039 decision 9): archived content
     * remains readable/correctable, and can be published again later.
     */
    public function archive(School $school, LearningContent $content, User $actor, ?LearningContentWriteGuard $guard = null): LearningContent
    {
        return $this->transition($school, $content, LearningContent::STATUS_ARCHIVED, 'lms.learning_content.archived', $actor, $guard);
    }

    private function transition(School $school, LearningContent $content, string $newStatus, string $auditEvent, User $actor, ?LearningContentWriteGuard $guard): LearningContent
    {
        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $content, $newStatus, $auditEvent, $actor, $guard) {
            $guard?->beforeWrite($school, $content);

            $locked = LearningContent::query()->whereKey($content->id)->lockForUpdate()->firstOrFail();

            $this->assertLegalTransition($locked->status, $newStatus);

            $previous = $locked->status;
            $locked->forceFill(['status' => $newStatus])->save();

            $this->audit->school($school, $auditEvent, actor: $actor, subject: $locked, metadata: [
                'learningContentId' => $locked->id,
                'previousStatus' => $previous,
                'newStatus' => $locked->status,
            ]);

            return $locked->refresh();
        }));
    }

    private function assertLegalTransition(string $from, string $to): void
    {
        if (! in_array($to, self::LEGAL_TRANSITIONS[$from] ?? [], true)) {
            throw new LearningContentIllegalTransitionException($from, $to);
        }
    }
}
