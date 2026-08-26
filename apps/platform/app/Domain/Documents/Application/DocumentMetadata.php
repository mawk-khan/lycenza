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

    /**
     * Phase 0E.5 -- the exact, sole JSON shape every Documents HTTP
     * transport endpoint returns for a Document entry
     * (docs/modules/DOCUMENTS.md "Document metadata JSON shape").
     * Snake_case, matching the established
     * App\Domain\HR\Application\EmployeeProfileDocumentEntry precedent
     * for this exact kind of Document-metadata-shaped payload.
     *
     * @return array{
     *     document_id: string,
     *     owner_type: string,
     *     owner_id: string,
     *     classification_tier: string,
     *     status: string,
     *     original_filename: string,
     *     mime_type: string,
     *     size_bytes: int,
     *     uploaded_at: string,
     * }
     */
    public function toArray(): array
    {
        return [
            'document_id' => $this->documentId,
            'owner_type' => $this->ownerType,
            'owner_id' => $this->ownerId,
            'classification_tier' => $this->classificationTier,
            'status' => $this->status,
            'original_filename' => $this->originalFilename,
            'mime_type' => $this->mimeType,
            'size_bytes' => $this->sizeBytes,
            'uploaded_at' => $this->uploadedAt,
        ];
    }
}
