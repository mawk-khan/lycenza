<?php

namespace App\Domain\Documents\Application\Exceptions;

use RuntimeException;

/**
 * Phase 0E.2 -- thrown for an owner type the `documents` table's
 * exclusive-arc schema (0E.1) structurally supports but the write
 * service does not yet activate (Student, Guardian at this
 * checkpoint). Neither owner domain has an existing capability whose
 * documented scope clearly covers document/attachment management
 * (`students.manage`/`guardians.manage` are broad identity-management
 * capabilities that do not name documents; see
 * docs/modules/DOCUMENTS.md "Active write owner types"), so this
 * checkpoint deliberately defers activating writes for them rather
 * than inventing an authorization boundary. This is a scope decision,
 * never reached after any storage or database side effect.
 */
class DocumentOwnerTypeNotSupportedException extends RuntimeException
{
    public function __construct(public readonly string $ownerType)
    {
        parent::__construct("Document writes for owner type \"{$ownerType}\" are not yet activated.");
    }
}
