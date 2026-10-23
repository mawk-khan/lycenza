<?php

namespace App\Domain\Payments\Application\Charges;

use RuntimeException;

/**
 * ORG.9B (ADR 0076 §68): an as-at charge-state read was asked for a date
 * before the end of the latest financial period whose detail retention
 * expired (ADR 0064 §15) -- that history is no longer retained, so no
 * figure is given rather than a wrong one.
 */
final class ChargeHistoryExpiredException extends RuntimeException
{
    public function __construct(public readonly string $retainedFrom)
    {
        parent::__construct('Charge history before '.$retainedFrom.' is no longer retained.');
    }
}
