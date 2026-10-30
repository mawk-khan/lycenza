<?php

namespace App\Domain\Students\Application\Exceptions;

/** SIS.EXP-4: one ISSUED certificate per Student, type and exit (partial unique index). */
class StudentCertificateAlreadyIssuedException extends StudentException
{
    public function __construct()
    {
        parent::__construct(409, 'STUDENT_CERTIFICATE_ALREADY_ISSUED', 'A certificate of this type is already issued for this exit. To correct it, use Void and reissue.');
    }
}
