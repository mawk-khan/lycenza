<?php

namespace App\Domain\Documents\Application;

use App\Domain\Documents\Application\Exceptions\DocumentContentUnavailableException;
use App\Domain\Documents\Application\Exceptions\DocumentNotFoundException;
use App\Domain\Documents\Application\Exceptions\DocumentOwnerTypeNotSupportedException;
use App\Domain\Documents\Infrastructure\Document;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Observability\StorageMetrics;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Phase 0E.3 -- the Documents module's first read/content-access path
 * (ADR 0012, docs/modules/DOCUMENTS.md). Deliberately separate from
 * App\Domain\Documents\Application\DocumentService (write/lifecycle
 * orchestration) -- this class only ever reads, never mutates a
 * `documents` row.
 *
 * The central invariant this class exists to enforce: knowing a
 * Document UUID is never sufficient to access its metadata or bytes.
 * Every public method resolves the Document under the trusted School
 * first (fails closed, identically, for "does not exist" and "exists
 * in a different School" -- DocumentNotFoundException never
 * distinguishes the two), derives owner type/classification from that
 * ALREADY-PERSISTED row (never from caller input -- there is no
 * DocumentOwner parameter anywhere on this class, unlike
 * DocumentService::create()), and only then authorizes the actor.
 * Storage is never touched before authorization succeeds.
 *
 * Only the Employee owner type is activated, reusing HR's existing
 * two-tier READ authorization boundary verbatim
 * (`hr.employees.documents.view` / `hr.employees.sensitive.view`) --
 * the exact read-side counterpart of DocumentService's write-side
 * reuse of `hr.employees.documents.manage` / `hr.employees.sensitive.manage`.
 * Student/Guardian remain deferred for the identical reason 0E.2
 * deferred their writes (see DocumentService's own docblock).
 *
 * Phase 0I.2 activates a SECOND owner type read path: LearningContent
 * (`lms.content.view`, ADR 0039 decision 8) -- a single capability, no
 * tier split, since a LearningContent-owned Document only ever carries
 * the one valid `internal` tier (see DocumentService's own docblock).
 *
 * Phase 0I.3 activates a THIRD: Assignment (`lms.assignments.view`),
 * identical shape.
 *
 * Archived Documents remain readable by an authorized actor --
 * evidence-based, not invented: neither
 * App\Domain\HR\Application\EmployeeProfileWorkspaceService's
 * Restricted-tier document query nor
 * App\Domain\HR\Application\EmployeeSensitiveDocumentReadService's
 * Highly Sensitive query filters by `status`; both read archived rows
 * identically to active ones. This class follows that same
 * established precedent rather than inventing a new policy.
 *
 * Audit policy (evidence-based from the same two HR services):
 * EmployeeProfileWorkspaceService's ordinary/Restricted-tier document
 * read is NOT audited at all; only EmployeeSensitiveDocumentReadService's
 * Highly Sensitive read is. This class reuses that exact boundary --
 * `public`/`internal`/`sensitive` metadata/content access is not
 * audited; `highly_sensitive` metadata/content access is, on success
 * only, using two distinct events (see below). "Content accessed" here
 * means "an authorized stream was successfully opened" -- NOT that
 * transport-level delivery to an end user completed (no HTTP exists in
 * this checkpoint to observe that).
 */
class DocumentReadService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    public function metadata(School $school, string $documentId, User $actor): DocumentMetadata
    {
        return $this->context->withSchool($school, function () use ($school, $documentId, $actor) {
            $document = $this->resolveAuthorizedDocument($school, $documentId, $actor);

            if ($document->classification_tier === 'highly_sensitive') {
                $this->audit->school($school, 'document.sensitive_metadata_viewed', actor: $actor, subject: $document, metadata: [
                    'documentId' => $document->id,
                    'ownerType' => $document->owner_type,
                ]);
            }

            return $this->toMetadata($document);
        });
    }

    public function content(School $school, string $documentId, User $actor): DocumentContent
    {
        return $this->context->withSchool($school, function () use ($school, $documentId, $actor) {
            $document = $this->resolveAuthorizedDocument($school, $documentId, $actor);

            $stream = null;

            try {
                $stream = Storage::disk($document->storage_disk)->readStream($document->storage_path);
            } catch (Throwable) {
                $stream = false;
            }

            if ($stream === false || $stream === null) {
                StorageMetrics::failed('read');

                throw new DocumentContentUnavailableException;
            }

            if ($document->classification_tier === 'highly_sensitive') {
                $this->audit->school($school, 'document.sensitive_content_accessed', actor: $actor, subject: $document, metadata: [
                    'documentId' => $document->id,
                    'ownerType' => $document->owner_type,
                ]);
            }

            return new DocumentContent(
                documentId: $document->id,
                originalFilename: $document->original_filename,
                mimeType: $document->mime_type,
                sizeBytes: $document->size_bytes,
                stream: $stream,
            );
        });
    }

    private function resolveAuthorizedDocument(School $school, string $documentId, User $actor): Document
    {
        $document = Document::query()->where('school_id', $school->id)->find($documentId);

        if ($document === null) {
            throw new DocumentNotFoundException($documentId);
        }

        $this->authorizeForDocument($school, $document, $actor);

        return $document;
    }

    private function authorizeForDocument(School $school, Document $document, User $actor): void
    {
        match ($document->owner_type) {
            'employee' => $this->authorizeCapabilityFor($actor, $this->employeeReadCapability($document->classification_tier), $school),
            'learning_content' => $this->authorizeCapabilityFor($actor, 'lms.content.view', $school),
            'assignment' => $this->authorizeCapabilityFor($actor, 'lms.assignments.view', $school),
            default => throw new DocumentOwnerTypeNotSupportedException($document->owner_type),
        };
    }

    /**
     * The read-side counterpart of
     * DocumentService::employeeDocumentCapability() -- same two-tier
     * split, `.view` instead of `.manage`.
     */
    private function employeeReadCapability(string $classificationTier): string
    {
        return $classificationTier === 'highly_sensitive'
            ? 'hr.employees.sensitive.view'
            : 'hr.employees.documents.view';
    }

    private function toMetadata(Document $document): DocumentMetadata
    {
        return new DocumentMetadata(
            documentId: $document->id,
            ownerType: $document->owner_type,
            ownerId: $document->employee_id ?? $document->student_id ?? $document->guardian_id ?? $document->learning_content_id ?? $document->assignment_id,
            classificationTier: $document->classification_tier,
            status: $document->status,
            originalFilename: $document->original_filename,
            mimeType: $document->mime_type,
            sizeBytes: $document->size_bytes,
            uploadedAt: $document->uploaded_at->toIso8601String(),
        );
    }
}
