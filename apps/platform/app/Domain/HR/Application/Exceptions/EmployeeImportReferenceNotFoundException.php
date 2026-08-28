<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * Phase 8A.12 -- thrown by `EmployeeImportService` when a row's
 * `position_code`/`department_code`/`campus_code` does not resolve to
 * a row within the AUTHORITATIVE import School (the lookup is always
 * `where('school_id', $school->id)` -- never global). This is also the
 * exact result for a code that belongs to a DIFFERENT School: the
 * lookup simply finds nothing, which is indistinguishable from a
 * genuine typo -- never a distinguishing cross-School existence signal
 * (checkpoint brief section 28/75). Always caught internally by
 * `EmployeeImportService::describeFailure()` and translated into a
 * per-row `{field, code, message}` result -- never reaches HTTP
 * directly, but still extends `HrException` for consistency with every
 * other HR domain exception.
 */
class EmployeeImportReferenceNotFoundException extends HrException
{
    public function __construct(public readonly string $referenceType, public readonly string $referenceCode)
    {
        parent::__construct(422, 'HR_IMPORT_REFERENCE_NOT_FOUND', "No {$referenceType} with code '{$referenceCode}' exists in this School.");
    }
}
