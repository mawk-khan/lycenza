<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * SIS.EXP-3: the Student document record changed after this form was
 * opened (its version moved on). Nothing was written.
 */
class StudentDocumentChangedException extends StudentException
{
    public function __construct()
    {
        parent::__construct(409, 'STUDENT_DOCUMENT_CHANGED', 'This document was changed by someone else after you opened this form. Nothing was saved. Reload the page and try again.');
    }
}
