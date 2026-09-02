<?php

namespace App\Domain\Examinations\Application;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Examinations\Application\Exceptions\DuplicateExaminationPaperException;
use App\Domain\Examinations\Application\Exceptions\ExaminationNotActiveException;
use App\Domain\Examinations\Application\Exceptions\ExaminationPaperAcademicYearMismatchException;
use App\Domain\Examinations\Application\Exceptions\ExaminationPaperDateOutsideWindowException;
use App\Domain\Examinations\Application\Exceptions\ExaminationPaperInvalidMaxMarksException;
use App\Domain\Examinations\Application\Exceptions\ExaminationPaperTimeOrderException;
use App\Domain\Examinations\Application\Exceptions\SubjectOfferingNotAvailableException;
use App\Domain\Examinations\Infrastructure\Examination;
use App\Domain\Examinations\Infrastructure\ExaminationPaper;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0H.4B -- the ONE sanctioned write path for `examination_papers`.
 * Neither the API controller nor the Inertia controller writes the model
 * directly (proven by
 * Tests\Feature\Examinations\ExaminationPaperArchitectureGuardTest).
 *
 * SERVER-DERIVED CONTEXT. `school_id`, `academic_year_id`, `campus_id`
 * and `grade_level_id` are never accepted from request data -- all four
 * are derived here from the trusted, tenant-resolved Examination and
 * SubjectOffering (CLAUDE.md rules 19/68). `examination_id` and
 * `subject_offering_id` are fixed at creation and can never be
 * reassigned by `update()`.
 *
 * ACTIVE-PARENT RULES. `create()` requires both the Examination and the
 * SubjectOffering to be active -- applies identically to required AND
 * elective Offerings, `is_required` is never inspected. `update()` does
 * NOT ordinarily require either parent active (an existing Paper remains
 * correctable as history even after a parent is later deactivated) --
 * EXCEPT when the update itself transitions `status` from `inactive` to
 * `active` ("reactivation"), which re-runs the same active-parent checks
 * `create()` performs. This is the one deliberate asymmetry in this
 * service.
 *
 * NO OVERLAP CHECK, NO LOCK. Overlapping Paper sittings across DIFFERENT
 * SubjectOfferings are permitted -- the only invariant is the aggregate
 * unique constraint (one Paper per Examination x SubjectOffering), which
 * a single PostgreSQL unique index settles without any concurrency
 * machinery.
 */
