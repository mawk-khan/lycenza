<?php

namespace App\Domain\Library\Application\Exceptions;

/**
 * UAT-C1 (UAT-LIB-01): the database already requires
 * `due_at >= checked_out_at` (`library_loans_due_after_checkout_check`).
 * LibraryLoanService checks the same rule first, against the checkout
 * timestamp it is about to write, so a due time in the past is a plain
 * refusal and never reaches the constraint as a 500.
 */
class DueDateBeforeCheckoutException extends LibraryException
{
    public function __construct()
    {
        parent::__construct(422, 'LIBRARY_DUE_BEFORE_CHECKOUT', 'The due date cannot be earlier than the checkout. Choose today or a later date.');
    }
}
