<?php

namespace App\Domain\HR\Application\Exceptions;

/** HR.R1-C8: a closed reason, the same for every way of not qualifying. */
class EmployeeAccountLinkCorrectionNotQualifiedException extends HrException
{
    public function __construct(public readonly string $correctionId = '')
    {
        parent::__construct(403, 'HR_ACCOUNT_LINK_CORRECTION_NOT_QUALIFIED', 'This needs a current staff member, linked to their own Employee record, who has never been linked to this Employee.');
    }
}
