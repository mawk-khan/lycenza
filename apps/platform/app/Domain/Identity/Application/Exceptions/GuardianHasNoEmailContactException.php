<?php

namespace App\Domain\Identity\Application\Exceptions;

/**
 * Brief §10: no fake invitation is ever issued when a Guardian has no
 * usable email contact -- an honest, actionable error instead.
 */
class GuardianHasNoEmailContactException extends AccountInvitationException
{
    public function __construct()
    {
        parent::__construct('This Guardian has no active, valid email contact on file. Add one before inviting.');
    }
}
