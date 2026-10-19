<?php

namespace App\Domain\Library\Application\Exceptions;

/**
 * ORG.7D-5 (ADR 0076 §41): the ONE answer a Campus-scoped librarian gets for
 * a checkout that cannot happen -- an unknown, inactive, unplaced or
 * other-Campus Student, a Copy that moved out of scope, on loan or inactive,
 * or a lost race. It never names another Campus, Copy location, borrower or
 * loan (CLAUDE.md rule 101: refusals look the same in and out of scope).
 */
class LibraryCheckoutUnavailableException extends LibraryException
{
    public function __construct()
    {
        parent::__construct(422, 'LIBRARY_CHECKOUT_UNAVAILABLE', 'This item cannot be checked out to this Student here right now.');
    }
}
