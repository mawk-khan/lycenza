<?php

namespace App\Domain\Students\Application\Exceptions;

/** SIS.EXP-4: the facts the issuer previewed are no longer the facts under the lock (stale preview). Nothing was written. */
class StudentCertificateChangedException extends StudentException
{
    public function __construct()
    {
        parent::__construct(409, 'STUDENT_CERTIFICATE_CHANGED', 'This Student\'s record changed after the preview was shown. Nothing was issued. Review the new preview and issue again.');
    }
}
