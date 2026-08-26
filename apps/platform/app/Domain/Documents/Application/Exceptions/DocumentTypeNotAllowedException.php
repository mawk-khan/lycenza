<?php

namespace App\Domain\Documents\Application\Exceptions;

/**
 * Phase 0E.2 -- thrown when the uploaded file's real, server-sniffed
 * MIME type is not in `config('documents.allowed_mime_types')`, or its
 * declared filename extension does not match an extension that MIME
 * type is allowed to carry.
 *
 * Phase 0E.5: maps to HTTP 422.
 */
class DocumentTypeNotAllowedException extends DocumentException
{
    public function __construct()
    {
        parent::__construct(422, 'DOCUMENT_TYPE_NOT_ALLOWED', 'This file type is not allowed.');
    }
}
