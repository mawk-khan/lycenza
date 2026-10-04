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
 * School hold, and the HRX purge functions refuse a School recorded there,
 * so the hold is enforced at the destructive boundary itself.
 *
 * E21-RH.2 (ADR 0066 §6.1 transition): writing the mirror is OPERATOR
 * maintenance (`platform:retention-holds-sync`, on the migration/owner
 * connection), never part of a scheduled run. The retention identity can
 * only read it: a destructive run refuses when a configured hold is not
 * recorded (RetentionExpiry::privileged()), and a recorded hold stays
 * enforced until the operator synchronizes its release.
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

    /** Operator maintenance only (ADR 0021): the connection that may write the hold mirror. */
    public const MAINTENANCE_CONNECTION = 'pgsql_admin';

    /**
     * Makes the database hold mirror equal the configured School holds
     * (RETENTION_HOLD_SCHOOL_IDS stays the operator's source): records every
     * configured School that exists, releases every `config` row no longer
     * configured. OPERATOR maintenance on the migration/owner connection
     * (`platform:retention-holds-sync`); never called by a scheduled run.
     *
     * @return list<string> configured ids that are not an existing School (not recorded; a destructive run keeps refusing until the configuration is fixed)
     */
    public function synchronize(): array
    {
        $admin = DB::connection(self::MAINTENANCE_CONNECTION);
        $held = $admin->table('schools')->whereIn('id', array_values(array_filter($this->heldSchoolIds(), fn (string $id) => Str::isUuid($id))))->pluck('id')->all();

        $admin->transaction(function () use ($admin, $held): void {
            $admin->table('retention_school_holds')->where('source', 'config')->whereNotIn('school_id', $held)->delete();
            foreach ($held as $schoolId) {
                $admin->table('retention_school_holds')->insertOrIgnore(['school_id' => $schoolId, 'source' => 'config']);
            }
        });

        return array_values(array_diff($this->heldSchoolIds(), $held));
    }

    /** @return list<string> */
    public function heldSchoolIds(): array
    {
        return array_values(array_map('strval', (array) config('retention.hold_school_ids', [])));
    }
}
