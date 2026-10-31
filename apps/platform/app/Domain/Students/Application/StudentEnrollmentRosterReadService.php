<?php

namespace App\Domain\Students\Application;

use App\Domain\Students\Application\Exceptions\AmbiguousHistoricalEnrollmentException;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0H.2: the ONE authoritative "who was placed in this Section on
 * this date" read, owned by Students/SIS.
 *
 * Attendance (both its non-authoritative roster PREVIEW and its
 * authoritative SUBMISSION) calls this service; it must never
 * reproduce the predicate itself. StudentEnrollment lifecycle and
 * placement-interval semantics belong to this module, and duplicating
 * them inside Attendance would let the two drift -- exactly the
 * failure `docs/modules/STUDENT-ENROLLMENT.md` exists to prevent.
 *
 * THE PREDICATE IS TEMPORAL, NOT STATUS-BASED. It is deliberately NOT
 * `status = 'active'`:
 *
 *   - `starts_on <= $asOfDate`
 *   - `ends_on IS NULL OR ends_on >= $asOfDate`   (ends_on is INCLUSIVE)
 *
 * plus exact equality on School + AcademicYear + Campus + GradeLevel +
 * Section. A row whose status is today `completed`/`withdrawn`/
 * `transferred`/`cancelled` STILL belongs on a historical register for
 * a date its interval contains -- a Student who transferred out in
 * October was genuinely present in September's Section, and a register
 * for 15 September must say so. Filtering on current status would make
 * every historical roster silently change meaning as the year
 * progresses.
 *
 * `ends_on` inclusivity matches `StudentEnrollmentService::transferPlacement()`
 * exactly: it sets the source row's `ends_on` to
 * `effectiveDate - 1 day` and the target row's `starts_on` to
 * `effectiveDate`. So for a transfer effective 20 September, 19
 * September resolves to the SOURCE Section and 20 September to the
 * TARGET Section, with no gap and no overlap.
 *
 * The four context predicates are intentional defense-in-depth. A
 * StudentEnrollment's `section_id` already implies its AcademicYear/
 * Campus/GradeLevel. That consistency was application-enforced by
 * StudentEnrollmentService until ORG.7D-0 (ADR 0076 §36), which made it
 * structural (`student_enrollments_section_context_fk`). Matching all
 * five columns means an internally inconsistent row is simply excluded
 * rather than silently admitted into permanent Attendance history --
 * and it makes this query's result set exactly what Attendance's
 * `attendance_records_enrollment_context_fk` will accept.
 *
 * Deliberately authorization-neutral, like every other Application
 * service in this codebase -- callers authorize before reaching here.
 * Must be called from inside an established TenantContext (the query
 * hits RLS-protected `student_enrollments`/`students`).
 */
class StudentEnrollmentRosterReadService
{
    /**
     * @return Collection<int, SectionRosterMember> ordered by roll number
     *                                              then Enrollment id --
     *                                              a stable, deterministic
     *                                              presentation order.
     *
     * @throws AmbiguousHistoricalEnrollmentException when one Student has
     *                                                more than one qualifying
     *                                                placement on $asOfDate
     */
    public function membersAsOf(
        string $schoolId,
        string $academicYearId,
        string $campusId,
        string $gradeLevelId,
        string $sectionId,
        string $asOfDate,
    ): Collection {
        $rows = $this->qualifyingQuery($schoolId, $academicYearId, $campusId, $gradeLevelId, $sectionId, $asOfDate)
            ->with('student:id,school_id,first_name,middle_name,last_name')
            ->orderBy('roll_number')
            ->orderBy('id')
            ->get();

        $this->assertUnambiguous($rows, $sectionId, $asOfDate);

        return $rows->map(fn (StudentEnrollment $e) => new SectionRosterMember(
            studentEnrollmentId: $e->id,
            studentId: $e->student_id,
            rollNumber: $e->roll_number,
            fullName: $this->composeFullName($e),
        ))->values();
    }

