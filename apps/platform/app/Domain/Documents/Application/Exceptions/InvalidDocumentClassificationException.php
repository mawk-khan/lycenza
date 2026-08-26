<?php

namespace App\Domain\Documents\Application\Exceptions;

/**
 * Phase 0E.2 -- thrown for a missing or invalid `classification_tier`.
 * A safe application-layer validation failure, never a raw
 * `documents_classification_tier_check` QueryException reaching the
 * caller -- the database CHECK constraint (0E.1) remains the final
 * defense in depth, this is the first line.
 *
 * Phase 0E.5: maps to HTTP 422.
 */
class InvalidDocumentClassificationException extends DocumentException
{
    public function __construct(public readonly string $given)
    {
        parent::__construct(422, 'INVALID_DOCUMENT_CLASSIFICATION', "\"{$given}\" is not a valid Document classification tier.");
    }
}
