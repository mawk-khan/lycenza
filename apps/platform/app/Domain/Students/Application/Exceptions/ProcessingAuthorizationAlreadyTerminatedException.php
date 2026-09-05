<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * Thrown when a terminal action (withdraw/revoke/supersede) targets a
 * grant that a concurrent request has already terminated. The
 * `student_processing_authorizations_one_termination_per_grant`
 * partial unique index is the real, database-authoritative guarantee
 * this maps to (`UniqueConstraintViolationException` translated) --
 * see StudentProcessingAuthorizationService::terminate().
 */
class ProcessingAuthorizationAlreadyTerminatedException extends StudentException
{
    public function __construct()
    {
        parent::__construct(409, 'PROCESSING_AUTHORIZATION_ALREADY_TERMINATED', 'This processing-authorization grant has already been withdrawn, revoked, or superseded.');
    }
}
