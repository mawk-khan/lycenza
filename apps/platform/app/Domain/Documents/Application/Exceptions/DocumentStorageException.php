<?php

namespace App\Domain\Documents\Application\Exceptions;

use RuntimeException;

/**
 * Phase 0E.2 -- thrown when the underlying object-storage write itself
 * fails (disk/object-store unavailable). DocumentService::create()
 * never creates a `documents` row in this case -- see its docblock for
 * the exact ordering that keeps this true.
 */
class DocumentStorageException extends RuntimeException
{
    public readonly string $failureCode;

    public function __construct()
    {
        $this->failureCode = 'document_storage_unavailable';

        parent::__construct('The document could not be stored. Please try again.');
    }
}
