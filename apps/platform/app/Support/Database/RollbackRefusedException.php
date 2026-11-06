<?php

namespace App\Support\Database;

use RuntimeException;

/**
 * HR.R1-C5 (PAY-15): a `down()` could not establish what it was protecting.
 * That migration's transaction has rolled back. It names the migration and the
 * reason, never a row.
 */
class RollbackRefusedException extends RuntimeException
{
    public function __construct(string $migration, string $reason, ?\Throwable $previous = null)
    {
        parent::__construct(
            "Rollback refused: {$migration} was not rolled back, and its changes were undone. {$reason} "
            .'Any earlier migration in this command stays rolled back. '
            .'On a disposable database, run as a role with BYPASSRLS. Anywhere else, use a forward fix or an approved restore '
            .'(docs/operations/DATABASE-BOOTSTRAP.md, "Rollback safety").',
            0,
            $previous,
        );
    }
}
