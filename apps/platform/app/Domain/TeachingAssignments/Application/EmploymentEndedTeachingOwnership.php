<?php

namespace App\Domain\TeachingAssignments\Application;

use App\Domain\HR\Application\EmploymentEndParticipant;
use App\Models\School;
use App\Models\User;

/**
 * S7 (ADR 0063 §47): Teaching Assignments' part of an employment end -- the
 * required (Section x Offering) and the elective (Offering-wide) ownership the
 * employment granted end with it, in EmploymentService::end()'s transaction,
 * through each table's one writer. Registered under
 * EmploymentEndParticipant::TAG in AppServiceProvider, so HR never references
 * this module (the dependency stays Teaching Assignments -> HR).
 *
 * Lock order inside the end: EmploymentRecord FOR UPDATE (HR) -> required rows
 * -> elective rows, each FOR UPDATE in id order -- the identity-first order a
 * teacher's use already takes (EmploymentRecord FOR SHARE, then the
 * assignment FOR SHARE).
 */
final readonly class EmploymentEndedTeachingOwnership implements EmploymentEndParticipant
{
    public function __construct(
        private TeachingAssignmentService $required,
        private ElectiveTeachingAssignmentService $elective,
    ) {}

    public function employmentEnded(School $school, string $employeeId, string $endsOn, User $actor): void
    {
        $this->required->endForEmployment($school, $employeeId, $endsOn, $actor);
        $this->elective->endForEmployment($school, $employeeId, $endsOn, $actor);
    }
}
