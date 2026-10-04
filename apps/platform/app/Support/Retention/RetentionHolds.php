<?php

namespace App\Support\Retention;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * E21 legal hold (docs/security/E21-RETENTION-DETERMINATION.md §2; ADR 0066
 * §6, E21-RH.3): PostgreSQL hold state (`retention_holds`) is AUTHORITATIVE.
 *
 * - A School hold holds that School; a PLATFORM hold holds everything --
 *   every School and every School-less record. The destructive functions
 *   migrated to the retention identity (HRX today) refuse in the database
 *   itself (`retention_assert_not_held()`); the remaining legacy retention
 *   commands consult this class.
 * - Holds are placed and released only through the audited operator
 *   commands, on the migration/owner connection (MAINTENANCE_CONNECTION),
 *   through the database functions `retention_hold_place()` /
 *   `retention_hold_release()`. Release is always explicit.
 * - RETENTION_HOLD_SCHOOL_IDS / RETENTION_HOLD_PLATFORM are a transitional,
 *   ADD-ONLY input: reconcileConfiguration() places what they name and never
 *   releases anything; removing a value from configuration releases nothing.
 *   Until reconciled, a configured value still holds here (legacy reading:
 *   the platform flag covers School-less records only), and a destructive
 *   run of a migrated function refuses (RetentionExpiry::privileged()).
 * - The database state is read as the retention identity through the
 *   read-only definer `retention_hold_active_scopes()` (active scopes only,
 *   no history). If it cannot be read, everything counts as held (fail
 *   closed).
 */
final class RetentionHolds
{
    /** Operator maintenance only (ADR 0021): the connection that places and releases holds. */
    public const MAINTENANCE_CONNECTION = 'pgsql_admin';

    /** Closed placement reasons (database-checked). */
    public const PLACE_REASONS = ['litigation', 'regulatory_inquiry', 'audit', 'investigation', 'configuration_transition', 'other'];

    /** Closed release reasons (database-checked). */
    public const RELEASE_REASONS = ['matter_concluded', 'inquiry_closed', 'audit_closed', 'placed_in_error', 'other'];

    /** The operator's change/ticket reference: a constrained token, never free text (database-checked). */
    public const REFERENCE_PATTERN = '/^[A-Za-z0-9._:\/-]{1,64}$/';

    public function isHeld(string $schoolId): bool
    {
        if (in_array($schoolId, $this->heldSchoolIds(), true)) {
            return true;
        }
        $database = $this->databaseHolds();

        return $database === null || $database['platform'] || in_array($schoolId, $database['schools'], true);
    }

    /**
     * Records that belong to no School are held as one group: by an active
     * database platform hold, or (transition) RETENTION_HOLD_PLATFORM=true.
     * That covers the platform audit ledger, email suppressions, platform and
     * Group grants, identity-level email, School-less outbox rows and failed
     * jobs.
     */
    public function platformHeld(): bool
    {
        if ((bool) config('retention.hold_platform', false)) {
            return true;
        }
        $database = $this->databaseHolds();

        return $database === null || $database['platform'];
    }

    /**
     * The active database holds, read as the retention identity; null when
     * they cannot be established (callers treat that as held).
     *
     * @return array{platform: bool, schools: list<string>}|null
     */
    public function databaseHolds(): ?array
    {
        try {
            $rows = DB::connection(RetentionExpiry::PRIVILEGED_CONNECTION)->select('SELECT scope, school_id FROM retention_hold_active_scopes()');
        } catch (Throwable) {
            DB::purge(RetentionExpiry::PRIVILEGED_CONNECTION);
            Log::warning('retention.hold_state_unavailable');

            return null;
        }

        return [
            'platform' => collect($rows)->contains(fn (object $r) => $r->scope === 'platform'),
            'schools' => collect($rows)->where('scope', 'school')->pluck('school_id')->map(fn ($id) => (string) $id)->values()->all(),
        ];
    }

    /**
     * Places a hold (operator maintenance). Idempotent: an already active
     * hold of that scope is returned, never duplicated.
     *
     * @return array{id: string, created: bool}
     */
    public function place(?string $schoolId, string $reasonCode, ?string $reference, string $via = 'operator_command'): array
    {
        $this->validate($schoolId, $reasonCode, self::PLACE_REASONS, $reference);
        $row = DB::connection(self::MAINTENANCE_CONNECTION)->selectOne(
            'SELECT hold_id, created FROM retention_hold_place(?, ?, ?, ?, ?)',
            [$schoolId === null ? 'platform' : 'school', $schoolId, $reasonCode, $reference, $via],
        );

        return ['id' => (string) $row->hold_id, 'created' => (bool) $row->created];
    }

    /** Releases the active hold of that scope (operator maintenance, explicit only); returns its id. */
    public function release(?string $schoolId, string $reasonCode, ?string $reference): string
    {
        $this->validate($schoolId, $reasonCode, self::RELEASE_REASONS, $reference);

        return (string) DB::connection(self::MAINTENANCE_CONNECTION)->selectOne(
            'SELECT retention_hold_release(?, ?, ?, ?) AS id',
            [$schoolId === null ? 'platform' : 'school', $schoolId, $reasonCode, $reference],
        )->id;
    }

    /**
     * The hold history, newest first (operator maintenance).
     *
     * @return list<object>
     */
    public function history(bool $activeOnly = false): array
    {
        $query = DB::connection(self::MAINTENANCE_CONNECTION)->table('retention_holds')->orderByDesc('placed_at');

        return ($activeOnly ? $query->whereNull('released_at') : $query)->get()->all();
    }

    /**
     * ADD-ONLY reconciliation of the transitional configuration: places a
     * hold for every configured School that exists and is not actively held,
     * and the platform hold when RETENTION_HOLD_PLATFORM is set. Never
     * releases anything.
     *
     * @return array{placed: int, unknown: list<string>}
     */
    public function reconcileConfiguration(): array
    {
        $configured = $this->heldSchoolIds();
        $existing = DB::connection(self::MAINTENANCE_CONNECTION)->table('schools')
            ->whereIn('id', array_values(array_filter($configured, fn (string $id) => Str::isUuid($id))))->pluck('id')->map(fn ($id) => (string) $id)->all();

        $placed = 0;
        foreach ($existing as $schoolId) {
            $placed += $this->place($schoolId, 'configuration_transition', null, 'configuration_reconciliation')['created'] ? 1 : 0;
        }
        if ((bool) config('retention.hold_platform', false)) {
            $placed += $this->place(null, 'configuration_transition', null, 'configuration_reconciliation')['created'] ? 1 : 0;
        }

        return ['placed' => $placed, 'unknown' => array_values(array_diff($configured, $existing))];
    }

    /** @return list<string> the transitional configured School holds */
    public function heldSchoolIds(): array
    {
        return array_values(array_map('strval', (array) config('retention.hold_school_ids', [])));
    }

    /** @param  list<string>  $reasons */
    private function validate(?string $schoolId, string $reasonCode, array $reasons, ?string $reference): void
    {
        if ($schoolId !== null && ! Str::isUuid($schoolId)) {
            throw new InvalidArgumentException('Not a School id.');
        }
        if (! in_array($reasonCode, $reasons, true)) {
            throw new InvalidArgumentException('Choose one of: '.implode(', ', $reasons).'.');
        }
        if ($reference !== null && preg_match(self::REFERENCE_PATTERN, $reference) !== 1) {
            throw new InvalidArgumentException('The reference is a change/ticket token: letters, digits and . _ : / - only, at most 64 characters.');
        }
    }
}
