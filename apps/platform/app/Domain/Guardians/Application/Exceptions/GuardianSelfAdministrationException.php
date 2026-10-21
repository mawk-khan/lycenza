<?php

namespace App\Domain\Guardians\Application\Exceptions;

/**
 * ORG.8A-R1 (ADR 0076 §59): nobody administers the Student relationships of a
 * Guardian identity linked to their own account. Another authorized
 * administrator can. Fixed text: no account-link or relationship detail.
 */
class GuardianSelfAdministrationException extends GuardianException
{
    public function __construct()
    {
        parent::__construct(403, 'GUARDIAN_SELF_ADMINISTRATION', 'Another administrator must make this change.');
    }
}
