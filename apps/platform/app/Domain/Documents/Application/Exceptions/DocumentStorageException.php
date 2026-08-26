<?php

namespace App\Domain\Documents\Application\Exceptions;

/**
 * Phase 0E.2 -- thrown when the underlying object-storage write itself
 * fails (disk/object-store unavailable). DocumentService::create()
 * never creates a `documents` row in this case -- see its docblock for
 * the exact ordering that keeps this true.
 *
 * Phase 0E.5: maps to HTTP 503 -- a safe, generic, retryable-operator
 * signal, never the raw MinIO/S3 exception (docs/modules/DOCUMENTS.md
 * "No raw storage exceptions").
 */
class DocumentStorageException extends DocumentException
{
    public function __construct()
    {
        parent::__construct(503, 'DOCUMENT_STORAGE_UNAVAILABLE', 'The document could not be stored. Please try again.');
    }
}
