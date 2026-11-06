<?php

namespace App\Domain\HR\Application\Exceptions;

/** HR.R1-C7: a correction is final, so a second one changes nothing. */
class EmployeeAccountLinkAlreadyCorrectedException extends HrException
{
    public function __construct(public readonly string $linkId)
    {
        parent::__construct(409, 'HR_ACCOUNT_LINK_ALREADY_CORRECTED', 'This account link has already been corrected.');
    }
}
