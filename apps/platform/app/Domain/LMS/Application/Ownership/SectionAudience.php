<?php

namespace App\Domain\LMS\Application\Ownership;

use InvalidArgumentException;

/**
 * TCH.5B (ADR 0063 section 34) -- the ownership half of a TEACHER-OWNED
 * LMS resource, supplied only when such a row is created: the owner
 * Employee and one or more audience Sections. Both are immutable once the
 * row exists (database-enforced).
 *
 * A value, not a decision: holding one authorizes nothing. Whoever builds
 * it (TCH.5C/TCH.5D) must first establish the owned-scope capability, the
 * ActingEmployee and TeachingAssignment coverage of every Section.
 */
final readonly class SectionAudience
{
    /** @var list<string> */
    public array $sectionIds;

    /** @param  list<string>  $sectionIds */
    public function __construct(
        public string $ownerEmployeeId,
        array $sectionIds,
    ) {
        if ($sectionIds === []) {
            throw new InvalidArgumentException('A teacher-owned LMS resource needs at least one audience Section.');
        }

        if (count(array_unique($sectionIds)) !== count($sectionIds)) {
            throw new InvalidArgumentException('Audience Sections must be distinct.');
        }

        $this->sectionIds = $sectionIds;
    }
}
