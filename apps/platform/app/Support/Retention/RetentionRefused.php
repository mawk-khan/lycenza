<?php

namespace App\Support\Retention;

use RuntimeException;

/**
 * E21-RH.2 (ADR 0066 §5, §6.1): a destructive retention unit refused to
 * start because its execution identity or the hold state could not be
 * established. Nothing was deleted. The reason is a closed code, never a
 * credential, identifier or row.
 */
final class RetentionRefused extends RuntimeException
{
    /** The retention connection has no credential configured. */
    public const UNCONFIGURED = 'retention_identity_unconfigured';

    /** The retention connection could not be opened. */
    public const UNREACHABLE = 'retention_identity_unreachable';

    /** The connection authenticated as some other (or an elevated) login. */
    public const MISMATCH = 'retention_identity_mismatch';

    /** A configured School hold is not recorded in the database (run platform:retention-holds-sync). */
    public const HOLD_STATE_STALE = 'retention_hold_state_stale';

    public function __construct(public readonly string $reason)
    {
        parent::__construct("Destructive retention refused ({$reason}); nothing was deleted.");
    }
}
