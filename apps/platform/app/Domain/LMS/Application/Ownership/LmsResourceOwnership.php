<?php

namespace App\Domain\LMS\Application\Ownership;

/**
 * TCH.5B (ADR 0063 section 34) -- what a Learning Content or Assignment
 * row IS in ownership terms, for the authorization TCH.5C/TCH.5D will
 * build:
 *
 * - Offering-wide (legacy/administrative): no owner, no audience;
 * - teacher-owned: an owner Employee and >= 1 audience Section.
 *
 * Persistence facts only -- never an authorization decision. An owner is
 * not the acting User, not an ActingEmployee and not a TeachingAssignment.
 */
final readonly class LmsResourceOwnership
{
    /** @param  list<string>  $audienceSectionIds */
    public function __construct(
        public string $resourceId,
        public string $subjectOfferingId,
        public ?string $ownerEmployeeId,
        public array $audienceSectionIds,
    ) {}

    public function isOfferingWide(): bool
    {
        return $this->ownerEmployeeId === null;
    }

    public function isEmployeeOwned(): bool
    {
        return $this->ownerEmployeeId !== null;
    }
}
