<?php

namespace App\Domain\Analytics\Application\Group;

/**
 * One School's approved contribution to a Group report (ADR 0048 section
 * 7): a fixed, minimal DTO -- never source rows, ids or School-defined
 * dimension names beyond what the report's declaration approves.
 */
interface GroupSafeSchoolSummary
{
    /** False when the School has nothing to contribute (e.g. no active academic year). */
    public function contributes(): bool;

    /**
     * @return array<string, bool|int|string|null>
     */
    public function toArray(): array;
}
