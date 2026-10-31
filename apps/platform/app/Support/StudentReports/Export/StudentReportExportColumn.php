<?php

namespace App\Support\StudentReports\Export;

use App\Support\StudentReports\StudentReportColumn;

/**
 * SIS 11.4 (D-11): one column of a governed export -- a flat, human-readable
 * value (names, numbers, labels, ISO dates; never an id or a raw code).
 *
 * - Declared, never inferred: only these columns can leave the system, and
 *   a request may only pick among them (a forged key is a validation error).
 * - Prohibited keys (StudentReportColumn::PROHIBITED: dates of birth,
 *   contacts, identifiers, demographics, internal ids...) are refused here
 *   too, so no export can ever carry one.
 * - `requires`: capabilities the viewer must also hold (anywhere for a
 *   Campus-aware report, whole School otherwise) -- the same rule as the
 *   browser column, so an export never shows what the screen hides.
 */
final readonly class StudentReportExportColumn
{
    /** @param  list<string>  $requires */
    public function __construct(
        public string $key,
        public string $label,
        public array $requires = [],
        public bool $default = true,
    ) {
        if (preg_match('/^[a-z][a-z_]*$/', $key) !== 1 || in_array($key, StudentReportColumn::PROHIBITED, true)
            || preg_match('/(^|_)(id|uuid|dob|birth|phone|email|mobile|contact|address|gender|religion|caste|hmac|token)(_|$)/', $key) === 1) {
            throw new \InvalidArgumentException("{$key} is not an allowed export column.");
        }
    }
}
