<?php

namespace App\Domain\Library\Application\Exceptions;

/**
 * OPF.4 (ADR 0067 D4): a fine is voided at most once
 * (`library_fine_voids_one_per_fine`).
 */
class LibraryFineAlreadyVoidedException extends LibraryException
{
    public function __construct()
    {
        parent::__construct(409, 'LIBRARY_FINE_ALREADY_VOIDED', 'This Library fine has already been voided.');
    }
}
