<?php

namespace App\Domain\Transport\Application;

use App\Domain\Students\Application\StudentLookupCandidate;
use App\Domain\Students\Application\StudentPurposeLookup;
use App\Domain\Students\Application\StudentSearch;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Transport\Application\Exceptions\TransportAssignmentUnavailableException;
use App\Domain\Transport\Infrastructure\TransportRoute;
use App\Domain\Transport\Infrastructure\TransportStop;
use App\Domain\Transport\Infrastructure\TransportStudentAssignment;
use App\Models\School;
use App\Support\Authorization\Campus\CampusScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * ORG.7D-5 (ADR 0076 §41): the ONE place Transport reads or targets routes,
 * their stops, rider assignments and rider candidates under the actor's
 * CampusScope (`transport.routes.view`, `transport.assignments.view`/`.manage`
 * are Campus-adopted; route and stop management `transport.routes.manage`
 * and the fleet `transport.vehicles.*` stay whole-School).
 *
 * - Authority source: the route's Campus (`transport_routes.campus_id`),
 *   fixed once the route is used (TransportRouteService); stops and riders
 *   reach it through their route, so history follows the route.
 * - A School-shared (NULL-Campus) route is whole-School only: never listed,
 *   opened, or assigned riders by a Campus-scoped actor.
 * - Candidates: for a Campus-scoped actor, StudentPurposeLookup with the
 *   actor's scope; a whole-School actor keeps the existing picker.
 * - Out of scope is NOT FOUND (404), like another School's record.
 */
final class TransportRiderAccess
{
    public function __construct(private readonly StudentPurposeLookup $students) {}

    /** @return Builder<TransportRoute> */
    public function routes(CampusScope $scope): Builder
    {
        /** @var Builder<TransportRoute> */
        return $scope->restrict(TransportRoute::query(), 'transport_routes.campus_id');
    }

    public function scopedRoute(CampusScope $scope, string $id): TransportRoute
    {
        $found = Str::isUuid($id) ? $this->routes($scope)->find($id) : null;

        return $found ?? throw (new ModelNotFoundException)->setModel(TransportRoute::class, [$id]);
    }

    /** A stop of an in-scope route (the stop follows its route). */
    public function stop(CampusScope $scope, string $id): TransportStop
    {
        $found = Str::isUuid($id) ? TransportStop::query()->whereIn('route_id', $this->routes($scope)->select('transport_routes.id'))->find($id) : null;

        return $found ?? throw (new ModelNotFoundException)->setModel(TransportStop::class, [$id]);
    }

    /** @return Builder<TransportStudentAssignment> */
    public function assignments(CampusScope $scope): Builder
    {
        return $scope->isWholeSchool()
            ? TransportStudentAssignment::query()
            : TransportStudentAssignment::query()->whereIn('route_id', $this->routes($scope)->select('transport_routes.id'));
    }

    public function assignment(CampusScope $scope, string $id): TransportStudentAssignment
    {
        $found = Str::isUuid($id) ? $this->assignments($scope)->find($id) : null;

        return $found ?? throw (new ModelNotFoundException)->setModel(TransportStudentAssignment::class, [$id]);
    }

    /**
     * The rider an assignment names. A whole-School actor gets the existing
     * answer (unknown = NOT FOUND); a Campus-scoped actor gets the same
     * generic refusal for an unknown Student as for any ineligible one.
     */
    public function rider(CampusScope $scope, string $id): Student
    {
        $student = Str::isUuid($id) ? Student::query()->find($id) : null;
        if ($student !== null) {
            return $student;
        }

        throw $scope->isWholeSchool()
            ? (new ModelNotFoundException)->setModel(Student::class, [$id])
            : new TransportAssignmentUnavailableException;
    }

    /**
     * The rider picker: the existing School-wide active-Student search for a
     * whole-School actor; the Students-owned purpose lookup (current
     * operational Students of their Campuses) for a Campus-scoped actor.
     *
     * @return list<StudentLookupCandidate>
     */
    public function riderCandidates(School $school, CampusScope $scope, ?string $term): array
    {
        if (! $scope->isWholeSchool()) {
            return $this->students->search($school, $scope, $term, 10);
        }

        return Student::query()
            ->where('status', 'active')
            ->when(trim((string) $term) !== '', fn ($q) => StudentSearch::anyField($q, (string) $term))
            ->limit(10)
            ->get()
            ->map(fn (Student $s) => new StudentLookupCandidate((string) $s->id, trim($s->first_name.' '.$s->last_name), $s->student_number))
            ->values()->all();
    }

    /** The write-time Campus check; none for a whole-School actor (unchanged path). */
    public function writeGuard(School $school, CampusScope $scope): ?TransportRiderCampusGuard
    {
        return $scope->isWholeSchool() ? null : new TransportRiderCampusGuard($school, $scope);
    }
}
