<?php

namespace App\Domain\Transport\Application\Exceptions;

/**
 * OPF.1 (ADR 0067 D8): Transport fee intent is carried forward only into a
 * named academic year of the School that is not closed.
 */
class TransportCarryForwardYearInvalidException extends TransportException
{
    public function __construct()
    {
        parent::__construct(422, 'TRANSPORT_CARRY_FORWARD_YEAR_INVALID', 'The academic year must be a draft or active academic year of this School.');
    }
}
