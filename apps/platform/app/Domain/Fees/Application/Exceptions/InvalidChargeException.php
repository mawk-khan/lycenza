<?php

namespace App\Domain\Fees\Application\Exceptions;

/**
 * Covers basic shape/validation failures of an assessment command that
 * are cheaper and safer to reject before ever reaching PostgreSQL (an
 * empty/over-length description, a currency other than INR) --
 * mirrors App\Domain\Finance\Application\Exceptions\InvalidJournalEntryException's
 * "one stable exception type per validation family" convention. The
 * database's own CHECK constraints (`charges_amount_positive_check`,
 * `charges_currency_inr_only_check`) remain the authoritative defense
 * regardless.
 */
class InvalidChargeException extends FeesException
{
    public function __construct(string $reason)
    {
        parent::__construct(422, 'INVALID_CHARGE', $reason);
    }
}
