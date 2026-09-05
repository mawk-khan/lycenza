<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * `guardian_consent` requires a specific StudentGuardianRelationship
 * that belongs to the same School and the same Student AND currently
 * has `is_legal_guardian = true` -- an ordinary contact/emergency-
 * contact relationship is never sufficient (see the creating
 * migration's docblock and ADR 0038).
 */
class GuardianRelationshipNotEligibleException extends StudentException
{
    public function __construct()
    {
        parent::__construct(422, 'GUARDIAN_RELATIONSHIP_NOT_ELIGIBLE', 'The referenced Guardian relationship does not belong to this Student/School, or is not currently a legal-guardian relationship.');
    }
}
