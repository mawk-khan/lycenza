<?php

namespace App\Domain\Students\Application\Exceptions;

class ProcessingAuthorizationNotFoundException extends StudentException
{
    public function __construct()
    {
        parent::__construct(404, 'PROCESSING_AUTHORIZATION_NOT_FOUND', 'No matching processing-authorization record was found for this Student and School.');
    }
}
