<?php

namespace App\Domain\HR\Application\Exceptions;

/** HR.R1-C8: the requester, or an account known to be the same person, never decides. */
class EmployeeAccountLinkCorrectionNotIndependentException extends HrException
{
    public function __construct(public readonly string $correctionId = '')
    {
        parent::__construct(403, 'HR_ACCOUNT_LINK_CORRECTION_NOT_INDEPENDENT', 'Someone other than the requester must decide this correction.');
    }
}
