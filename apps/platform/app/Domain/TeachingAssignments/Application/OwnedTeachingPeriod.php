<?php

namespace App\Domain\TeachingAssignments\Application;

/**
 * TCH.3: one period during which an Employee owns one Section + required
 * SubjectOffering teaching context (a TeachingAssignment, as ids and
 * dates only). `endsOn` null is open-ended; both ends are inclusive.
 */
final readonly class OwnedTeachingPeriod
{
    public function __construct(
        public string $assignmentId,
        public string $sectionId,
        public string $subjectOfferingId,
        public string $startsOn,
        public ?string $endsOn,
    ) {}

    public function covers(string $date): bool
    {
        return $this->startsOn <= $date && ($this->endsOn === null || $this->endsOn >= $date);
    }

    /** Overlap with an inclusive [from, to] interval; $to null is open-ended. */
    public function overlaps(string $from, ?string $to): bool
    {
        return ($to === null || $this->startsOn <= $to) && ($this->endsOn === null || $this->endsOn >= $from);
    }
}
