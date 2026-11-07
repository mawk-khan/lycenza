<?php

namespace App\Domain\HR\Application\Exceptions;

/** HR.R1-C8: one open request per link. */
class EmployeeAccountLinkCorrectionAlreadyRequestedException extends HrException
{
    public function __construct(public readonly string $correctionId = '')
    {
        parent::__construct(409, 'HR_ACCOUNT_LINK_CORRECTION_ALREADY_REQUESTED', 'A correction of this link is already waiting for a decision.');
    }
}
