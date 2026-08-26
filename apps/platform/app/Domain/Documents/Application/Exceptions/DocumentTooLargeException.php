<?php

namespace App\Domain\Documents\Application\Exceptions;

/**
 * Phase 0E.2 -- thrown when an uploaded file exceeds
 * `config('documents.max_file_size_mb')`.
 *
 * Phase 0E.5: maps to HTTP 422.
 */
class DocumentTooLargeException extends DocumentException
{
    public function __construct(public readonly int $maxSizeMb)
    {
        parent::__construct(422, 'DOCUMENT_TOO_LARGE', "This file exceeds the maximum Document size of {$maxSizeMb}MB.");
    }
}
