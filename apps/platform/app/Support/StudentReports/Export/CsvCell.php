<?php

namespace App\Support\StudentReports\Export;

/**
 * SIS 11.4 (D-11): spreadsheet formula-injection defence for every exported
 * cell (OWASP "CSV injection"). A spreadsheet treats a cell as a formula
 * when its first meaningful character is `=`, `+`, `-` or `@`, or when it
 * starts with a tab or carriage return; leading spaces or other control
 * characters do not stop that. Such a cell is prefixed with a single quote,
 * which spreadsheets show as text and never evaluate. A plain number (e.g.
 * "-1" seats) is left as it is: it is not a formula.
 */
final class CsvCell
{
    public static function safe(string|int|null $value): string
    {
        if ($value === null) {
            return '';
        }
        $text = (string) $value;
        if (preg_match('/^-?\d+(\.\d+)?$/', $text) === 1) {
            return $text;
        }

        return preg_match('/^[\x00-\x20]*[=+\-@\t\r]/', $text) === 1 ? "'".$text : $text;
    }
}
