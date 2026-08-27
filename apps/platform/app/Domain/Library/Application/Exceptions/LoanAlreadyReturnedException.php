<?php

namespace App\Domain\Library\Application\Exceptions;

/**
 * A check-in was attempted against a Loan that is already `returned`.
 * LibraryLoanService::checkIn() detects this via a conditional
 * `UPDATE ... WHERE status = 'active'` affecting zero rows -- the same
 * pattern AcademicYearService::activate() uses for its own conditional
 * update -- never a bare `$loan->update(...)` that would silently
 * re-set `checked_in_at` a second time.
 */
class LoanAlreadyReturnedException extends LibraryException
{
    public function __construct()
    {
        parent::__construct(422, 'LIBRARY_LOAN_ALREADY_RETURNED', 'This Library loan has already been checked in.');
    }
}
