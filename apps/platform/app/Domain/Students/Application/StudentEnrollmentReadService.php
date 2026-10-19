<?php

namespace App\Domain\Students\Application;

use App\Domain\AcademicStructure\Application\CurrentAcademicYearResolver;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
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
 *
 * ORG.7D-2 (ADR 0076 §38): THE definition of a CURRENT placement is
 * constrainToCurrentPlacement() -- the `active` Enrollment in the School's
 * `active` AcademicYear (at most one per Student: the
 * `student_enrollments_one_active_per_student_year` partial unique index;
 * at most one active year per School: `academic_years_one_active_per_school`).
 * Never the newest row, the latest year or a request Campus. The operational
 * Campus built on it lives in StudentOperationalCampusResolver. Year-
 * specific, as-of-date, roster and finance-attribution readers are
 * deliberately separate (campusAttribution(), the roster and fee-target
 * readers) and never substituted for "current".
 */
class StudentEnrollmentReadService
{
    public function __construct(private readonly CurrentAcademicYearResolver $academicYearResolver) {}

    /**
     * ORG.7D-2: the single SQL definition of a current placement for the
     * given (the School's active) AcademicYear. Every "current enrollment"
     * read -- here, in StudentOperationalCampusResolver and in the Data Health
     * placement check -- goes through it.
     *
     * @template TQuery of Builder|QueryBuilder
     *
     * @param  TQuery  $enrollments  a student_enrollments query
     * @return TQuery
     */
    public function constrainToCurrentPlacement(Builder|QueryBuilder $enrollments, string $academicYearId, string $table = 'student_enrollments'): Builder|QueryBuilder
    {
        return $enrollments->where("{$table}.academic_year_id", $academicYearId)->where("{$table}.status", 'active');
    }

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

        return $this->constrainToCurrentPlacement(StudentEnrollment::query(), $academicYear->id)
            ->with(['academicYear', 'campus', 'gradeLevel', 'section'])
            ->where('student_id', $student->id)
            ->first();
    }

    /**
     * V11.2 (ADR 0075): currentFor() for a page of Students at once --
     * the same rule (the `active` Enrollment in the given, else the
     * School's currently active, AcademicYear; never a historical row
     * substituted), in ONE query for the whole page instead of one per
     * row. Keyed by Student id; a Student with no current placement is
     * simply absent. An empty map when the School has no active
     * AcademicYear. Authorization-neutral like every method here: the
     * caller checks `enrollments.view` first.
     *
     * @param  list<string>  $studentIds
     * @return Collection<string, StudentEnrollment>
     */
    public function currentForMany(School $school, array $studentIds, ?AcademicYear $academicYear = null): Collection
    {
        $academicYear ??= $this->academicYearResolver->tryResolve($school);
        if ($academicYear === null || $studentIds === []) {
            return new Collection;
        }

        return $this->constrainToCurrentPlacement(StudentEnrollment::query(), $academicYear->id)
            ->with(['gradeLevel', 'section', 'campus'])
            ->whereIn('student_id', $studentIds)
            ->get()
            ->keyBy('student_id');
    }

    /**
     * ORG.7D-2: the Students currently placed in a Grade Level and/or
     * Section -- current placement only (constrainToCurrentPlacement()), for
     * a consumer's "in this class now" filter (e.g. Outstanding fees), so it
     * never reads Enrollments itself. Null when the School has no active
     * AcademicYear (no current placement exists). Authorization-neutral.
     *
     * @return list<string>|null
     */
    public function currentStudentIdsIn(School $school, ?string $gradeLevelId, ?string $sectionId): ?array
    {
        $academicYear = $this->academicYearResolver->tryResolve($school);
        if ($academicYear === null) {
            return null;
        }

        return $this->constrainToCurrentPlacement(StudentEnrollment::query(), $academicYear->id)
            ->when($gradeLevelId !== null, fn ($q) => $q->where('grade_level_id', $gradeLevelId))
            ->when($sectionId !== null, fn ($q) => $q->where('section_id', $sectionId))
            ->pluck('student_id')->map(fn ($id) => (string) $id)->values()->all();
    }

    /**
     * LC-4 (SB-03b): the Campus each obligation belongs to, for Finance's
     * Campus filter -- from the Student's placements in the obligation's
     * academic year, never their current placement (a leaver has none, and
     * a moved Student's earlier debt stays with the earlier Campus).
     *
     * For each item [studentId, academicYearId, onDate], ignoring
     * `cancelled` placements (recorded in error):
     * 1. one Campus across that year's placements -> that Campus;
     * 2. several -> the Campus of the one placement in effect on `onDate`
     *    (`starts_on <= onDate <= ends_on`, open-ended when `ends_on` is
     *    null);
     * 3. otherwise (no placement, or no single placement on that date) ->
     *    null: unavailable, never guessed.
     *
     * One query for all items; tenant-safe through the ambient scope like
     * every method here; authorization-neutral (the caller authorizes).
     *
     * @param  array<string, array{0: string, 1: string, 2: ?string}>  $items  key => [studentId, academicYearId, onDate]
     * @return array<string, ?string> key => campus id, or null when unavailable
     */
    public function campusAttribution(array $items): array
    {
        if ($items === []) {
            return [];
        }

        $placements = StudentEnrollment::query()
            ->whereIn('student_id', array_values(array_unique(array_column($items, 0))))
            ->whereIn('academic_year_id', array_values(array_unique(array_column($items, 1))))
            ->where('status', '!=', 'cancelled')
            ->get(['student_id', 'academic_year_id', 'campus_id', 'starts_on', 'ends_on'])
            ->groupBy(fn (StudentEnrollment $e) => $e->student_id.'|'.$e->academic_year_id);

        $result = [];
        foreach ($items as $key => [$studentId, $yearId, $onDate]) {
            $inYear = $placements->get($studentId.'|'.$yearId, new Collection);
            $campuses = $inYear->pluck('campus_id')->unique()->values();
            if ($campuses->count() === 1) {
                $result[$key] = (string) $campuses->first();

                continue;
            }
            $onThatDay = $onDate === null ? new Collection : $inYear->filter(fn (StudentEnrollment $e) => $e->starts_on->toDateString() <= $onDate
                && ($e->ends_on === null || $e->ends_on->toDateString() >= $onDate));
            $result[$key] = $onThatDay->pluck('campus_id')->unique()->count() === 1 ? (string) $onThatDay->first()?->campus_id : null;
        }

        return $result;
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
        // UAT-C4 (UAT-SRCH-01): the same matching as the Students directory.
        if (isset($filters['student_number'])) {
            $query->whereHas('student', fn ($q) => StudentSearch::number($q, $filters['student_number']));
        }
        if (isset($filters['student_name'])) {
            $query->whereHas('student', fn ($q) => StudentSearch::name($q, $filters['student_name']));
        }

        return $query->paginate($perPage);
    }
}
