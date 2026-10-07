<?php

namespace App\Domain\TeachingAssignments\Application;

/**
 * TCH-E: one period during which an Employee teaches one elective
 * SubjectOffering (an ElectiveTeachingAssignment, as ids and dates only).
 * Offering-wide: there is no Section. `endsOn` null is open-ended; both ends
 * are inclusive.
 */
final readonly class OwnedElectivePeriod
{
    public function __construct(
        public string $assignmentId,
        public string $subjectOfferingId,
        public string $startsOn,
        public ?string $endsOn,
    ) {}

    public function covers(string $date): bool
    {
        return $this->startsOn <= $date && ($this->endsOn === null || $this->endsOn >= $date);
    }
}
