<?php

namespace App\Domain\Fees\Application\Exceptions;

/**
 * ORG.8G (ADR 0076 §65): the person ending this Charge is a Guardian of its
 * Student (an account link, active or revoked, to a Guardian currently
 * related to the Student -- the ORG.8A-R1 rule), so someone else must do it.
 * Fixed, generic text: it never says who is whose Guardian.
 */
class ChargeCancellationConflictException extends FeesException
{
    public function __construct(string $chargeId)
    {
        parent::__construct(403, 'CHARGE_CANCELLATION_CONFLICT', "Charge '{$chargeId}' must be cancelled by someone else.");
    }
}