    /**
     * The qualifying Enrollment ids ONLY, sorted ascending by id -- the
     * deterministic order in which
     * App\Domain\Attendance\Application\AttendanceSubmissionService
     * takes its row locks. Deliberately id-ordered (never roll-number
     * or request order): a stable global lock order is what prevents
     * two concurrent submissions from deadlocking against each other.
     *
     * @return list<string>
     */
    public function qualifyingEnrollmentIdsAsOf(
        string $schoolId,
        string $academicYearId,
        string $campusId,
        string $gradeLevelId,
        string $sectionId,
        string $asOfDate,
    ): array {
        return $this->qualifyingQuery($schoolId, $academicYearId, $campusId, $gradeLevelId, $sectionId, $asOfDate)
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    /**
     * SIS 11.3 (OD-SIS-6): each Section's operational STRENGTH -- the number
     * of distinct Students whose placement qualifies on the Section's as-of
     * date, by THIS class's temporal predicate (never a status filter, never
     * a second copy of it). A sub-query with one row per Section of a draft or
     * active year of the School (`section_id`, `as_of`, `strength`), for a
     * caller to join to its own, already-authorized Section rows.
     *
     * - As-of date: the School-local `$today`, clamped into the Section's
     *   academic year (an active year counts today; a draft year that has not
     *   started counts its first day, so already-created future placements
     *   show as planned strength; a year past its end counts its last day).
     * - A closed year has NO row: its Sections show capacity only, never a
     *   current vacancy (history stays on the placement reports).
     * - A transfer ends the source the day before the destination starts, so
     *   one Student is never counted in two Sections on one date; a placement
     *   cancelled before it started has an empty interval and never counts;
     *   an ended placement stops counting after its end date.
     *
     * One grouped scan of the School's placements, whatever the number of
     * Sections. Must run inside the School's TenantContext.
     */
    public function sectionStrengthQuery(string $schoolId, string $today): QueryBuilder
    {
        $asOf = 'greatest(ay.starts_on, least(ay.ends_on, ?::date))';

        return DB::table('sections as s')
            ->join('academic_years as ay', fn ($j) => $j->on('ay.id', '=', 's.academic_year_id')->on('ay.school_id', '=', 's.school_id'))
            ->leftJoin('student_enrollments as se', fn ($j) => $j->on('se.section_id', '=', 's.id')
                ->on('se.school_id', '=', 's.school_id')
                ->on('se.academic_year_id', '=', 's.academic_year_id')
                ->on('se.campus_id', '=', 's.campus_id')
                ->on('se.grade_level_id', '=', 's.grade_level_id')
                ->whereRaw("se.starts_on <= {$asOf}", [$today])
                ->whereRaw("(se.ends_on IS NULL OR se.ends_on >= {$asOf})", [$today]))
            ->where('s.school_id', $schoolId)
            ->where('ay.status', '!=', 'closed')
            ->groupBy('s.id', 'ay.starts_on', 'ay.ends_on')
            ->select('s.id as section_id')
            ->selectRaw("({$asOf}) AS as_of", [$today])
            ->selectRaw('count(DISTINCT se.student_id) AS strength');
    }

    /**
     * @return Builder<StudentEnrollment>
     */
    private function qualifyingQuery(
        string $schoolId,
        string $academicYearId,
        string $campusId,
        string $gradeLevelId,
        string $sectionId,
        string $asOfDate,
    ) {
        return StudentEnrollment::query()
            ->where('school_id', $schoolId)
            ->where('academic_year_id', $academicYearId)
            ->where('campus_id', $campusId)
            ->where('grade_level_id', $gradeLevelId)
            ->where('section_id', $sectionId)
            ->whereDate('starts_on', '<=', $asOfDate)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $asOfDate));
    }

    /**
     * @param  Collection<int, StudentEnrollment>  $rows
     */
    private function assertUnambiguous(Collection $rows, string $sectionId, string $asOfDate): void
    {
        foreach ($rows->groupBy('student_id') as $studentId => $forStudent) {
            if ($forStudent->count() > 1) {
                throw new AmbiguousHistoricalEnrollmentException(
                    (string) $studentId, $sectionId, $asOfDate, $forStudent->count(),
                );
            }
        }
    }

    private function composeFullName(StudentEnrollment $enrollment): string
    {
        $student = $enrollment->student;

        if ($student === null) {
            return '';
        }

        return trim(implode(' ', array_filter([
            $student->first_name, $student->middle_name, $student->last_name,
        ], fn (?string $part) => $part !== null && $part !== '')));
    }
}
