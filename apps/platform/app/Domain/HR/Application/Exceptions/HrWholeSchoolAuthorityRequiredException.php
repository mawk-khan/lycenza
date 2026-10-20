<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * ORG.7E-1 (ADR 0076 §43): a Campus-scoped HR actor reached an Employee (one
 * current assignment is in scope) but the change needs authority over MORE
 * than that -- every current Campus of the Employee (an identity edit, a new
 * assignment), or whole-School authority (a NULL-Campus assignment, history).
 * It never names the other Campus, assignment or record.
 */
class HrWholeSchoolAuthorityRequiredException extends HrException
{
    public const string CODE = 'HR_WHOLE_SCHOOL_AUTHORITY_REQUIRED';

    public function __construct()
    {
        parent::__construct(403, self::CODE, 'This change needs HR authority over every Campus this Employee works on. Ask a whole-School HR administrator.');
    }
}