class ExaminationPaperService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array{subject_offering_id: string, scheduled_on: string, starts_at: string, ends_at: string, max_marks: string, status?: string}  $attributes
     */
    public function create(School $school, Examination $examination, array $attributes, User $actor): ExaminationPaper
    {
        return $this->context->withSchool($school, function () use ($school, $examination, $attributes, $actor) {
            if (! $examination->isActive()) {
                throw new ExaminationNotActiveException($examination->id);
            }

            // Tenant-scoped lookup (SchoolScope + RLS): another School's
            // SubjectOffering id is a clean 404 here.
            $subjectOffering = SubjectOffering::query()->findOrFail($attributes['subject_offering_id']);

            if (! $subjectOffering->isActive()) {
                throw new SubjectOfferingNotAvailableException($subjectOffering->id);
            }

            $this->assertSameAcademicYear($examination, $subjectOffering);

            $scheduledOn = $attributes['scheduled_on'];
            $startsAt = $attributes['starts_at'];
            $endsAt = $attributes['ends_at'];
            $maxMarks = $attributes['max_marks'];

            $this->assertWithinExaminationWindow($examination, $scheduledOn);
            $this->assertTimeOrder($startsAt, $endsAt);
            $this->assertPositiveMaxMarks($maxMarks);

            return DB::transaction(function () use ($school, $examination, $subjectOffering, $attributes, $scheduledOn, $startsAt, $endsAt, $maxMarks, $actor) {
                $paper = new ExaminationPaper;
                // Every parent/pin is force-filled from trusted,
                // server-resolved models -- never mass-assigned from
                // request input.
                $paper->forceFill([
                    'school_id' => $school->id,
                    'examination_id' => $examination->id,
                    'subject_offering_id' => $subjectOffering->id,
                    'academic_year_id' => $examination->academic_year_id,
                    'campus_id' => $subjectOffering->campus_id,
                    'grade_level_id' => $subjectOffering->grade_level_id,
                    'scheduled_on' => $scheduledOn,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'max_marks' => $maxMarks,
                    'status' => $attributes['status'] ?? ExaminationPaper::STATUS_ACTIVE,
                ]);

                $this->save($paper, $examination->id, $subjectOffering->id);

                $this->audit->school($school, 'examinations.paper.created', actor: $actor, subject: $paper, metadata: [
                    'paperId' => $paper->id,
                    'examinationId' => $paper->examination_id,
                    'subjectOfferingId' => $paper->subject_offering_id,
                    'scheduledOn' => $paper->scheduled_on->toDateString(),
                    'startsAt' => $paper->starts_at,
                    'endsAt' => $paper->ends_at,
                    'maxMarks' => (string) $paper->max_marks,
                    'status' => $paper->status,
                ]);

                return $paper;
            });
        });
    }

    /**
     * May change only scheduled_on/starts_at/ends_at/max_marks/status.
     * `examination_id`, `subject_offering_id` and every integrity pin are
     * immutable and never accepted here.
     *
     * @param  array<string, string>  $attributes
     */
    public function update(School $school, ExaminationPaper $paper, array $attributes, User $actor): ExaminationPaper
    {
        return $this->context->withSchool($school, function () use ($school, $paper, $attributes, $actor) {
            $scheduledOn = $attributes['scheduled_on'] ?? $paper->scheduled_on->toDateString();
            $startsAt = $attributes['starts_at'] ?? $paper->starts_at;
            $endsAt = $attributes['ends_at'] ?? $paper->ends_at;
            $maxMarks = $attributes['max_marks'] ?? (string) $paper->max_marks;
            $newStatus = $attributes['status'] ?? $paper->status;

            // Tenant-scoped: the Paper itself was already resolved
            // tenant-scoped by the caller, so its own parent is trusted.
            $examination = Examination::query()->findOrFail($paper->examination_id);

            // Re-run every applicable invariant: an existing row is not
            // a back door around them.
            $this->assertWithinExaminationWindow($examination, $scheduledOn);
            $this->assertTimeOrder($startsAt, $endsAt);
            $this->assertPositiveMaxMarks($maxMarks);

            $isReactivation = $paper->status === ExaminationPaper::STATUS_INACTIVE
                && $newStatus === ExaminationPaper::STATUS_ACTIVE;

            // Reactivation guard (the one deliberate asymmetry): an
            // ordinary correction never re-checks parent activity, but
            // moving inactive -> active must not reintroduce a Paper
            // beneath a withdrawn parent.
            if ($isReactivation) {
                if (! $examination->isActive()) {
                    throw new ExaminationNotActiveException($examination->id);
                }

                $subjectOffering = SubjectOffering::query()->findOrFail($paper->subject_offering_id);
                if (! $subjectOffering->isActive()) {
                    throw new SubjectOfferingNotAvailableException($subjectOffering->id);
                }
            }

            $before = [
                'scheduled_on' => $paper->scheduled_on->toDateString(),
                'starts_at' => $paper->starts_at,
                'ends_at' => $paper->ends_at,
                'max_marks' => (string) $paper->max_marks,
                'status' => $paper->status,
            ];

            return DB::transaction(function () use ($school, $paper, $scheduledOn, $startsAt, $endsAt, $maxMarks, $newStatus, $before, $attributes, $actor) {
                $paper->forceFill([
                    'scheduled_on' => $scheduledOn,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'max_marks' => $maxMarks,
                    'status' => $newStatus,
                ]);

                $this->save($paper, $paper->examination_id, $paper->subject_offering_id);

                $after = [
                    'scheduled_on' => $paper->scheduled_on->toDateString(),
                    'starts_at' => $paper->starts_at,
                    'ends_at' => $paper->ends_at,
                    'max_marks' => (string) $paper->max_marks,
                    'status' => $paper->status,
                ];

                $this->audit->school($school, 'examinations.paper.updated', actor: $actor, subject: $paper, metadata: [
                    'paperId' => $paper->id,
                    'changedFields' => array_keys($attributes),
                    'before' => $before,
                    'after' => $after,
                ]);

                return $paper->refresh();
            });
        });
    }

    /**
     * Persists, translating ONLY the specific named aggregate unique
     * violation into a duplicate-paper domain error. Any other unique
     * violation is genuinely unexpected and must stay an unhandled
     * failure rather than being mislabelled.
     */
    private function save(ExaminationPaper $paper, string $examinationId, string $subjectOfferingId): void
    {
        try {
            $paper->save();
        } catch (UniqueConstraintViolationException $e) {
            if (! str_contains($e->getMessage(), 'examination_papers_examination_offering_unique')) {
                throw $e;
            }

            throw new DuplicateExaminationPaperException($examinationId, $subjectOfferingId);
        }
    }

    private function assertSameAcademicYear(Examination $examination, SubjectOffering $subjectOffering): void
    {
        if ($examination->academic_year_id !== $subjectOffering->academic_year_id) {
            throw new ExaminationPaperAcademicYearMismatchException($examination->id, $subjectOffering->id);
        }
    }

    /**
     * Both bounds inclusive. Future Paper dates are permitted -- only the
     * Examination's own window bound is enforced.
     */
    private function assertWithinExaminationWindow(Examination $examination, string $scheduledOn): void
    {
        if ($scheduledOn < $examination->starts_on->toDateString() || $scheduledOn > $examination->ends_on->toDateString()) {
            throw new ExaminationPaperDateOutsideWindowException($scheduledOn);
        }
    }

    private function assertTimeOrder(string $startsAt, string $endsAt): void
    {
        if ($endsAt <= $startsAt) {
            throw new ExaminationPaperTimeOrderException($startsAt, $endsAt);
        }
    }

    private function assertPositiveMaxMarks(string $maxMarks): void
    {
        if ((float) $maxMarks <= 0) {
            throw new ExaminationPaperInvalidMaxMarksException($maxMarks);
        }
    }
}
