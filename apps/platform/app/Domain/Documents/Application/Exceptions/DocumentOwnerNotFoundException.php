<?php

namespace App\Domain\Documents\Application\Exceptions;

use RuntimeException;

/**
 * Phase 0E.2 -- thrown when a caller-supplied owner id does not
 * resolve to a real row of the declared owner type WITHIN the trusted
 * School (rule 19: a School-scoped query, not a global lookup followed
 * by a comparison). Deliberately identical whether the id genuinely
 * does not exist or exists in a different School -- the message never
 * distinguishes the two, so a caller can never use this exception to
 * confirm "this Employee exists, just not here."
 */
class DocumentOwnerNotFoundException extends RuntimeException
{
    public function __construct(
        public readonly string $ownerType,
        public readonly string $ownerId,
    ) {
        parent::__construct("No {$ownerType} with id {$ownerId} was found in this School.");
    }
}
