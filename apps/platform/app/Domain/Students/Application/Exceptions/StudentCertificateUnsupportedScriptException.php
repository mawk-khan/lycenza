<?php

namespace App\Domain\Students\Application\Exceptions;

/** SIS.EXP-4 (owner decision): the generic renderer prints Latin-script text only; another script is refused until a script-capable renderer is decided. */
class StudentCertificateUnsupportedScriptException extends StudentException
{
    public function __construct()
    {
        parent::__construct(422, 'STUDENT_CERTIFICATE_UNSUPPORTED_SCRIPT', 'This certificate contains characters the certificate template cannot print yet (only Latin-script text is supported). Nothing was issued.');
    }
}
