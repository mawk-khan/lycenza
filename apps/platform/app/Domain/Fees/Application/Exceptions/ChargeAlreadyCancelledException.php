<?php

namespace App\Domain\Fees\Application\Exceptions;

/**
 * At most one cancellation per charge -- raised both by
 * `ChargeService::cancel()`'s own pre-check (the common, sequential
 * case) and when its conditional `UPDATE ... WHERE cancelled_at IS
 * NULL` affects zero rows (the genuine concurrent-race case: two
 * callers raced, PostgreSQL's row-level locking serialized them, and
 * the loser's WHERE clause found the row already cancelled). Both
 * paths raise this SAME exception -- from the caller's perspective the
 * outcome is identical, mirroring
 * App\Domain\Finance\Application\Exceptions\JournalEntryAlreadyReversedException's
 * exact "no distinction between who found out first" reasoning.
 */
class ChargeAlreadyCancelledException extends FeesException
{
    public function __construct(string $chargeId)
    {
        parent::__construct(409, 'CHARGE_ALREADY_CANCELLED', "Charge '{$chargeId}' has already been cancelled.");
    }
}
