<?php

namespace App\Support\Retention;

/**
 * E21 legal-hold seam (docs/security/E21-RETENTION-DETERMINATION.md §2):
 * Schools listed in RETENTION_HOLD_SCHOOL_IDS are skipped by every
 * tenant-scoped retention command. Their rows are counted as held, and
 * nothing of theirs is deleted. Rows that belong to no School are held as
 * one group by RETENTION_HOLD_PLATFORM (platformHeld()).
 */
final class RetentionHolds
{
    public function isHeld(string $schoolId): bool
    {
        return in_array($schoolId, $this->heldSchoolIds(), true);
    }

    /**
     * E21.2B: records that belong to no School cannot be held by School.
     * RETENTION_HOLD_PLATFORM=true holds ALL of them instead. That covers
     * the platform audit ledger, email suppressions, platform and Group
     * grants, identity-level email, School-less outbox rows and failed jobs.
     * Nothing School-less is purged while it is set.
     */
    public function platformHeld(): bool
    {
        return (bool) config('retention.hold_platform', false);
    }

    /** @return list<string> */
    public function heldSchoolIds(): array
    {
        return array_values(array_map('strval', (array) config('retention.hold_school_ids', [])));
    }
}
