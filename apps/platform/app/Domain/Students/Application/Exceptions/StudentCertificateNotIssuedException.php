<?php

namespace App\Domain\Students\Application\Exceptions;

/** SIS.EXP-4: only an ISSUED certificate can be voided or replaced; a void is final. */
class StudentCertificateNotIssuedException extends StudentException
{
    public function __construct()
    {
        parent::__construct(409, 'STUDENT_CERTIFICATE_NOT_ISSUED', 'This certificate is already void and cannot be changed.');
    }
}
