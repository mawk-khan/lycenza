<?php

namespace App\Domain\Identity\Application\Exceptions;

/**
 * Thrown when attempting to unlink a Student/Guardian that has no
 * currently active account link.
 */
class NoActiveAccountLinkException extends AccountLinkException
{
    public function __construct()
    {
        parent::__construct('This Student/Guardian has no active account link to remove.');
    }
}
