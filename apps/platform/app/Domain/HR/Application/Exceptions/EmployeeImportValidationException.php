<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * Phase 8A.12 -- thrown by `EmployeeImportRow::fromArray()` for a
 * structurally-recognized but invalid row (a required field missing/
 * blank, an unsupported `employment_type` value, a malformed date, an
 * Employment/Assignment block that is only partially supplied). Kept
 * distinct from `EmployeeImportUnknownFieldException` (an unrecognized
 * key) so `EmployeeImportService` can report a precise, safe
 * `field`/`message` pair without ever echoing the row's actual values.
 * Also thrown directly by `EmployeeImportService::import()` for a
 * batch exceeding `MAX_ROWS_PER_BATCH` -- that one path is NOT caught
 * internally and does reach the HTTP layer as a 422.
 */
class EmployeeImportValidationException extends HrException
{
    public function __construct(public readonly ?string $field, string $message)
    {
        parent::__construct(422, 'HR_IMPORT_VALIDATION', $message);
    }
}
