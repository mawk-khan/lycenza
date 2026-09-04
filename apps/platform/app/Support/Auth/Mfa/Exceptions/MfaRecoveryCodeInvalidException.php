<?php

namespace App\Support\Auth\Mfa\Exceptions;

/** A submitted recovery code did not match any unconsumed code for this User (wrong, already-consumed, or unknown). */
class MfaRecoveryCodeInvalidException extends MfaException
{
    public function __construct()
    {
        parent::__construct(422, 'MFA_RECOVERY_CODE_INVALID', 'That recovery code is not valid.');
    }
}
