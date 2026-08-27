<?php

namespace App\Domain\Library\Application\Exceptions;

/**
 * The common (non-racing) case: the Copy already has an active Loan,
 * or its own lifecycle status is `inactive` (withdrawn/lost) -- caught
 * by an ordinary application-level check before ever attempting the
 * INSERT. See ConcurrentCheckoutConflictException for the genuine-race
 * case this does NOT cover.
 */
class CopyNotAvailableException extends LibraryException
{
    public function __construct(string $reason)
    {
        parent::__construct(422, 'LIBRARY_COPY_NOT_AVAILABLE', "This Library copy is not available for checkout: {$reason}.");
    }
}
