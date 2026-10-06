<?php

namespace App\Domain\Students\Application;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Domain\Students\Infrastructure\StudentSubjectEnrollment;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * RES.1 (ADR 0068 §5, "P3"): the ONE authoritative as-of-date
 * SubjectOffering eligibility read, owned by Students/SIS. It answers "was
 * Student S eligible to be assessed in SubjectOffering O on date D, and on
 * which placement?" from existing placement and elective-enrollment
 * evidence. No table, cache or event of its own.
 *
 * - **Required Offering:** a StudentEnrollment (placement) of S whose
 *   interval contains D and whose AcademicYear, Campus and GradeLevel equal
 *   the Offering's. Never filtered by Section -- an Offering is not
 *   Section-owned; the Section is returned as evidence only.
 * - **Elective Offering:** additionally a `student_subject_enrollments` row
 *   of S for O whose interval contains D, carrying its placement anchor.
 *   The anchor must be a placement of the Offering's context, but need not
 *   itself cover D: a placement transfer never re-anchors an elective row
 *   (StudentEnrollmentService never touches it), so after a same-grade
 *   transfer the row keeps its original, now-ended anchor and the covering
 *   placement is the new one.
 * - **Temporal, not status-based,** exactly like
 *   StudentEnrollmentRosterReadService::membersAsOf(): `starts_on <= D` and
 *   `ends_on IS NULL OR ends_on >= D` (inclusive). A row's status today
 *   (completed, withdrawn, transferred, cancelled) never removes it from a
 *   date its interval contains, so a later transfer, rollover, withdrawal
 *   or year close never changes an earlier date's answer.
 * - **Independent of current state:** the Offering's and the Student's
 *   current status, and the Student's current placement, are not read.
 *   D must lie inside the Offering's AcademicYear (inclusive), as every
 *   dated academic record of a year does.
 * - **Fails closed** (SubjectOfferingEligibility reasons): another School's
 *   Student or Offering, no covering placement, no covering elective row,
 *   an unanchored (legacy) elective row, more than one covering placement
 *   or elective row, or rows that disagree with each other.
 *
 * Two variants (ADR 0038's discipline):
 * - eligibilityAsOf(): a plain point-in-time read, no locks;
 * - lockEligibilityAsOf(): for a write path, inside the caller's open
 *   transaction. It takes FOR SHARE, in this order, on the Offering row,
 *   then every placement of S covering D in the year (id order), then
 *   every elective row of S for O covering D (id order), so a concurrent
 *   Offering change, placement transfer / end, or elective switch /
 *   withdrawal of those rows waits for the caller's commit. Students
 *   writers lock Section -> placement and Offering -> elective row, so this
 *   order cannot form a cycle with them.
 *
 * Authorization-neutral, like every Students read service: callers
 * authorize first. The School is the trusted tenant context, never a
 * client-supplied id.
 */
class SubjectOfferingEligibilityReadService
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function eligibilityAsOf(School $school, string $studentId, string $subjectOfferingId, string $asOfDate): SubjectOfferingEligibility
    {
        $date = $this->date($asOfDate);

        return $this->context->withSchool($school, fn () => $this->decide($school, $studentId, $subjectOfferingId, $date, false));
    }

    /** As eligibilityAsOf(), holding FOR SHARE on every row the answer relied on until the caller's transaction ends. */
    public function lockEligibilityAsOf(School $school, string $studentId, string $subjectOfferingId, string $asOfDate): SubjectOfferingEligibility
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('lockEligibilityAsOf() must be called inside an open DB::transaction().');
        }
        $date = $this->date($asOfDate);

        return $this->context->withSchool($school, fn () => $this->decide($school, $studentId, $subjectOfferingId, $date, true));
    }

    private function decide(School $school, string $studentId, string $offeringId, string $date, bool $lock): SubjectOfferingEligibility
    {
        $offering = $this->locked(SubjectOffering::query()->where('school_id', $school->id)->whereKey($offeringId), $lock)->first();
        if ($offering === null) {
            return SubjectOfferingEligibility::notEligible(SubjectOfferingEligibility::OFFERING_NOT_FOUND);
        }
        if (! Student::query()->where('school_id', $school->id)->whereKey($studentId)->exists()) {
            return SubjectOfferingEligibility::notEligible(SubjectOfferingEligibility::STUDENT_NOT_FOUND);
        }

        $year = AcademicYear::query()->where('school_id', $school->id)->whereKey($offering->academic_year_id)->first();
        if ($year === null) {
            return SubjectOfferingEligibility::notEligible(SubjectOfferingEligibility::INCONSISTENT_RECORD);
        }
        if ($date < $year->starts_on->toDateString() || $date > $year->ends_on->toDateString()) {
            return SubjectOfferingEligibility::notEligible(SubjectOfferingEligibility::OUTSIDE_ACADEMIC_YEAR);
        }

        // Every placement of the Student covering D in the Offering's year: one human Student is in one place on one date.
        $placements = $this->locked($this->covering(StudentEnrollment::query()
            ->where('school_id', $school->id)
            ->where('student_id', $studentId)
            ->where('academic_year_id', $offering->academic_year_id), $date)->orderBy('id'), $lock)->get();
        if ($placements->count() > 1) {
            return SubjectOfferingEligibility::notEligible(SubjectOfferingEligibility::AMBIGUOUS_HISTORY);
        }
        $placement = $placements->first();
        if ($placement === null || ! $this->inOfferingContext($placement, $offering)) {
            return SubjectOfferingEligibility::notEligible(SubjectOfferingEligibility::NO_PLACEMENT);
        }
        if (! $this->sectionAgrees($school, $placement)) {
            return SubjectOfferingEligibility::notEligible(SubjectOfferingEligibility::INCONSISTENT_RECORD);
        }

        if ($offering->is_required) {
            return SubjectOfferingEligibility::required($placement->id, $placement->section_id);
        }

        $rows = $this->locked($this->covering(StudentSubjectEnrollment::query()
            ->where('school_id', $school->id)
            ->where('student_id', $studentId)
            ->where('subject_offering_id', $offering->id), $date)->orderBy('id'), $lock)->get();
        if ($rows->count() > 1) {
            return SubjectOfferingEligibility::notEligible(SubjectOfferingEligibility::AMBIGUOUS_HISTORY);
        }
        $row = $rows->first();
        if ($row === null) {
            return SubjectOfferingEligibility::notEligible(SubjectOfferingEligibility::NO_ELECTIVE_ENROLLMENT);
        }
        if ($row->academic_year_id !== $offering->academic_year_id) {
            return SubjectOfferingEligibility::notEligible(SubjectOfferingEligibility::INCONSISTENT_RECORD);
        }
        if ($row->student_enrollment_id === null) {
            return SubjectOfferingEligibility::notEligible(SubjectOfferingEligibility::ELECTIVE_UNANCHORED);
        }
        $anchor = StudentEnrollment::query()->where('school_id', $school->id)->whereKey($row->student_enrollment_id)->first();
        if ($anchor === null || $anchor->student_id !== $studentId || ! $this->inOfferingContext($anchor, $offering)) {
            return SubjectOfferingEligibility::notEligible(SubjectOfferingEligibility::INCONSISTENT_RECORD);
        }

        return SubjectOfferingEligibility::elective($placement->id, $placement->section_id, $row->id);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function covering(Builder $query, string $date): Builder
    {
        return $query->where('starts_on', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $date));
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function locked(Builder $query, bool $lock): Builder
    {
        return $lock ? $query->sharedLock() : $query;
    }

    private function inOfferingContext(StudentEnrollment $placement, SubjectOffering $offering): bool
    {
        return $placement->academic_year_id === $offering->academic_year_id
            && $placement->campus_id === $offering->campus_id
            && $placement->grade_level_id === $offering->grade_level_id;
    }

    /** The placement's Section is application-kept consistent with its context columns; a disagreement is corrupt data. */
    private function sectionAgrees(School $school, StudentEnrollment $placement): bool
    {
        return Section::query()->where('school_id', $school->id)->whereKey($placement->section_id)
            ->where('academic_year_id', $placement->academic_year_id)
            ->where('campus_id', $placement->campus_id)
            ->where('grade_level_id', $placement->grade_level_id)
            ->exists();
    }

    private function date(string $asOfDate): string
    {
        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $asOfDate);
        } catch (Throwable) {
            $parsed = null;
        }
        if (! $parsed instanceof CarbonImmutable || $parsed->format('Y-m-d') !== $asOfDate) {
            throw new InvalidArgumentException('The as-of date must be a calendar date (Y-m-d).');
        }

        return $asOfDate;
    }
}
