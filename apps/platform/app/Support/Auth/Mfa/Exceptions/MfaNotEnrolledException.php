<?php

namespace App\Support\Auth\Mfa\Exceptions;

/** disable()/regenerate() was called for a User with no active MFA factor. */
class MfaNotEnrolledException extends MfaException
{
    public function __construct()
    {
        parent::__construct(409, 'MFA_NOT_ENROLLED', 'This account does not currently have an active MFA factor.');
    }
}
