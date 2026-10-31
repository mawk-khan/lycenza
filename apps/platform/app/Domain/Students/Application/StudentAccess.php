<?php

namespace App\Domain\Students\Application;

use App\Domain\AcademicStructure\Application\CurrentAcademicYearResolver;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Campuses\Application\ActiveCampus;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\Campus\CampusScope;
use App\Support\Authorization\Campus\CampusScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

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
        private readonly StudentOperationalEligibility $eligibility,
        private readonly ActiveCampus $campuses,
        private readonly SectionOccupancyReader $occupancy,
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
     * SIS.EXP-1: narrows a Student directory query to Students whose CURRENT
     * placement (the active year's active Enrollment) is on the given Campus,
     * Grade and/or Section -- in SQL, through the same current-placement rule
     * as currentPlacements(), under the viewer's `enrollments.view` scope.
     *
     * - Filters only narrow: an id outside the scope, of another School, or
     *   a Section that is not in the chosen Campus/Grade simply matches no
     *   placement (no error that would confirm it exists).
     * - A viewer without placement read (scope none) cannot filter by
     *   placement at all: the query then matches nothing.
     *
     * @param  Builder<Student>  $students
     * @param  array<string, mixed>  $filters  only campus_id, grade_level_id and section_id are read (request input: may be null or malformed)
     * @return Builder<Student>
     */
    public function narrowToCurrentPlacement(Builder $students, School $school, CampusScope $placementScope, array $filters): Builder
    {
        $filters = array_filter(array_intersect_key($filters, array_flip(['campus_id', 'grade_level_id', 'section_id'])), fn ($v) => is_string($v) && $v !== '');
        if ($filters === []) {
            return $students;
        }
        if ($placementScope->isNone() || array_filter($filters, fn (string $v) => ! Str::isUuid($v)) !== []) {
            return $students->whereRaw('1 = 0');
        }

        $placements = $this->restrictEnrollments(StudentEnrollment::query(), $school, $placementScope, currentOnly: true);
        foreach ($filters as $column => $value) {
            $placements->where("student_enrollments.{$column}", $value);
        }

        return $students->whereIn('students.id', $placements->select('student_enrollments.student_id'));
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

    /**
     * ORG.7F-2 (ADR 0076 §46): the locked WRITE target for a Student identity
     * edit -- inside the caller's write transaction, held until it commits.
     *
     * - Whole School: the Student (any status), locked FOR NO KEY UPDATE.
     * - Campus set: in this order, each lock kept until commit --
     *   1. the School's active year, FOR SHARE (none: refused);
     *   2. the Student, FOR NO KEY UPDATE (never FOR UPDATE, §40.5), which
     *      must be active;
     *   3. its current Campus, read under that lock, must be in scope;
     *   4. StudentOperationalEligibility::lockOperationalOn() then
     *      share-locks the current Enrollment and the Campus and proves
     *      that placement is still current, on that Campus, and active.
     *   A transfer, inactivation, year switch or Campus deactivation that
     *   committed first is seen and refused; one that comes later waits.
     * - Everything refused (unknown, malformed, other School, out of scope,
     *   inactive, unplaced, moved) is the same NOT FOUND.
     *
     * Returns the locked Student only: never a general mutable finder.
     */
    public function lockedWriteTarget(School $school, CampusScope $scope, string $studentId): Student
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('StudentAccess::lockedWriteTarget() must run inside the write transaction.');
        }
        $refuse = fn () => throw (new ModelNotFoundException)->setModel(Student::class, [$studentId]);
        if ($scope->isNone() || ! Str::isUuid($studentId)) {
            $refuse();
        }
        $student = fn (): ?Student => Student::query()->where('school_id', $school->id)->whereKey($studentId)->lock('for no key update')->first();

        if ($scope->isWholeSchool()) {
            return $student() ?? $refuse();
        }

        $yearId = $this->years->shareLockActiveId($school) ?? $refuse();
        $locked = $student();
        if ($locked === null || $locked->status !== 'active') {
            $refuse();
        }
        $campusId = $this->enrollments->constrainToCurrentPlacement(StudentEnrollment::query(), $yearId)
            ->where('student_enrollments.school_id', $school->id)
            ->where('student_enrollments.student_id', $studentId)
            ->value('campus_id');
        if (! is_string($campusId) || ! $scope->covers($campusId) || ! $this->eligibility->lockOperationalOn($school, $studentId, $campusId)) {
            $refuse();
        }

        return $locked;
    }

    /**
     * ORG.7F-3 (ADR 0076 §47): the locked TRANSFER target of a Campus-scoped
     * transfer -- inside the transfer transaction, held until it commits:
     *
     *   1. the School's active year, FOR SHARE (none: NOT FOUND);
     *   2. the Student, FOR SHARE (never FOR UPDATE, §40.5) -- the same
     *      placement lock every enrollment-creating path takes first -- which
     *      must be active;
     *   3. the CURRENT placement (constrainToCurrentPlacement), whose Campus
     *      must be in scope and active (share-locked) -- the web actor never
     *      names an Enrollment row; an API caller's named row must be it;
     *
     * Out of scope, unknown, inactive or unplaced: NOT FOUND, like an unknown
     * Student. The destination Section is only resolved here (this School);
     * StudentTransferCampusGuard and the F-2 rule decide it under
     * transferPlacement()'s row locks.
     *
     * @return array{source: StudentEnrollment, target: Section|null, activeYearId: string}
     */
    public function lockedTransferTarget(School $school, CampusScope $scope, string $studentId, string $targetSectionId, ?string $expectedSourceId = null): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('StudentAccess::lockedTransferTarget() must run inside the transfer transaction.');
        }
        $refuse = fn () => throw (new ModelNotFoundException)->setModel(Student::class, [$studentId]);
        if ($scope->isNone() || ! Str::isUuid($studentId)) {
            $refuse();
        }

        $yearId = $this->years->shareLockActiveId($school) ?? $refuse();
        $status = Student::query()->where('school_id', $school->id)->whereKey($studentId)->sharedLock()->value('status');
        if ($status !== 'active') {
            $refuse();
        }
        $source = $this->enrollments->constrainToCurrentPlacement(StudentEnrollment::query(), $yearId)
            ->where('student_enrollments.school_id', $school->id)
            ->where('student_enrollments.student_id', $studentId)
            ->first();
        // The source Campus must still be active (share-locked until commit, so
        // a deactivation waits or is seen); an API caller that named a
        // placement must still be naming THIS current one.
        if ($source === null || ! $scope->covers($source->campus_id)
            || ($expectedSourceId !== null && $source->id !== $expectedSourceId)
            || $this->campuses->find($school, $source->campus_id) === null) {
            $refuse();
        }

        $target = Str::isUuid($targetSectionId)
            ? Section::query()->where('sections.school_id', $school->id)->whereKey($targetSectionId)->first()
            : null;

        return ['source' => $source, 'target' => $target, 'activeYearId' => $yearId];
    }

    /**
     * ORG.7F-3: the purpose-limited destination lookup of a Campus transfer
     * (never Academic Structure administration): active Sections of the
     * source's year and Grade on ANOTHER active Campus in scope, in SQL.
     * Advisory labels only -- the transfer re-decides everything under lock.
     *
     * @return list<array{id: string, label: string}>
     */
    public function transferDestinations(School $school, CampusScope $scope, StudentEnrollment $source): array
    {
        if ($scope->isNone()) {
            return [];
        }
        $query = Section::query()->where('sections.school_id', $school->id)
            ->where('sections.academic_year_id', $source->academic_year_id)
            ->where('sections.grade_level_id', $source->grade_level_id)
            ->where('sections.status', 'active')
            ->where('sections.campus_id', '!=', $source->campus_id)
            ->whereHas('campus', fn ($q) => $q->where('status', 'active'))
            ->with(['campus', 'gradeLevel']);
        $scope->restrict($query, 'sections.campus_id');

        $sections = $query->get();
        // SIS 11.3 (OD-SIS-6): advisory occupancy of each destination (information only).
        $occupancy = $this->occupancy->forSections($school, $sections);

        return $sections
            ->sortBy(fn (Section $s) => [$s->campus->name, $s->name])
            ->map(fn (Section $s) => ['id' => (string) $s->id, 'label' => "{$s->gradeLevel->name} · Section {$s->name} · {$s->campus->name}", 'occupancy' => $occupancy[(string) $s->id]->label()])
            ->values()->all();
    }

    /**
     * SIS 11.3 (OD-SIS-6): the Sections whose occupancy the `enrollments.view`
     * scope may see, in SQL. Whole School: every Section of every year (a
     * closed year's shows capacity only). A Campus set: only the School's
     * ACTIVE year's Sections on in-scope Campuses -- a Campus reader sees
     * current placements, never a draft/future or closed year (rule 101).
     * None: nothing.
     *
     * @return Builder<Section>
     */
    public function capacitySections(School $school, CampusScope $scope): Builder
    {
        /** @var Builder<Section> $query */
        $query = Section::query()->where('sections.school_id', $school->id);
        if ($scope->isWholeSchool()) {
            return $query;
        }
        $year = $scope->isNone() ? null : $this->years->tryResolve($school);
        if ($year === null) {
            return $query->whereRaw('1 = 0');
        }

        return $scope->restrict($query->where('sections.academic_year_id', $year->id), 'sections.campus_id');
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
