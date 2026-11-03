<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * HR.3A: a reporting-line change was refused before anything was written.
 * `$reason` is a closed code: `same_employee`, `cross_school`,
 * `outside_employment`, `no_line_on_date`, `later_line_exists`,
 * `unchanged`, `already_voided`, `before_line_start`, `not_live`.
 * Never names another Employee or record.
 */
class InvalidReportingChangeException extends HrException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct(422, 'HR_INVALID_REPORTING_CHANGE', $message);
    }
}
