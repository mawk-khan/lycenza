<?php

namespace App\Domain\Hostel\Application\Exceptions;

/**
 * OPF.2 (ADR 0067 D8): Hostel fee intent is carried forward only into a
 * named academic year of the School that is not closed.
 */
class HostelCarryForwardYearInvalidException extends HostelException
{
    public function __construct()
    {
        parent::__construct(422, 'HOSTEL_CARRY_FORWARD_YEAR_INVALID', 'The academic year must be a draft or active academic year of this School.');
    }
}
