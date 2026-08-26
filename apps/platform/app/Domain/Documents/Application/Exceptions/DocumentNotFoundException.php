<?php

namespace App\Domain\Documents\Application\Exceptions;

/**
 * Phase 0E.3 -- thrown when a caller-supplied Document UUID does not
 * resolve to a real row WITHIN the trusted School (a School-scoped
 * query, not a global lookup followed by a comparison). Deliberately
 * identical whether the id genuinely does not exist or exists in a
 * different School -- knowing a Document UUID must never be enough to
 * learn "this Document exists, just not here."
 *
 * Phase 0E.5: maps to HTTP 404 -- the non-enumerating outcome the
 * direct Document routes (`GET /documents/{document}`,
 * `/documents/{document}/content`, `/documents/{document}/archive`)
 * rely on (docs/modules/DOCUMENTS.md "404 vs 403").
 */
class DocumentNotFoundException extends DocumentException
{
    public function __construct(public readonly string $documentId)
    {
        parent::__construct(404, 'DOCUMENT_NOT_FOUND', "No Document with id {$documentId} was found in this School.");
    }
}
