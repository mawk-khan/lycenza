<?php

namespace App\Domain\Students\Application;

use App\Domain\AcademicStructure\Application\CurrentAcademicYearResolver;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Support\Authorization\Campus\CampusScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;

/**
 * ORG.7D-2 (ADR 0076 §38, D2-1 to D2-5): THE answer to "what is this
 * Student's CURRENT OPERATIONAL Campus?" -- one canonical rule, every
 * component required:
 *
 *   the School's `active` AcademicYear (exactly one, database-enforced)
 *   + an `active` Student
 *   + the Student's `active` Enrollment in that year (at most one,
 *     database-enforced; StudentEnrollmentReadService::constrainToCurrentPlacement())
 *   + that Enrollment's Campus (FK-bound to its Section, ORG.7D-0 R2)
 *   + the Campus is `active`.
 *
 * Missing any component means NO operational Campus -- never the newest
 * year, the latest row, a draft/closed year's placement, or a request or
 * selected Campus. Student identity stays School-wide: the Campus is read
 * from the Enrollment, never stored on `students`.
 *
 * - Data/context only. It grants nothing: the caller authorizes its own
 *   capability first and passes an already-resolved CampusScope where it
 *   filters (restrictToOperationalCampus()).
 * - Current, not historical: records already written keep the Campus
 *   stored on them; Finance's charge-year attribution
 *   (StudentEnrollmentReadService::campusAttribution()) stays separate.
 * - Advisory, never a write-time proof: it reads without locks. A write that
 *   creates NEW operational work for a Student on a Campus proves it with
 *   StudentOperationalEligibility::lockOperationalOn() inside its own
 *   transaction (ORG.7D-4, ADR 0076 §40).
 * - Fresh on every call (no cache), so an academic-year switch or a Campus
 *   (in)activation takes effect at once; a queued job resolves at execution
 *   time and never carries a resolved Campus as authority.
 * - Tenant-safe: every query runs inside TenantContext::withSchool() for the
 *   target School (SchoolScope + RLS) with explicit `school_id` predicates.
 */
final class StudentOperationalCampusResolver
{
    public function __construct(
        private readonly CurrentAcademicYearResolver $years,
        private readonly StudentEnrollmentReadService $enrollments,
        private readonly TenantContext $context,
    ) {}

    public function for(School $school, string $studentId): StudentOperationalCampus
    {
        return $this->forMany($school, [$studentId])[$studentId] ?? StudentOperationalCampus::none($studentId);
    }

    /**
     * One query for every Student given, whatever their enrollment history.
     * A Student of another School (or an unknown id) is absent.
     *
     * @param  list<string>  $studentIds
     * @return array<string, StudentOperationalCampus>
     */
    public function forMany(School $school, array $studentIds): array
    {
        if ($studentIds === []) {
            return [];
        }
        $yearId = $this->years->tryResolve($school)?->id;

        return $this->context->withSchool($school, function () use ($school, $studentIds, $yearId): array {
            $rows = Student::query()
                ->where('students.school_id', $school->id)
                ->whereIn('students.id', array_values(array_unique($studentIds)))
                ->when($yearId !== null, fn (Builder $q) => $this->joinCurrentPlacement($q, $school, $yearId, 'leftJoin'))
                ->get($yearId === null
                    ? ['students.id', 'students.status']
                    : ['students.id', 'students.status', 'se.id as current_enrollment_id', 'se.campus_id as placement_campus_id', 'c.status as campus_status']);

            $result = [];
            foreach ($rows as $row) {
                $result[(string) $row->id] = new StudentOperationalCampus(
                    (string) $row->id,
                    $row->status === 'active',
                    $row->current_enrollment_id ?? null,
                    $row->placement_campus_id ?? null,
                    ($row->campus_status ?? null) === 'active',
                );
            }

            return $result;
        });
    }

    /**
     * Restricts a `students` query, in SQL, to the current operational
     * candidates whose operational Campus is within $scope: active Student,
     * current placement in the active year, active Campus, and (for a
     * Campus-limited scope) that Campus in the scope. A whole-School scope
     * still requires an operational Campus (any); an empty scope -- or no
     * active AcademicYear -- matches nothing. Each Student appears at most
     * once (an EXISTS predicate on their one current placement). The Campus
     * predicate is on the Enrollment, never on `students`.
     *
     * MUST be called inside the target School's TenantContext.
     *
     * @param  Builder<Student>  $students
     * @return Builder<Student>
     */
    public function restrictToOperationalCampus(Builder $students, School $school, CampusScope $scope): Builder
    {
        $yearId = $scope->isNone() ? null : $this->years->tryResolve($school)?->id;
        if ($yearId === null) {
            return $students->whereRaw('1 = 0');
        }

        // An EXISTS predicate, never a join: the caller's select stays
        // `students.*` only, so no Enrollment or Campus column can ever be
        // hydrated onto a Student (security review, ADR 0076 §38.8).
        return $students
            ->where('students.status', 'active')
            ->whereExists(function (QueryBuilder $q) use ($school, $yearId, $scope): void {
                $q->selectRaw('1')
                    ->from('student_enrollments as se')
                    ->join('campuses as c', fn (JoinClause $j) => $j->on('c.id', '=', 'se.campus_id')->where('c.school_id', '=', $school->id))
                    ->whereColumn('se.student_id', 'students.id')
                    ->where('se.school_id', $school->id)
                    ->where('c.status', 'active');
                $this->enrollments->constrainToCurrentPlacement($q, $yearId, 'se');
                $scope->restrict($q, 'se.campus_id');
            });
    }

    /**
     * @param  Builder<Student>  $students
     * @return Builder<Student>
     */
    private function joinCurrentPlacement(Builder $students, School $school, string $yearId, string $join): Builder
    {
        return $students
            ->{$join}('student_enrollments as se', function (JoinClause $j) use ($school, $yearId): void {
                $j->on('se.student_id', '=', 'students.id')->where('se.school_id', '=', $school->id);
                $this->enrollments->constrainToCurrentPlacement($j, $yearId, 'se');
            })
            ->{$join}('campuses as c', function (JoinClause $j) use ($school): void {
                $j->on('c.id', '=', 'se.campus_id')->where('c.school_id', '=', $school->id);
            });
    }
}
