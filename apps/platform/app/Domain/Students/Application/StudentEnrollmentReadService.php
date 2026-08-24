<?php

namespace App\Domain\Students\Application;

use App\Domain\AcademicStructure\Application\CurrentAcademicYearResolver;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Phase 1B.4: the canonical read layer for StudentEnrollment --
 * centralizes "what does the current/historical placement for this
 * Student look like" so a future API/UI layer never independently
 * rebuilds this query logic. Deliberately internal application
 * architecture, not yet an API contract (no DTOs, no Resources) --
 * returns plain Eloquent models/collections/paginators, matching
 * `StudentController::index()`/`show()`'s existing established shape
 * (Student's own read layer).
 *
 * Tenant-safe but authorization-neutral, exactly like
 * StudentEnrollmentService: every method here relies entirely on the
 * ambient SchoolScope/RLS protection already active on `StudentEnrollment`
 * (and `Student`, for the directory's search filters) -- it never calls
 * `TenantContext::withSchool()` itself, matching `StudentController`'s
 * own read methods (`Student::query()->paginate(...)` with no explicit
 * context wrapping) rather than the write-service pattern (which
 * explicitly establishes context because it may be invoked from a
 * context-less caller, e.g. a queue job). A caller of this class is
 * always an already-tenant-resolved request/job. No capability check
 * lives here -- see docs/modules/STUDENT-ENROLLMENT.md ("Read
 * authorization contract") for exactly where a future controller must
 * call `Gate::authorize`/`authorizeCapability` with `enrollments.view`
 * before ever reaching this class.
 *
 * Never mutates state -- no "fix stale placement while reading," no
 * automatic status completion, no automatic rollover, no lazy roll-
 * number correction. Reads are reads.
 */
class StudentEnrollmentReadService
{
    public function __construct(private readonly CurrentAcademicYearResolver $academicYearResolver) {}

    /**
     * The `active` Enrollment for a Student within one AcademicYear --
     * NEVER "the latest row by starts_on/created_at" and NEVER a
     * historical/terminal row silently substituted for "current". If
     * `$academicYear` is omitted, resolves the School's currently
     * `active` AcademicYear (`CurrentAcademicYearResolver`); if the
     * School has no active AcademicYear, or the Student has no `active`
     * Enrollment in the resolved/given AcademicYear, returns `null` --
     * never guesses at an arbitrary historical row.
     */
    public function currentFor(Student $student, ?AcademicYear $academicYear = null): ?StudentEnrollment
    {
        $academicYear ??= $this->academicYearResolver->tryResolve($student->school);
        if ($academicYear === null) {
            return null;
        }

        return StudentEnrollment::query()
            ->with(['academicYear', 'campus', 'gradeLevel', 'section'])
            ->where('student_id', $student->id)
            ->where('academic_year_id', $academicYear->id)
            ->where('status', 'active')
            ->first();
    }

    /**
     * Every permitted Enrollment record for a Student, across every
     * AcademicYear and every status (active/completed/withdrawn/
     * transferred/cancelled) -- the full historical record, never
     * filtered to "current" implicitly. Ordered by `starts_on` (each
     * Enrollment's own placement start date, already inherently
     * AcademicYear-chronological -- a later AcademicYear's Enrollments
     * always start later), then `created_at` as a deterministic
     * tiebreaker, matching this checkpoint's chosen ordering
     * convention (docs/modules/STUDENT-ENROLLMENT.md "Historical
     * reads").
     *
     * @return Collection<int, StudentEnrollment>
     */
    public function historyFor(Student $student): Collection
    {
        return StudentEnrollment::query()
            ->with(['academicYear', 'campus', 'gradeLevel', 'section'])
            ->where('student_id', $student->id)
            ->orderBy('starts_on')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * A single Enrollment with every relationship needed to explain its
     * historical placement (Student, AcademicYear, Campus, GradeLevel,
     * Section) -- deliberately does NOT eager-load Guardian data;
     * Enrollment reads stay academically focused (docs/modules/STUDENT-ENROLLMENT.md
     * "Enrollment detail").
     */
    public function detail(string $enrollmentId): ?StudentEnrollment
    {
        return StudentEnrollment::query()
            ->with(['student', 'academicYear', 'campus', 'gradeLevel', 'section'])
            ->find($enrollmentId);
    }

    /**
     * Paginated administrative Enrollment listing for the current
     * School -- the future `/enrollments` directory / Student
     * placement display. Every filter is applied to already
     * tenant-scoped query state (`StudentEnrollment`'s own SchoolScope/
     * RLS, and `student` via a `whereHas` on the SAME scoped relation)
     * -- a foreign-School id passed in any filter simply matches zero
     * rows, it can never widen or redirect the query outside the
     * current School (proven in
     * StudentEnrollmentReadServiceTest::a_foreign_school_filter_value_matches_zero_rows_never_widens_the_query).
     *
     * Eager-loads only `student` (a DELIBERATELY PARTIAL column
     * selection excluding `date_of_birth` -- the same privacy boundary
     * Phase 1A's own Student list already established, never pulled
     * into a broad Enrollment index by default), `academicYear`,
     * `campus`, `gradeLevel`, `section` -- never `guardians`/`contacts`/
     * `subjects`/attendance/fees. No N+1: exactly one query per eager-
     * loaded relation regardless of page size, proven directly in
     * StudentEnrollmentReadServiceTest.
     *
     * @param  array{academic_year_id?: string, campus_id?: string, grade_level_id?: string, section_id?: string, status?: string, student_number?: string, student_name?: string, roll_number?: string}  $filters
     */
    public function directory(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $query = StudentEnrollment::query()
            ->with([
                'student:id,school_id,student_number,first_name,middle_name,last_name,status',
                'academicYear', 'campus', 'gradeLevel', 'section',
            ])
            ->orderBy('starts_on', 'desc')
            ->orderBy('created_at', 'desc');

        if (isset($filters['academic_year_id'])) {
            $query->where('academic_year_id', $filters['academic_year_id']);
        }
        if (isset($filters['campus_id'])) {
            $query->where('campus_id', $filters['campus_id']);
        }
        if (isset($filters['grade_level_id'])) {
            $query->where('grade_level_id', $filters['grade_level_id']);
        }
        if (isset($filters['section_id'])) {
            $query->where('section_id', $filters['section_id']);
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['roll_number'])) {
            $query->where('roll_number', $filters['roll_number']);
        }
        if (isset($filters['student_number'])) {
            $query->whereHas('student', fn ($q) => $q->where('student_number', $filters['student_number']));
        }
        if (isset($filters['student_name'])) {
            $term = '%'.$filters['student_name'].'%';
            $query->whereHas('student', fn ($q) => $q->where(
                fn ($q2) => $q2->where('first_name', 'ilike', $term)->orWhere('last_name', 'ilike', $term),
            ));
        }

        return $query->paginate($perPage);
    }
}
