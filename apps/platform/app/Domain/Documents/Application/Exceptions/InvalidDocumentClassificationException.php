<?php

namespace App\Domain\Documents\Application\Exceptions;

use InvalidArgumentException;

/**
 * Phase 0E.2 -- thrown for a missing or invalid `classification_tier`.
 * A safe application-layer validation failure, never a raw
 * `documents_classification_tier_check` QueryException reaching the
 * caller -- the database CHECK constraint (0E.1) remains the final
 * defense in depth, this is the first line.
 */
class InvalidDocumentClassificationException extends InvalidArgumentException
{
    public readonly string $failureCode;

    public function __construct(public readonly string $given)
    {
        $this->failureCode = 'invalid_document_classification';

        parent::__construct("\"{$given}\" is not a valid Document classification tier.");
    }
}
