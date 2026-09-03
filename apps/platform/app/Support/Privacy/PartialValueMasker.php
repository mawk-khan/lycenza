<?php

namespace App\Support\Privacy;

/**
 * Checkpoint 9.6I -- shared first-2/last-2-visible masking, extracted
 * from `StatutoryTdsDraftStatementExportService`'s original inline
 * implementation once a second and third call site (the statutory
 * identifier admin read service, the statutory payslip extension)
 * needed the identical rule. A short static helper, not a new
 * abstraction layer -- there is exactly one masking policy in this
 * codebase so far, applied consistently.
 */
class PartialValueMasker
{
    public static function mask(string $value): string
    {
        $length = mb_strlen($value);
        if ($length <= 4) {
            return str_repeat('X', $length);
        }

        return mb_substr($value, 0, 2).str_repeat('X', $length - 4).mb_substr($value, -2);
    }
}
