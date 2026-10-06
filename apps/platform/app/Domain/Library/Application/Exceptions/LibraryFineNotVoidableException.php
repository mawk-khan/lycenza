<?php

namespace App\Domain\Library\Application\Exceptions;

/**
 * OPF.4 (ADR 0067 D4): the void path cancels only an unpaid, unwaived fine
 * charge. A charge with payment allocations or a live concession adjustment
 * is refused by FEE, and nothing is recorded. There is no refund path.
 */
class LibraryFineNotVoidableException extends LibraryException
{
    public function __construct(string $why)
    {
        parent::__construct(409, 'LIBRARY_FINE_NOT_VOIDABLE', "This Library fine cannot be voided: {$why}.");
    }
}
