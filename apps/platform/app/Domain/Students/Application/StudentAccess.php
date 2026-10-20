<?php

namespace App\Domain\Students\Application;

use App\Domain\AcademicStructure\Application\CurrentAcademicYearResolver;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\Campus\CampusScope;
use App\Support\Authorization\Campus\CampusScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * ORG.7F-1 (ADR 0076 §45): the ONE place SIS reads Students and Enrollments
 * under the actor's CampusScope for the adopted read capabilities
 * (`students.view`, `enrollments.view`). READ side only: no write
 * authority exists here (Student and Enrollment writes are not
 * Campus-adopted).
 *
 * Built on the canonical Student Campus foundation (ADR 0076 §38); it never
 * judges currency itself:
 *
 * - Whole School: every existing behaviour, unchanged (every Student,
 *   every Enrollment, history included).
 * - Campus set: a Student is reachable only while their CURRENT operational
 *   Campus (StudentOperationalCampusResolver: active Student, active
 *   Enrollment in the School's active year, active Campus) is in scope --
 *   an SQL EXISTS before any row is returned. Inactive, unplaced, former-
 *   and future-Campus Students are whole-School only. Several Campus grants
 *   are a union. An Enrollment is reachable only when it IS that current
 *   placement: never history, a draft/future year, or another Campus.
 * - None: nothing.
 * - Out of scope is NOT FOUND (404), exactly like an unknown id or another
 *   School's record; a malformed id is NOT FOUND before any query.
 *
 * Fresh on every call (no cache): a transfer or an academic-year switch
 * changes what the next request sees.
 */
final class StudentAccess
{
    public function __construct(
        private readonly StudentOperationalCampusResolver $operational,
        private readonly StudentEnrollmentReadService $enrollments,
        private readonly CurrentAcademicYearResolver $years,
        private readonly CampusScopeResolver $scopes,
    ) {}

    /**
     * Restricts a Student query to the scope, in SQL.
     *
     * @return Builder<Student>
     */
    public function students(School $school, CampusScope $scope): Builder
    {
        /** @var Builder<Student> $query */
        $query = Student::query()->where('students.school_id', $school->id);
        if ($scope->isWholeSchool()) {
            return $query;
        }

        // Campus set, or none (which matches nothing).
        return $this->operational->restrictToOperationalCampus($query, $school, $scope);
    }

    /** The Student when the scope reaches it, else NOT FOUND (unknown, malformed, other School and out of scope alike). */
    public function student(School $school, CampusScope $scope, string $id): Student
    {
        $found = Str::isUuid($id) ? $this->students($school, $scope)->find($id) : null;

        return $found ?? throw (new ModelNotFoundException)->setModel(Student::class, [$id]);
    }

    /**
     * The Enrollment directory restricted to the scope, in SQL: every row
     * for the whole School; for a Campus set, only CURRENT placements on
     * in-scope Campuses of operational Students (filters narrow further).
     *
     * @param  array{academic_year_id?: string, campus_id?: string, grade_level_id?: string, section_id?: string, status?: string, student_number?: string, student_name?: string, roll_number?: string}  $filters
     * @return Builder<StudentEnrollment>
     */
    public function enrollmentDirectory(School $school, CampusScope $scope, array $filters): Builder
    {
        return $this->restrictEnrollments($this->enrollments->directoryQuery($filters), $school, $scope);
    }

    /** One Enrollment when the scope reaches it (for a Campus set: only the current in-scope placement), else NOT FOUND. */
    public function enrollment(School $school, CampusScope $scope, string $id): StudentEnrollment
    {
        $found = Str::isUuid($id)
            ? $this->restrictEnrollments(StudentEnrollment::query()->with(['student', 'academicYear', 'campus', 'gradeLevel', 'section']), $school, $scope)->find($id)
            : null;

        return $found ?? throw (new ModelNotFoundException)->setModel(StudentEnrollment::class, [$id]);
    }

    /**
     * The current placements of these Students that the scope may read,
     * keyed by Student id, in ONE SQL query: for the whole School every
     * current placement; for a Campus set only a current placement on an
     * in-scope Campus of a Student who is operational in scope (the same
     * rule as the Enrollment directory); none for none.
     *
     * @param  list<string>  $studentIds
     * @return Collection<string, StudentEnrollment>
     */
    public function currentPlacements(School $school, CampusScope $scope, array $studentIds): Collection
    {
        if ($scope->isNone() || $studentIds === []) {
            return new Collection;
        }

        return $this->restrictEnrollments(StudentEnrollment::query()->with(['academicYear', 'campus', 'gradeLevel', 'section']), $school, $scope, currentOnly: true)
            ->whereIn('student_enrollments.student_id', $studentIds)
            ->get()
            ->keyBy('student_id');
    }

    /**
     * Whole-School reads only: the active placement in a NAMED academic year
     * (an unknown or another School's year is NOT FOUND, never a fallback to
     * the School's active year). Any other scope gets nothing: another year
     * is history.
     */
    public function placementInYear(School $school, CampusScope $scope, Student $student, string $academicYearId): ?StudentEnrollment
    {
        if (! $scope->isWholeSchool()) {
            return null;
        }
        $year = AcademicYear::query()->where('school_id', $school->id)->findOrFail($academicYearId);

        return $this->enrollments->constrainToCurrentPlacement(StudentEnrollment::query()->with(['academicYear', 'campus', 'gradeLevel', 'section']), $year->id)
            ->where('student_enrollments.school_id', $school->id)
            ->where('student_enrollments.student_id', $student->id)
            ->first();
    }

    /**
     * @param  Builder<StudentEnrollment>  $enrollments
     * @param  bool  $currentOnly  restrict a whole-School read to current placements too
     * @return Builder<StudentEnrollment>
     */
    private function restrictEnrollments(Builder $enrollments, School $school, CampusScope $scope, bool $currentOnly = false): Builder
    {
        $enrollments->where('student_enrollments.school_id', $school->id);
        if ($scope->isWholeSchool() && ! $currentOnly) {
            return $enrollments;
        }
        $yearId = $scope->isNone() ? null : $this->years->tryResolve($school)?->id;
        if ($yearId === null) {
            return $enrollments->whereRaw('1 = 0');
        }

        // THE current placement (one per Student and year) -- the canonical
        // rule. For a Campus set also: on an in-scope Campus, of a Student
        // whose current operational Campus is in scope (active Student,
        // active Campus) -- the canonical rules, composed.
        $this->enrollments->constrainToCurrentPlacement($enrollments, $yearId);
        if ($scope->isWholeSchool()) {
            return $enrollments;
        }
        $scope->restrict($enrollments, 'student_enrollments.campus_id');

        return $enrollments->whereIn('student_enrollments.student_id', $this->students($school, $scope)->select('students.id'));
    }

    /** The actor's scope for a capability (page affordances; every read re-applies it). */
    public function scopeOf(User $actor, string $capability, School $school): CampusScope
    {
        return $this->scopes->campusScope($actor, $capability, $school);
    }

    public function holdsWholeSchool(User $actor, string $capability, School $school): bool
    {
        return $this->scopeOf($actor, $capability, $school)->isWholeSchool();
    }
}
