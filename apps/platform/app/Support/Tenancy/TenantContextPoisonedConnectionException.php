<?php

namespace App\Support\Tenancy;

use RuntimeException;
use Throwable;

/**
 * Phase 1B.4A: thrown when TenantContext::withSchool() finishes its
 * callback WITHOUT an exception propagating, but the database
 * connection was nonetheless left in PostgreSQL's aborted-transaction
 * state (SQLSTATE 25P02) -- meaning the callback caught and swallowed
 * its own database exception internally, leaving the connection
 * unusable without telling anyone. TenantContext cannot restore the
 * previous School/Campus context on the database side in this state
 * (see docs/architecture/TENANCY.md, "Root cause: aborted-transaction
 * cleanup"), and never silently pretends the restore succeeded --
 * this exception is the honest signal that the connection is poisoned
 * and whatever caught the original error must be fixed to let it
 * propagate (or to roll back its own transaction) instead of
 * swallowing it.
 *
 * Distinct from the SAME underlying SQLSTATE encountered while an
 * original exception is ALREADY propagating out of the callback (the
 * far more common case, where the callback itself failed loudly) --
 * that case is proven safe to silently skip (the eventual ROLLBACK of
 * whichever transaction the callback's own failure aborted already
 * reverts the GUC automatically) and does NOT raise this exception;
 * see withSchool()'s exception-path handling.
 */
class TenantContextPoisonedConnectionException extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct(
            'TenantContext could not restore School context: the database connection was left in an '.
            'aborted-transaction state by code that caught its own database exception without rolling back '.
            'or rethrowing it. Fix the caller to let the original exception propagate (or to roll back its own '.
            'transaction) rather than swallowing it.',
            previous: $previous,
        );
    }
}
