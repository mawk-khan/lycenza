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
 * specific, as-of-date, roster and fee-target readers are deliberately
 * separate and never substituted for "current". A Charge's Campus is never
 * derived here: it is stored on the Charge when it is assessed (ORG.7G-1A,
 * ADR 0076 §49).
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
     * SIS.HARDEN-1 (SIS.EXP-R1 finding R-5): keeps the Sections (of
     * `$academicYearId`) a current-placement filter can match -- active ones,
     * plus an inactive one still holding a current placement (deactivating a
     * Section never ends its placements). For filter OPTIONS only.
     *
     * @template TQuery of Builder|QueryBuilder
     *
     * @param  TQuery  $sections  a `sections` query
     * @return TQuery
     */
    public function matchableSections(Builder|QueryBuilder $sections, string $academicYearId): Builder|QueryBuilder
    {
        return $sections->where(fn ($q) => $q->where('sections.status', 'active')->orWhereExists(fn (QueryBuilder $placed) => $this->constrainToCurrentPlacement(
            $placed->selectRaw('1')->from('student_enrollments as se')->whereColumn('se.section_id', 'sections.id')->whereColumn('se.school_id', 'sections.school_id'),
            $academicYearId,
            'se',
        )));
    }

    /**
     * SIS 8.1 (SB-02): keeps the rows of `$query` whose date (`$dateColumn`)
     * lies outside the dates of the placement named by `$enrollmentColumn`
     * -- before it started, or after it ended. An empty-interval
     * cancellation (ADR 0078) covers no day, so every date is outside it.
     * Students' SQL, so a consumer (Attendance) never reads placements
     * itself (CLAUDE.md rule 4).
     *
     * @template TQuery of Builder|QueryBuilder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function constrainToDateOutsidePlacement(Builder|QueryBuilder $query, School $school, string $enrollmentColumn, string $dateColumn): Builder|QueryBuilder
    {
        return $query->whereExists(fn ($e) => $e->selectRaw('1')->from('student_enrollments as outside_placement')
            ->where('outside_placement.school_id', $school->id)
            ->whereColumn('outside_placement.id', $enrollmentColumn)
            ->where(fn ($w) => $w->whereColumn($dateColumn, '<', 'outside_placement.starts_on')
                ->orWhere(fn ($x) => $x->whereNotNull('outside_placement.ends_on')->whereColumn($dateColumn, '>', 'outside_placement.ends_on'))));
    }

    /**
     * SIS 8.1: placements by id, with their Student (display columns only),
     * year, Grade and Section -- for a consumer's Data Health finding rows.
     * Authorization-neutral; keyed by id.
     *
     * @param  list<string>  $ids
     * @return Collection<string, StudentEnrollment>
     */
    public function placementsForFindings(School $school, array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        return StudentEnrollment::query()->where('school_id', $school->id)->whereIn('id', $ids)
            ->with(['student:id,school_id,student_number,first_name,middle_name,last_name', 'academicYear', 'gradeLevel', 'section'])
            ->get()->keyBy('id');
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
        return $this->directoryQuery($filters)->paginate($perPage);
    }

    /**
     * ORG.7F-1 (ADR 0076 §45): the directory's filtered query, unpaginated,
     * so StudentAccess can apply a Campus actor's scope in SQL before the
     * page is read.
     *
     * @param  array{academic_year_id?: string, campus_id?: string, grade_level_id?: string, section_id?: string, status?: string, student_number?: string, student_name?: string, roll_number?: string}  $filters
     * @return Builder<StudentEnrollment>
     */
    public function directoryQuery(array $filters = []): Builder
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

        return $query;
    }
}
