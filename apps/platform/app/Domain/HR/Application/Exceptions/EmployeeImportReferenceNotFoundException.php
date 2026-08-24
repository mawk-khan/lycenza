<?php

namespace App\Domain\HR\Application\Exceptions;

use RuntimeException;

/**
 * Phase 8A.12 -- thrown by `EmployeeImportService` when a row's
 * `position_code`/`department_code`/`campus_code` does not resolve to
 * a row within the AUTHORITATIVE import School (the lookup is always
 * `where('school_id', $school->id)` -- never global). This is also the
 * exact result for a code that belongs to a DIFFERENT School: the
 * lookup simply finds nothing, which is indistinguishable from a
 * genuine typo -- never a distinguishing cross-School existence signal
 * (checkpoint brief section 28/75).
 */
class EmployeeImportReferenceNotFoundException extends RuntimeException
{
    public function __construct(public readonly string $referenceType, public readonly string $referenceCode)
    {
        parent::__construct("No {$referenceType} with code '{$referenceCode}' exists in this School.");
    }
}
