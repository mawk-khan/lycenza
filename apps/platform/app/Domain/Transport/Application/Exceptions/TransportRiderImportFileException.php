<?php

namespace App\Domain\Transport\Application\Exceptions;

/** ORG.9C-4: the rider import file (or its signed preview) cannot be used as a whole. */
class TransportRiderImportFileException extends TransportException
{
    public function __construct(string $message)
    {
        parent::__construct(422, 'TRANSPORT_RIDER_IMPORT_FILE_INVALID', $message);
    }
}
