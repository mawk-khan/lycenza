<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * Phase 8A.12 -- thrown by `EmployeeImportRow::fromArray()` when a raw
 * import row contains a key outside the fixed, explicit allow-list
 * (`EmployeeImportRow::ALLOWED_KEYS`). Import is an UNTRUSTED INPUT
 * boundary (checkpoint brief section 44/62): a raw associative array is
 * never mass-assigned into a domain service call, and an unrecognized
 * key -- whether a typo, a caller-supplied ownership field
 * (`school_id`/`employee_id`/`uploaded_by_user_id`/`storage_path`), or
 * an unsupported Highly Sensitive field (a government id, a bank
 * account number, ...) -- is rejected explicitly rather than silently
 * dropped, so an operator is never misled into believing a field was
 * imported when it was not. Always caught internally by
 * `EmployeeImportService::importRow()` -- never reaches HTTP directly,
 * but still extends `HrException` for consistency with every other HR
 * domain exception.
 */
class EmployeeImportUnknownFieldException extends HrException
{
    public function __construct(public readonly string $field)
    {
        parent::__construct(422, 'HR_IMPORT_UNKNOWN_FIELD', "Unsupported import field: '{$field}'.");
    }
}
