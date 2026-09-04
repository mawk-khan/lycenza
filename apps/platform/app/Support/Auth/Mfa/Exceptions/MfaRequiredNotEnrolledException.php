<?php

namespace App\Support\Auth\Mfa\Exceptions;

/**
 * Thrown by the `mfa` route middleware: the route requires MFA
 * assurance, but this User has no active factor to even challenge --
 * they must enroll before this route becomes reachable at all.
 */
class MfaRequiredNotEnrolledException extends MfaException
{
    public function __construct()
    {
        parent::__construct(403, 'mfa_required_not_enrolled', 'This action requires multi-factor authentication. Enroll an MFA factor before continuing.');
    }
}
