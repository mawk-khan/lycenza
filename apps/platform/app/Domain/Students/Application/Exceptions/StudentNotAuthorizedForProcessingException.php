<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * Thrown by StudentProcessingAuthorizationReadService::
 * assertAuthorizedForProcessing() (and its lock-capable counterpart)
 * when no currently-qualifying, active StudentProcessingAuthorization
 * exists for the given Student/purpose. This is the exception a
 * future StudentMark creation path is expected to catch -- Students/
 * SIS owns the interpretation of age/basis/state/validity;
 * Examinations only ever receives this authoritative yes/no decision.
 */
class StudentNotAuthorizedForProcessingException extends StudentException
{
    public function __construct(string $purpose)
    {
        parent::__construct(403, 'STUDENT_NOT_AUTHORIZED_FOR_PROCESSING', "No currently qualifying processing authorization exists for this Student for purpose \"{$purpose}\".");
    }
}
