<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * HR.2A: the profile record changed after the form was opened (its version
 * token no longer matches). Nothing was written; reload and try again.
 */
class EmployeeProfileChangedException extends HrException
{
    public function __construct()
    {
        parent::__construct(409, 'HR_PROFILE_CHANGED', 'This profile was changed by someone else after you opened it. Reload the page and make your change again.');
    }
}
