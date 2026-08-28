<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * Thrown for either of two distinct effective-date violations sharing
 * one safe error category (checkpoint 8A.13 section 57's taxonomy:
 * `invalid_effective_date`):
 *
 * - `before_employment_start` -- App\Domain\HR\Application\EmploymentService::end()
 *   rejects an `$endsOn` before the EmploymentRecord's own `starts_on`
 *   (docs/modules/HR.md section 14: "separation/end date >=
 *   Employment starts_on"). The database's own
 *   `employment_records_date_range_check` CHECK constraint would also
 *   reject this, but this application-level check gives a clean
 *   exception instead of a raw SQLSTATE.
 * - `future_dated` -- App\Domain\HR\Application\EmployeeLifecycleService::separate()
 *   rejects an `$endsOn` after today (section 15: "If future-dated
 *   separation is NOT explicitly required, prefer rejecting it" --
 *   HR.md does not define scheduled-separation semantics, so this is
 *   the conservative default; deliberately NOT enforced inside the
 *   lower-level `EmploymentService::end()` itself, which remains a
 *   more permissive primitive for any future correction workflow that
 *   may legitimately need to backdate/end a historical record).
 */
class InvalidEmploymentEffectiveDateException extends HrException
{
    public function __construct(
        public readonly string $employmentRecordId,
        public readonly string $attemptedEndsOn,
        public readonly string $reason,
    ) {
        parent::__construct(422, 'HR_INVALID_EMPLOYMENT_EFFECTIVE_DATE', "EmploymentRecord {$employmentRecordId}'s effective date {$attemptedEndsOn} is invalid ({$reason}).");
    }
}
