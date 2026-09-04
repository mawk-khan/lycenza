<?php

namespace App\Support\Auth\Mfa\Exceptions;

/** A submitted TOTP code did not verify against the factor's secret within the configured window. */
class MfaInvalidCodeException extends MfaException
{
    public function __construct()
    {
        parent::__construct(422, 'MFA_INVALID_CODE', 'That authentication code is not valid.');
    }
}
