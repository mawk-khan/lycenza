<?php

namespace App\Domain\Hostel\Application;

use App\Domain\Hostel\Application\Exceptions\HostelResidencyUnavailableException;
use App\Domain\Hostel\Infrastructure\Hostel;
use App\Domain\Hostel\Infrastructure\HostelBed;
use App\Domain\Hostel\Infrastructure\HostelRoom;
use App\Domain\Students\Application\StudentOperationalEligibility;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Support\Authorization\Campus\CampusScope;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * ORG.7D-3 (ADR 0076 §39): the Campus-scoped actor's write-time check,
 * run by HostelResidencyService::assign() inside its transaction, after it
 * has locked the Student and the Bed:
 *
 * - the Bed's Hostel Campus must be in the actor's scope (else NOT FOUND);
 * - the Student must be a current operational Student OF THAT Campus,
 *   proven under lock (StudentOperationalEligibility::lockOperationalOn(),
 *   ORG.7D-4 §40) and held until the residency commits. A Student who
 *   moved Campus, left, or lost their current placement since the picker
 *   answered, or does so concurrently, is refused with one generic message.
 *   No cross-Campus boarding exists for a Campus-scoped actor (owner
 *   decision, ORG.7D-4).
 *
 * Built only by HostelResidencyAccess, from a resolved CampusScope.
 */
final class HostelResidencyCampusGuard
{
    public function __construct(private readonly School $school, private readonly CampusScope $scope) {}

    public function beforeAssign(Student $student, HostelBed $bed): void
    {
        $hostelId = HostelRoom::query()->whereKey($bed->hostel_room_id)->value('hostel_id');
        $campusId = is_string($hostelId) ? Hostel::query()->whereKey($hostelId)->value('campus_id') : null;

        if (! is_string($campusId) || ! $this->scope->covers($campusId)) {
            throw (new ModelNotFoundException)->setModel(HostelBed::class);
        }
        if (! app(StudentOperationalEligibility::class)->lockOperationalOn($this->school, $student->id, $campusId)) {
            throw new HostelResidencyUnavailableException;
        }
    }
}
