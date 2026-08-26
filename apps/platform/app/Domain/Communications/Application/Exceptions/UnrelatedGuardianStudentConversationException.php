<?php

namespace App\Domain\Communications\Application\Exceptions;

/**
 * Thrown when a thread would include both a Guardian and a Student
 * participant with no eligible StudentGuardianRelationship between
 * them (brief §22/§23) -- the same `is_primary OR is_legal_guardian`
 * eligibility rule
 * App\Domain\Communications\Application\Audience\GuardianProjectionResolver
 * already established for general-communication audiences, applied
 * here to private-conversation composition. An emergency-contact-only
 * or pickup-only relationship is never sufficient.
 */
class UnrelatedGuardianStudentConversationException extends CommunicationException
{
    public function __construct()
    {
        parent::__construct('The selected Guardian and Student are not linked by an eligible relationship.');
    }
}
