<?php

namespace App\Domain\Guardians\Application\Exceptions;

/**
 * SIS 11.H1: the Student–Guardian relationship changed after this form was
 * opened (StudentGuardianRelationshipVersion). Nothing was written; no value
 * appears in the message.
 */
class GuardianRelationshipChangedException extends GuardianException
{
    public function __construct()
    {
        parent::__construct(409, 'GUARDIAN_RELATIONSHIP_CHANGED', 'This relationship changed after you opened it. Nothing was saved. Reload and review the latest information before saving again.');
    }
}
