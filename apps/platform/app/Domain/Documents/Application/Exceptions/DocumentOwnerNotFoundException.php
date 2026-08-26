<?php

namespace App\Domain\Documents\Application\Exceptions;

/**
 * Phase 0E.2 -- thrown when a caller-supplied owner id does not
 * resolve to a real row of the declared owner type WITHIN the trusted
 * School (rule 19: a School-scoped query, not a global lookup followed
 * by a comparison). Deliberately identical whether the id genuinely
 * does not exist or exists in a different School -- the message never
 * distinguishes the two, so a caller can never use this exception to
 * confirm "this Employee exists, just not here."
 *
 * Phase 0E.5: maps to HTTP 404 -- the non-enumerating outcome the
 * Employee-owner routes (upload/list/sensitive-list) rely on for a
 * nonexistent-or-cross-School Employee id (docs/modules/DOCUMENTS.md
 * "404 vs 403").
 */
class DocumentOwnerNotFoundException extends DocumentException
{
    public function __construct(
        public readonly string $ownerType,
        public readonly string $ownerId,
    ) {
        parent::__construct(404, 'DOCUMENT_OWNER_NOT_FOUND', "No {$ownerType} with id {$ownerId} was found in this School.");
    }
}
