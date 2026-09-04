<?php

namespace App\Support\Auth\Mfa\Exceptions;

/** The User already has an active MFA factor -- v1's one-active-factor policy (ADR 0037). */
class MfaAlreadyEnrolledException extends MfaException
{
    public function __construct()
    {
        parent::__construct(409, 'MFA_ALREADY_ENROLLED', 'This account already has an active MFA factor.');
    }
}
