<?php

namespace Tests\Concerns;

use App\Support\Retention\RetentionAnchors;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * E21-RH.7 (ADR 0066 §15): retention deletes only rows the database recorded
 * before the unit's cutoff (`retention_recorded_at`). A committed fixture
 * written "years ago" (its clocks in the past) is recorded NOW by the
 * database; this aligns every anchor recorded since a checkpoint to a time
 * long past, so the fixture's clocks alone decide, as they did before.
 *
 * Owner session in replica mode (the append-only and immutability guards
 * would refuse the maintenance update otherwise); SKIP LOCKED and a lock
 * timeout, so a concurrently held row never blocks it; no events (a test
 * watching which connections a run used never sees it). Test database only.
 */
trait AlignsRetentionAnchors
{
    /** The database clock now: anchors recorded from here on belong to this test. */
    protected function retentionAnchorCheckpoint(): string
    {
        return $this->fixturesOwner()->selectOne("SELECT to_char(clock_timestamp() AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') AS t")->t;
    }

    protected function alignRetentionAnchorsSince(string $from): void
    {
        // Only committed fixtures can be aligned: a test still inside its own transaction holds row locks the
        // owner session would wait on, and its uncommitted rows are invisible to that session anyway.
        if (DB::connection('pgsql')->transactionLevel() > 0) {
            return;
        }
        $admin = $this->fixturesOwner();
        $tables = "'".implode("', '", array_keys(RetentionAnchors::TABLES))."'";
        $admin->transaction(function () use ($admin, $from, $tables): void {
            $admin->statement("SET LOCAL session_replication_role = 'replica'");
            $admin->statement("SET LOCAL lock_timeout = '5s'");
            $admin->unprepared(<<<SQL
                DO \$\$
                DECLARE v_table text;
                BEGIN
                    FOREACH v_table IN ARRAY ARRAY[{$tables}] LOOP
                        EXECUTE format('UPDATE public.%I SET retention_recorded_at = %L WHERE ctid = ANY (ARRAY(SELECT ctid FROM public.%I WHERE retention_recorded_at >= %L FOR UPDATE SKIP LOCKED))',
                            v_table, '1970-01-01 00:00:00', v_table, '{$from}');
                    END LOOP;
                END
                \$\$;
                SQL);
        });
    }

    /** A distinct alias of the owner connection, with no event dispatcher. */
    protected function fixturesOwner(): Connection
    {
        config(['database.connections.fixtures_owner' => config('database.connections.pgsql_admin')]);
        $admin = DB::connection('fixtures_owner');
        $admin->unsetEventDispatcher();

        return $admin;
    }
}
