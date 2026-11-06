<?php

namespace App\Support\Database;

use RuntimeException;

/**
 * HR.R1-C5 (PAY-15): a `down()` could not establish what it was protecting.
 * The migration's transaction has rolled back, so nothing changed. It names the
 * migration and the database's reason, never a row.
 */
class RollbackRefusedException extends RuntimeException
{
    public function __construct(string $migration, string $reason, ?\Throwable $previous = null)
    {
        parent::__construct(
            "Rollback refused, nothing changed: {$migration}. {$reason} "
            .'Row-level security would have hidden rows from this role, so an empty answer proves nothing. '
            .'Run as the migration role with BYPASSRLS on a disposable database, or use a forward fix or an approved restore '
            .'(docs/operations/DATABASE-BOOTSTRAP.md, "Rollback safety").',
            0,
            $previous,
        );
    }
}
