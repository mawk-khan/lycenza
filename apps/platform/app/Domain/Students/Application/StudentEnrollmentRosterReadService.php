<?php

namespace App\Domain\Students\Application;

use App\Domain\Students\Application\Exceptions\AmbiguousHistoricalEnrollmentException;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
 * Campus/GradeLevel, but that consistency is application-enforced by
 * StudentEnrollmentService rather than structurally guaranteed (see
 * `create_student_enrollments_table`'s own docblock). Matching all
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
