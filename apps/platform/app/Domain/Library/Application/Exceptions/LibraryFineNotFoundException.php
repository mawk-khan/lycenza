<?php

namespace App\Domain\Library\Application\Exceptions;

class LibraryFineNotFoundException extends LibraryException
{
    public function __construct()
    {
        parent::__construct(404, 'LIBRARY_FINE_NOT_FOUND', 'The Library fine does not exist.');
    }
}
