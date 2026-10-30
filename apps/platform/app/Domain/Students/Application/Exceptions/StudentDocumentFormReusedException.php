<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * SIS.EXP-3: an upload form key already recorded a document for another
 * Student or by another person. Nothing was written.
 */
class StudentDocumentFormReusedException extends StudentException
{
    public function __construct()
    {
        parent::__construct(409, 'STUDENT_DOCUMENT_FORM_REUSED', 'This upload form was already used. Reload the page and upload again.');
    }
}
