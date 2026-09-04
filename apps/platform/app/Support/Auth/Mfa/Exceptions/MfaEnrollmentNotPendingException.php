<?php

namespace App\Support\Auth\Mfa\Exceptions;

/** confirm() was called with no pending factor for this User to confirm. */
class MfaEnrollmentNotPendingException extends MfaException
{
    public function __construct()
    {
        parent::__construct(409, 'MFA_ENROLLMENT_NOT_PENDING', 'There is no pending MFA enrollment to confirm.');
    }
}
