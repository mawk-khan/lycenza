<?php

namespace App\Domain\Documents\Application\Exceptions;

use RuntimeException;

/**
 * Phase 0E.3 -- thrown when a caller-supplied Document UUID does not
 * resolve to a real row WITHIN the trusted School (a School-scoped
 * query, not a global lookup followed by a comparison). Deliberately
 * identical whether the id genuinely does not exist or exists in a
 * different School -- knowing a Document UUID must never be enough to
 * learn "this Document exists, just not here."
 */
class DocumentNotFoundException extends RuntimeException
{
    public function __construct(public readonly string $documentId)
    {
        parent::__construct("No Document with id {$documentId} was found in this School.");
    }
}
