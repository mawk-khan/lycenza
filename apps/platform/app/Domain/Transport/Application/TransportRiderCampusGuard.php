<?php

namespace App\Domain\Transport\Application;

use App\Domain\Campuses\Application\ActiveCampus;
use App\Domain\Students\Application\StudentOperationalEligibility;
use App\Domain\Transport\Application\Exceptions\TransportAssignmentUnavailableException;
use App\Domain\Transport\Infrastructure\TransportRoute;
use App\Domain\Transport\Infrastructure\TransportStudentAssignment;
use App\Models\School;
use App\Support\Authorization\Campus\CampusScope;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * ORG.7D-5 (ADR 0076 §41): the Campus-scoped Transport actor's write-time
 * checks, run by TransportStudentAssignmentService inside its transaction.
 *
 * - Assign (after the Student lock and the Route share lock): the route's
 *   Campus must be set and in scope, and the Student must be a current
 *   operational Student OF THAT Campus, proven under lock
 *   (StudentOperationalEligibility::lockOperationalOn(), which also
 *   share-locks the Campus as active). No inter-Campus riding from a Campus
 *   grant: every refusal is the one generic TRANSPORT_ASSIGNMENT_UNAVAILABLE.
 * - End: the assignment's route Campus must be in scope and still active
 *   (ActiveCampus share lock), else NOT FOUND.
 *
 * Built only by TransportRiderAccess, from a resolved CampusScope.
 */
final class TransportRiderCampusGuard
{
    public function __construct(private readonly School $school, private readonly CampusScope $scope) {}

    public function beforeAssign(TransportRoute $lockedRoute, string $studentId): void
    {
        $campusId = $lockedRoute->campus_id;
        if (! is_string($campusId) || ! $this->scope->covers($campusId)
            || ! app(StudentOperationalEligibility::class)->lockOperationalOn($this->school, $studentId, $campusId)) {
            throw new TransportAssignmentUnavailableException;
        }
    }

    public function beforeEnd(TransportStudentAssignment $assignment): void
    {
        $campusId = TransportRoute::query()->where('school_id', $this->school->id)->whereKey($assignment->route_id)->value('campus_id');
        if (! is_string($campusId) || ! $this->scope->covers($campusId) || app(ActiveCampus::class)->find($this->school, $campusId) === null) {
            throw (new ModelNotFoundException)->setModel(TransportStudentAssignment::class, [$assignment->id]);
        }
    }
}
