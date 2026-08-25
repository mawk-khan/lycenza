<?php

namespace App\Domain\Documents\Application\Exceptions;

use RuntimeException;

/**
 * Phase 0E.2 -- thrown when the uploaded file's real, server-sniffed
 * MIME type is not in `config('documents.allowed_mime_types')`, or its
 * declared filename extension does not match an extension that MIME
 * type is allowed to carry.
 */
class DocumentTypeNotAllowedException extends RuntimeException
{
    public readonly string $failureCode;

    public function __construct()
    {
        $this->failureCode = 'document_type_not_allowed';

        parent::__construct('This file type is not allowed.');
    }
}
