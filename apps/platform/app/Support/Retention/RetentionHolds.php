<?php

namespace App\Support\Retention;

/**
 * E21 legal-hold seam (docs/security/E21-RETENTION-DETERMINATION.md §2):
 * Schools listed in RETENTION_HOLD_SCHOOL_IDS are skipped by every
 * tenant-scoped retention command. Their rows are counted as held, and
 * nothing of theirs is deleted. Rows that belong to no School (identity-level
 * email, platform outbox rows) are not School-holdable.
 */
final class RetentionHolds
{
    public function isHeld(string $schoolId): bool
    {
        return in_array($schoolId, $this->heldSchoolIds(), true);
    }

    /** @return list<string> */
    public function heldSchoolIds(): array
    {
        return array_values(array_map('strval', (array) config('retention.hold_school_ids', [])));
    }
}
