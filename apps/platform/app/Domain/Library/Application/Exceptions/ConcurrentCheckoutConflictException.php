<?php

namespace App\Domain\Library\Application\Exceptions;

/**
 * Surfaced when the database's own partial unique index
 * (`library_loans_one_active_per_copy`) rejects a genuinely concurrent
 * checkout attempt that lost the race -- mirrors
 * App\Domain\AcademicStructure\Application\Exceptions\ConcurrentActivationConflictException
 * exactly. The loser's transaction is told "someone else's checkout
 * committed first," never a silent double-active-loan state. See
 * tests/Feature/Library/LibraryLoanCheckoutConcurrencyTest.php for the
 * real two-process proof this exists to make possible.
 */
class ConcurrentCheckoutConflictException extends LibraryException
{
    public function __construct()
    {
        parent::__construct(
            409,
            'LIBRARY_CHECKOUT_CONFLICT',
            'This Library copy was checked out by someone else concurrently. Refresh and try again.',
        );
    }
}
