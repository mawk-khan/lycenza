<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * SIS.EXP-4: an issued certificate's document belongs to the certificate
 * workflow -- it is never edited or archived from the document list (void the
 * certificate instead).
 */
class StudentDocumentGeneratedException extends StudentException
{
    public function __construct()
    {
        parent::__construct(409, 'STUDENT_DOCUMENT_GENERATED', 'This is an issued certificate. It cannot be changed here: void or reissue it from the Student\'s certificates.');
    }
}
