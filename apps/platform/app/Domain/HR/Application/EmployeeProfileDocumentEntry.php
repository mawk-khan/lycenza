<?php

namespace App\Domain\HR\Application;

/**
 * Phase 8A.9 -- one `EmployeeDocument` row projected into the Profile
 * Workspace's Restricted "Documents" section. `classificationTier` on
 * every entry here is always `restricted` -- `highly_sensitive`
 * documents are excluded at the DATABASE QUERY level by
 * `EmployeeProfileWorkspaceService` (never fetched, let alone
 * filtered out afterward), per docs/modules/HR.md's explicit
 * pre-8A.10 acceptance gate: general Profile Workspace reads must
 * never make Highly Sensitive documents broadly available.
 *
 * Deliberately, permanently excludes -- not merely "not yet added":
 * `original_filename`, `mime_type`, `size_bytes`, `storage_disk`,
 * `storage_path`, `uploaded_by_user_id`. None of these are normal
 * HR-facing fields; the first five are operational storage/file
 * metadata, and `uploaded_by_user_id` is original-registration
 * provenance (8A.7's own provenance-integrity fix) with no approved
 * need for general-workspace display. No file content, no upload, no
 * download, no signed/public URL exists anywhere in this class or its
 * producing service.
 *
 * Both `active` and `archived` documents may appear (history is not
 * silently hidden here, unlike Directory) -- `status` makes the
 * distinction explicit.
 */
final class EmployeeProfileDocumentEntry
{
    public function __construct(
        public readonly string $id,
        public readonly string $category,
        public readonly string $classificationTier,
        public readonly ?string $issuedOn,
        public readonly ?string $expiresOn,
        public readonly string $status,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category,
            'classification_tier' => $this->classificationTier,
            'issued_on' => $this->issuedOn,
            'expires_on' => $this->expiresOn,
            'status' => $this->status,
        ];
    }
}
