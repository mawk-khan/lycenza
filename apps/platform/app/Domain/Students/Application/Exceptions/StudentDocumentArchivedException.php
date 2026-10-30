<?php

namespace App\Domain\Students\Application\Exceptions;

/** SIS.EXP-3: an archived Student document is final -- it is never edited or archived again. */
class StudentDocumentArchivedException extends StudentException
{
    public function __construct()
    {
        parent::__construct(409, 'STUDENT_DOCUMENT_ARCHIVED', 'This document is archived and can no longer be changed. Upload a new document instead.');
    }
}
