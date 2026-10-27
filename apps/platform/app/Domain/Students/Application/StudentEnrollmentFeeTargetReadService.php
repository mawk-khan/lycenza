<?php

namespace App\Domain\Students\Application;

use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * FEE.2 (ADR 0062 §10.1, §12): the Students-owned read boundary Fees uses to
 * find who a fee assessment run may bill, and to re-check one enrollment
 * under a lock at execution time. Fees never queries `student_enrollments`
 * or `students` itself (CLAUDE.md rule 4).
 *
 * The candidate predicate is the enrollment interval, not current status
 * (the roster read's precedent): every enrollment of the AcademicYear x
 * GradeLevel -- optionally one Campus, or every Campus except the listed
 * ones -- that has not ENDED before the billing period starts. Enrollments
 * that start after the period ends are included deliberately so Fees can
 * report them as `period_before_enrollment` (owner decision D1) instead of
 * silently dropping them. Cancelled enrollments are included so Fees can
 * report `enrollment_cancelled`. Eligibility itself is Fees' decision.
 *
 * Authorization-neutral like its siblings; callers authorize first and call
 * inside the School's TenantContext.
 */
class StudentEnrollmentFeeTargetReadService
{
    /**
     * @param  list<string>  $excludedCampusIds  campuses served by their own campus-override structure
     * @return Collection<int, FeeTargetEnrollment>
     */
    public function candidatesForGrade(
        School $school,
        string $academicYearId,
        string $gradeLevelId,
        ?string $campusId,
        array $excludedCampusIds,
        CarbonImmutable $periodStartsOn,
    ): Collection {
        $rows = StudentEnrollment::query()
            ->where('school_id', $school->id)
            ->where('academic_year_id', $academicYearId)
            ->where('grade_level_id', $gradeLevelId)
            ->when($campusId !== null, fn ($q) => $q->where('campus_id', $campusId))
            ->when($excludedCampusIds !== [], fn ($q) => $q->whereNotIn('campus_id', $excludedCampusIds))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $periodStartsOn->toDateString()))
            ->orderBy('student_id')
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get();

        $activeStudents = Student::query()
            ->whereIn('id', $rows->pluck('student_id')->unique()->all())
            ->where('status', 'active')
            ->pluck('id')
            ->flip();

        return $rows->map(fn (StudentEnrollment $e) => $this->toTarget($e, $activeStudents->has($e->student_id)))->values();
    }

    /**
     * SIS 8.1 (SB-03a): keeps the rows of `$query` (a fee assessment query)
     * whose billing period starts after the billed placement
     * (`$enrollmentColumn`) ended, AND which no other placement of the same
     * Student in the same year (cancelled ones never count) covers on that
     * start date. A transfer therefore never matches: the destination
     * placement covers the period. The interval rule, never Student status
     * (a Student's status is not the leaver test here). Students' SQL, so
     * Fees never reads placements itself (CLAUDE.md rule 4).
     *
     * @template TQuery of EloquentBuilder|QueryBuilder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function constrainToPeriodAfterPlacementEnded(EloquentBuilder|QueryBuilder $query, School $school, string $enrollmentColumn, string $studentColumn, string $yearColumn, string $periodStartColumn): EloquentBuilder|QueryBuilder
    {
        return $query
            ->whereExists(fn ($e) => $e->selectRaw('1')->from('student_enrollments as billed_placement')
                ->where('billed_placement.school_id', $school->id)
                ->whereColumn('billed_placement.id', $enrollmentColumn)
                ->whereNotNull('billed_placement.ends_on')
                ->whereColumn('billed_placement.ends_on', '<', $periodStartColumn))
            ->whereNotExists(fn ($e) => $e->selectRaw('1')->from('student_enrollments as covering_placement')
                ->where('covering_placement.school_id', $school->id)
                ->whereColumn('covering_placement.student_id', $studentColumn)
                ->whereColumn('covering_placement.academic_year_id', $yearColumn)
                ->where('covering_placement.status', '<>', 'cancelled')
                ->whereColumn('covering_placement.starts_on', '<=', $periodStartColumn)
                ->where(fn ($w) => $w->whereNull('covering_placement.ends_on')->orWhereColumn('covering_placement.ends_on', '>=', $periodStartColumn)));
    }

    /**
     * Re-reads one enrollment and its Student under FOR SHARE locks, so a
     * concurrent withdraw/transfer/cancel/status change either commits
     * first (and is seen here) or waits for the caller's transaction.
     * Must run inside a database transaction.
     */
    public function lockForFeeAssessment(School $school, string $enrollmentId): ?FeeTargetEnrollment
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('lockForFeeAssessment() must run inside a database transaction.');
        }

        $enrollment = StudentEnrollment::query()->where('school_id', $school->id)->sharedLock()->find($enrollmentId);

        if ($enrollment === null) {
            return null;
        }

        $studentActive = Student::query()->where('school_id', $school->id)->sharedLock()
            ->whereKey($enrollment->student_id)->value('status') === 'active';

        return $this->toTarget($enrollment, $studentActive);
    }

    /**
     * OPF (ADR 0067 §8): the Student's current placement in one
     * AcademicYear -- its latest open (not ended, not cancelled) enrollment
     * there -- so Fees can resolve which structure (and optional line) a
     * source module's selection belongs to. Null when the Student has none.
     * Read under FOR SHARE, like lockForFeeAssessment(); inside a
     * transaction.
     */
    public function currentForYear(School $school, string $studentId, string $academicYearId): ?FeeTargetEnrollment
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('currentForYear() must run inside a database transaction.');
        }

        $enrollment = StudentEnrollment::query()
            ->where('school_id', $school->id)
            ->where('student_id', $studentId)
            ->where('academic_year_id', $academicYearId)
            ->whereNull('ends_on')
            ->where('status', '<>', 'cancelled')
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->sharedLock()
            ->first();

        if ($enrollment === null) {
            return null;
        }

        $studentActive = Student::query()->where('school_id', $school->id)->sharedLock()
            ->whereKey($enrollment->student_id)->value('status') === 'active';

        return $this->toTarget($enrollment, $studentActive);
    }

    /**
     * ORG.7G-1A (ADR 0076 §49): the Campus a manual Charge is attributed to
     * -- the Student's placement(s) in that AcademicYear in effect on `$on`
     * (`starts_on <= on <= ends_on`, open-ended when not ended; cancelled
     * placements never count). Exactly one Campus among them, or null
     * (none, or several): unavailable, never guessed, never the current
     * Campus. Every placement of the Student in that year is read FOR SHARE
     * (id order), so a concurrent cancel or transfer commits first and is
     * seen, or waits for the caller's transaction. Data, never authority.
     */
    public function placementCampusOn(School $school, string $studentId, string $academicYearId, CarbonImmutable $on): ?string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('placementCampusOn() must run inside a database transaction.');
        }

        $day = $on->toDateString();
        $campuses = StudentEnrollment::query()
            ->where('school_id', $school->id)
            ->where('student_id', $studentId)
            ->where('academic_year_id', $academicYearId)
            ->orderBy('id')
            ->sharedLock()
            ->get(['id', 'campus_id', 'status', 'starts_on', 'ends_on'])
            ->filter(fn (StudentEnrollment $e) => $e->status !== 'cancelled'
                && $e->starts_on->toDateString() <= $day
                && ($e->ends_on === null || $e->ends_on->toDateString() >= $day))
            ->pluck('campus_id')->unique()->values();

        return $campuses->count() === 1 ? (string) $campuses->first() : null;
    }

    private function toTarget(StudentEnrollment $e, bool $studentIsActive): FeeTargetEnrollment
    {
        return new FeeTargetEnrollment(
            enrollmentId: $e->id,
            studentId: $e->student_id,
            academicYearId: $e->academic_year_id,
            gradeLevelId: $e->grade_level_id,
            campusId: $e->campus_id,
            sectionId: $e->section_id,
            startsOn: CarbonImmutable::parse($e->starts_on),
            endsOn: $e->ends_on === null ? null : CarbonImmutable::parse($e->ends_on),
            status: $e->status,
            studentIsActive: $studentIsActive,
        );
    }
}
