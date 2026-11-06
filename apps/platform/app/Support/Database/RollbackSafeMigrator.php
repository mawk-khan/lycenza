<?php

namespace App\Support\Database;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * HR.R1-C5 (PAY-15): a rollback guard can never mistake hidden rows for no rows.
 *
 * FORCE ROW LEVEL SECURITY binds a table's owner. A role without BYPASSRLS
 * reading such a table with no tenant context gets an empty answer, and a
 * `down()` guard written as "refuse if rows exist" then passes and destroys what
 * it protects. Every migration needs BYPASSRLS (ADR 0021) and has it, so this is
 * reachable only by a role that lost it or stopped part way, but the failure is
 * silent and the evidence is gone.
 *
 * With `row_security = off`, PostgreSQL raises an error instead of filtering. So
 * for every `down()`:
 *  - a role that sees every row is unaffected, and its answer is authoritative;
 *  - a role that would have seen a filtered answer is refused, even if the table
 *    really is empty, because it cannot tell. `row_security = off` bypasses
 *    nothing: it only turns silent filtering into a refusal.
 *
 * It lives here, not in each `down()`, because published migrations are never
 * rewritten (ADR 0066 §15.1), and so it also covers every future guard and every
 * framework path that runs a `down()`: rollback, reset and refresh.
 * `SET LOCAL` ends with the migration's own transaction, which the refusal rolls
 * back, so no earlier statement of that `down()` survives it.
 *
 * This does not make a guard atomic with the DDL after it. Rollback is not a
 * sanctioned path in a hardened environment (ADR 0074 §26.7), and those
 * environments refuse the commands outright.
 */
class RollbackSafeMigrator extends Migrator
{
    private const INSUFFICIENT_PRIVILEGE = '42501';

    protected function runMethod($connection, $migration, $method)
    {
        if ($method !== 'down') {
            parent::runMethod($connection, $migration, $method);

            return;
        }

        $name = $this->migrationName($migration);
        if ($connection->getDriverName() === 'pgsql') {
            if ($connection->transactionLevel() === 0) {
                // No transaction would mean no SET LOCAL and no way back from a refusal.
                throw new LogicException("{$name} rolls back outside a transaction: its safety cannot be established.");
            }
            $connection->statement('SET LOCAL row_security = off');
        }

        try {
            parent::runMethod($connection, $migration, $method);
        } catch (QueryException $e) {
            if ((string) $e->getCode() !== self::INSUFFICIENT_PRIVILEGE) {
                throw $e;
            }
            $reason = preg_match('/row-level security policy for table "([^"]+)"/', $e->getMessage(), $m) === 1
                ? "This role cannot see every row of {$m[1]}."
                : 'This role lacks a privilege the rollback needs.';
            Log::warning('migration.rollback_refused', ['migration' => $name, 'connection' => $connection->getName(), 'reason' => $reason]);

            throw new RollbackRefusedException($name, $reason, $e);
        }
    }

    private function migrationName(object $migration): string
    {
        $file = (new \ReflectionObject($migration))->getFileName();

        return $file === false ? $migration::class : $this->getMigrationName($file);
    }
}
