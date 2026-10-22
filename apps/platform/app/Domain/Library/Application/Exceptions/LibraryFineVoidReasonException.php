<?php

namespace App\Domain\Library\Application\Exceptions;

/** ORG.8E (ADR 0076 §63): a fine void names one of the closed void reasons (never a waiver). */
class LibraryFineVoidReasonException extends LibraryException
{
    public function __construct()
    {
        parent::__construct(422, 'LIBRARY_FINE_VOID_REASON_INVALID', 'Choose why this fine is being voided.');
    }
}
