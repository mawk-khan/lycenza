<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * Thrown by App\Domain\HR\Application\EmploymentService::end() when
 * asked to transition an EmploymentRecord to a `$status` outside the
 * closed terminal set (docs/modules/HR.md "Employee lifecycle -- state
 * responsibility matrix": separated|terminated|retired|deceased).
 * Closes the pre-8A.13 gap where `end()` accepted any caller-supplied
 * string verbatim -- a non-terminal value (`active`, `draft`,
 * `pre_joining`, `notice_period`) or an unrecognized one is never a
 * valid target for "ending" an Employment.
 */
class InvalidEmploymentStatusTransitionException extends HrException
{
    public function __construct(
        public readonly string $employmentRecordId,
        public readonly string $attemptedStatus,
    ) {
        parent::__construct(422, 'HR_INVALID_EMPLOYMENT_STATUS_TRANSITION', "'{$attemptedStatus}' is not a valid terminal status for ending EmploymentRecord {$employmentRecordId}.");
    }
}
