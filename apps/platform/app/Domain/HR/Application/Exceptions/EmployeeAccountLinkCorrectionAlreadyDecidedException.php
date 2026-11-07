<?php

namespace App\Domain\HR\Application\Exceptions;

/** HR.R1-C8: a decision is final. */
class EmployeeAccountLinkCorrectionAlreadyDecidedException extends HrException
{
    public function __construct(public readonly string $correctionId = '')
    {
        parent::__construct(409, 'HR_ACCOUNT_LINK_CORRECTION_ALREADY_DECIDED', 'This correction has already been decided.');
    }
}
