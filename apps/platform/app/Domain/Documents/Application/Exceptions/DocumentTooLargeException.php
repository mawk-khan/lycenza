<?php

namespace App\Domain\Documents\Application\Exceptions;

use RuntimeException;

/**
 * Phase 0E.2 -- thrown when an uploaded file exceeds
 * `config('documents.max_file_size_mb')`.
 */
class DocumentTooLargeException extends RuntimeException
{
    public readonly string $failureCode;

    public function __construct(public readonly int $maxSizeMb)
    {
        $this->failureCode = 'document_too_large';

        parent::__construct("This file exceeds the maximum Document size of {$maxSizeMb}MB.");
    }
}
