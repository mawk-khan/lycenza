<?php

namespace App\Domain\Documents\Application\Exceptions;

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
 *
 * Phase 0E.5: also reachable on the direct Document routes (metadata/
 * content/archive) for a Document row whose persisted `owner_type` is
 * not yet activated for reads either (only possible via a raw fixture/
 * seeded row -- the write path never produces one). Maps to HTTP 404,
 * identical to "does not exist" -- the message deliberately does NOT
 * interpolate `$ownerType` (unlike the earlier Application-layer-only
 * wording this replaces), since an HTTP caller must never learn a
 * protected Document's owner type merely from a 404 body
 * (docs/modules/DOCUMENTS.md "404 vs 403", checkpoint 0E.5 gate 66).
 * `$ownerType` remains available as a property for internal/log use.
 */
class DocumentOwnerTypeNotSupportedException extends DocumentException
{
    public function __construct(public readonly string $ownerType)
    {
        parent::__construct(404, 'DOCUMENT_OWNER_TYPE_NOT_SUPPORTED', 'No Document with that id was found in this School.');
    }
}
