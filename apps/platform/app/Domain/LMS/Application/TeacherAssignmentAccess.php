<?php

namespace App\Domain\LMS\Application;

use App\Domain\HR\Application\ActingEmployeeResolver;
use App\Domain\LMS\Application\Ownership\LmsResourceOwnershipReader;
use App\Domain\LMS\Infrastructure\Assignment;
use App\Domain\TeachingAssignments\Application\TeachingOwnership;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * TCH.5D (ADR 0063 sections 34, 37) -- the owned (Tier 2) Assignment entry
 * point, the same rule as Learning Content (TeacherLmsScope/TeacherLmsGuard):
 *
 *   lms.assignments.teacher
 *   AND a verified ActingEmployee (today, School-local)
 *   AND the row's owner/audience rule (TeacherAssignmentScope)
 *   AND TeachingAssignment coverage today (TeachingOwnership).
 *
 * `due_on` is never an authorization date. `scope()`/`visible()` are fresh
 * reads; every write goes through `guard()`. The role key is never read.
 * Tier 1 (`lms.assignments.view/.manage`) needs none of these facts.
 */
class TeacherAssignmentAccess
{
    use AuthorizesCapability;

    public const string CAPABILITY = 'lms.assignments.teacher';

    public function __construct(
        private readonly ActingEmployeeResolver $identities,
        private readonly TeachingOwnership $ownership,
        private readonly LmsResourceOwnershipReader $resources,
    ) {}

    public function scope(User $actor, School $school): TeacherAssignmentScope
    {
        $this->authorizeCapabilityFor($actor, self::CAPABILITY, $school);

        $acting = $this->identities->resolve($actor, $school);

        return new TeacherAssignmentScope($acting->employeeId, $acting->asOf, $this->ownership->periods($school, $acting->employeeId));
    }

    /**
     * The row, if this teacher may read it today; otherwise the same 404 as
     * an unknown id (another teacher's draft or closed row, an unpublished
     * Offering-wide row, an untaught class, another School).
     */
    public function visible(User $actor, School $school, string $assignmentId, ?TeacherAssignmentScope $scope = null): Assignment
    {
        $scope ??= $this->scope($actor, $school);
        $assignment = Assignment::query()->where('school_id', $school->id)->findOrFail($assignmentId);

        if (! $scope->canRead($this->resources->forAssignment($school, $assignment->id), $assignment->status)) {
            throw (new ModelNotFoundException)->setModel(Assignment::class, [$assignmentId]);
        }

        return $assignment;
    }

    public function guard(User $actor): TeacherAssignmentGuard
    {
        return new TeacherAssignmentGuard($actor, $this->identities, $this->ownership, $this->resources);
    }
}
