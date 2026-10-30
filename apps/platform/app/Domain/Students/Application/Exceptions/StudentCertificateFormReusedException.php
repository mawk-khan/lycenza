<?php

namespace App\Domain\Students\Application\Exceptions;

/** SIS.EXP-4: an issue form key already recorded a different request (another Student, type, preview or person). Nothing was written. */
class StudentCertificateFormReusedException extends StudentException
{
    public function __construct()
    {
        parent::__construct(409, 'STUDENT_CERTIFICATE_FORM_REUSED', 'This issue form was already used for a different request. Reload the page and try again.');
    }
}
