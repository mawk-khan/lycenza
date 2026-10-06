<?php

namespace App\Domain\Library\Application\Exceptions;

/**
 * OPF.4 (ADR 0067 D3): a fine policy version must name an active fee head of
 * the School and a positive daily rate, non-negative grace days and an
 * optional positive cap, in INR with at most two decimal places.
 */
class InvalidLibraryFinePolicyException extends LibraryException
{
    public function __construct(string $message)
    {
        parent::__construct(422, 'LIBRARY_FINE_POLICY_INVALID', $message);
    }
}
