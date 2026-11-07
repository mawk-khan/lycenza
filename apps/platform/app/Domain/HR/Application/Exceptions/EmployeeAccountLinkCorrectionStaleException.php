<?php

namespace App\Domain\HR\Application\Exceptions;

/** HR.R1-C8: an approval binds the link history it was shown. */
class EmployeeAccountLinkCorrectionStaleException extends HrException
{
    public function __construct(public readonly string $correctionId = '')
    {
        parent::__construct(409, 'HR_ACCOUNT_LINK_CORRECTION_STALE', "This Employee's account links have changed since this was requested. Refuse it and request a new correction.");
    }
}
