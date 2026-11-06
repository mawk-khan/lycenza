<?php

namespace App\Support\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * HR.R1-C5 (PAY-15): a rollback can never act on rows it could not see.
 *
 * FORCE ROW LEVEL SECURITY binds a table's owner. A role without BYPASSRLS
 * reading such a table with no tenant context gets an empty answer, and a
 * `down()` guard written as "refuse if rows exist" then passes and destroys what
 * it protects. A `down()` that reads nothing is worse: DROP is never filtered, so
 * it destroys rows that role could not even see. Three migrations need BYPASSRLS
 * (ADR 0021), so this is reachable only by a role that lost it or stopped part
 * way, but the failure is silent and the evidence is gone.
 *
 * So every `down()`:
 *  - is refused before it starts unless the role sees every row (superuser or
 *    BYPASSRLS), whether or not the table is really empty, because that role
 *    cannot tell;
 *  - runs with `row_security = off`, which bypasses nothing: it turns silent
 *    filtering into an error, for a read through something that does not run as
 *    this role, such as a security-definer function.
 *
 * It lives here, not in each `down()`, because published migrations are never
 * rewritten (ADR 0066 §15.1), and so it also covers every future guard and every
 * framework path that runs a `down()`: rollback, reset and refresh. `SET LOCAL`
 * ends with the migration's own transaction, which a refusal rolls back. Earlier
 * migrations of the same command stay rolled back, each being its own transaction.
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

        // A pretend run executes nothing: there is nothing to protect and no transaction to hold the setting.
        if ($connection->pretending()) {
            parent::runMethod($connection, $migration, $method);

            return;
        }

        $name = $this->migrationName($migration);
        if ($connection->getDriverName() === 'pgsql') {
            if ($connection->transactionLevel() === 0) {
                // No transaction would mean no SET LOCAL and no way back from a refusal.
                throw new LogicException("{$name} rolls back outside a transaction: its safety cannot be established.");
            }
            $role = $connection->selectOne('SELECT r.rolsuper OR r.rolbypassrls AS sees_all FROM pg_roles r WHERE r.rolname = current_user');
            if ($role === null || ! $role->sees_all) {
                $this->refuse($connection, $name, 'This role cannot see every row, because it has neither BYPASSRLS nor superuser.');
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
            $this->refuse($connection, $name, $reason, $e);
        }
    }

    private function refuse(Connection $connection, string $name, string $reason, ?\Throwable $previous = null): never
    {
        Log::warning('migration.rollback_refused', [
            'migration' => $name,
            'connection' => $connection->getName(),
            'role' => $connection->getConfig('username'),
            'environment' => app()->environment(),
            'reason' => $reason,
        ]);

        throw new RollbackRefusedException($name, $reason, $previous);
    }

    private function migrationName(object $migration): string
    {
        $file = (new \ReflectionObject($migration))->getFileName();

        return $file === false ? $migration::class : $this->getMigrationName($file);
    }
}
