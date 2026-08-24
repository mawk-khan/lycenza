<?php

namespace App\Domain\Identity\Application\Exceptions;

/**
 * Thrown when linking a Student/Guardian that already has an active
 * account link -- the caller must unlink first (root CLAUDE.md
 * "explicit, never inferred" discipline: no silent re-link/replace).
 */
class PersonaAlreadyLinkedException extends AccountLinkException
{
    public function __construct()
    {
        parent::__construct('This Student/Guardian already has an active account link. Unlink it first.');
    }
}
