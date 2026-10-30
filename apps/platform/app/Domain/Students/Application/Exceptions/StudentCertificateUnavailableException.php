<?php

namespace App\Domain\Students\Application\Exceptions;

/** SIS.EXP-4: the legal production gate (StudentCertificateAvailability). */
class StudentCertificateUnavailableException extends StudentException
{
    public function __construct()
    {
        parent::__construct(409, 'STUDENT_CERTIFICATES_UNAVAILABLE', 'Certificates cannot be issued in this environment yet: the certificate wording still needs legal approval for production use.');
    }
}
