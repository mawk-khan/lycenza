<?php

namespace App\Domain\Documents\Application;

/**
 * Phase 0E.3 -- the only safe shape `DocumentReadService::metadata()`
 * ever returns; a raw `App\Domain\Documents\Infrastructure\Document`
 * Eloquent model never crosses this boundary. Deliberately excludes
 * `storage_disk`/`storage_path` (never disclosed outside this module)
 * and `uploaded_by_user_id` (uploader identity is not exposed here,
 * matching the narrower precedent
 * `App\Domain\HR\Application\EmployeeProfileDocumentEntry` already
 * established for the closest analogous read path in this repository
 * -- a caller needing "who uploaded this" belongs at the audit log,
 * not this DTO). Every field here is scalar/string -- no relations, no
 * loaded owner model, no School internals, no authorization details.
 */
final class DocumentMetadata
{
    public function __construct(
        public readonly string $documentId,
        public readonly string $ownerType,
        public readonly string $ownerId,
        public readonly string $classificationTier,
        public readonly string $status,
        public readonly string $originalFilename,
        public readonly string $mimeType,
        public readonly int $sizeBytes,
        public readonly string $uploadedAt,
    ) {}
}
