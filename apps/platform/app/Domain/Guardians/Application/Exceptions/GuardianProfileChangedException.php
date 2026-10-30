<?php

namespace App\Domain\Guardians\Application\Exceptions;

/**
 * SIS.HARDEN-1 (SIS.EXP-R1 finding R-1): the Guardian's name changed after
 * this form was opened (GuardianProfileVersion). Nothing was written; no
 * value appears in the message.
 */
class GuardianProfileChangedException extends GuardianException
{
    public function __construct()
    {
        parent::__construct(409, 'GUARDIAN_PROFILE_CHANGED', 'This Guardian\'s details were changed by someone else after you opened this form. Nothing was saved. Reload the page, review the current details and make your change again.');
    }
}
