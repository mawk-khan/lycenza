<?php

namespace App\Support\Retention;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * E21 legal-hold seam (docs/security/E21-RETENTION-DETERMINATION.md §2):
 * Schools listed in RETENTION_HOLD_SCHOOL_IDS are skipped by every
 * tenant-scoped retention command. Their rows are counted as held, and
 * nothing of theirs is deleted. Rows that belong to no School are held as
 * one group by RETENTION_HOLD_PLATFORM (platformHeld()).
 *
 * HRX.6 hardening: `retention_school_holds` is the DATABASE side of the same
 * School hold. The privileged retention path mirrors the configured holds
 * into it (synchronize()) before it purges, and the HRX purge functions
 * refuse a School recorded there, so the hold is enforced at the
 * destructive boundary itself, not only here. Only the retention identity
 * (the migration/owner connection) can write the table.
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

    /**
     * Makes the database hold mirror equal the configured School holds
     * (RETENTION_HOLD_SCHOOL_IDS stays the operator's source): records every
     * configured School that exists, releases every `config` row no longer
     * configured. Runs on the retention identity's connection only.
     */
    public function synchronize(): void
    {
        $admin = DB::connection(RetentionExpiry::PRIVILEGED_CONNECTION);
        $held = $admin->table('schools')->whereIn('id', array_values(array_filter($this->heldSchoolIds(), fn (string $id) => Str::isUuid($id))))->pluck('id')->all();

        $admin->transaction(function () use ($admin, $held): void {
            $admin->table('retention_school_holds')->where('source', 'config')->whereNotIn('school_id', $held)->delete();
            foreach ($held as $schoolId) {
                $admin->table('retention_school_holds')->insertOrIgnore(['school_id' => $schoolId, 'source' => 'config']);
            }
        });
    }

    /** @return list<string> */
    public function heldSchoolIds(): array
    {
        return array_values(array_map('strval', (array) config('retention.hold_school_ids', [])));
    }
}
