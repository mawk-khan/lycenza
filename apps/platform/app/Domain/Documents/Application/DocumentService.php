<?php

namespace App\Domain\Documents\Application;

use App\Domain\Documents\Application\Exceptions\DocumentOwnerNotFoundException;
use App\Domain\Documents\Application\Exceptions\DocumentOwnerTypeNotSupportedException;
use App\Domain\Documents\Application\Exceptions\DocumentStorageException;
use App\Domain\Documents\Application\Exceptions\DocumentTooLargeException;
use App\Domain\Documents\Application\Exceptions\DocumentTypeNotAllowedException;
use App\Domain\Documents\Application\Exceptions\InvalidDocumentClassificationException;
use App\Domain\Documents\Infrastructure\Document;
use App\Domain\HR\Infrastructure\Employee;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantStoragePath;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Uid\UuidV7;
use Throwable;

/**
 * Phase 0E.2 -- the Documents module's first real write path (ADR
 * 0012, docs/modules/DOCUMENTS.md). Structurally mirrors
 * App\Domain\Communications\Application\CommunicationAttachmentService::store(),
 * the one existing production precedent for "validate, write an
 * object, then persist metadata with compensation" in this repository
 * -- adapted for the exclusive-arc multi-owner-type schema and
 * owner-domain (not participant-based) authorization.
 *
 * Upload ordering (deliberate, matches CommunicationAttachmentService's
 * own documented reasoning -- storage and DB writes are not
 * perfectly atomic):
 *
 *   1. Resolve the declared owner under the trusted School (fails
 *      closed, identically, for "does not exist" and "exists in a
 *      different School" -- DocumentOwnerNotFoundException).
 *   2. Authorize the actor for that owner type + the requested
 *      classification tier BEFORE any I/O (rule: no unauthorized
 *      orphan object).
 *   3. Cheap, no-I/O validation next (classification tier, MIME/
 *      extension allow-list, size limit) -- reject before ever
 *      touching storage.
 *   4. Write bytes to the configured disk under a server-generated
 *      key (TenantStoragePath::for(), never the raw filename).
 *   5. Create the `documents` row + audit event inside one DB
 *      transaction. If step 5 throws, the just-written object is
 *      deleted (compensating action) before the exception propagates
 *      -- a caller must never be told a Document exists when its row
 *      failed to persist. If step 4 throws, no DB write is ever
 *      attempted -- a caller must never be told a Document exists
 *      when storage failed.
 *
 * Only the Employee owner type is activated in this checkpoint --
 * see docs/modules/DOCUMENTS.md "Active write owner types" for why
 * Student/Guardian are deliberately deferred (no existing capability
 * whose documented scope covers document/attachment management for
 * either), not silently unsupported.
 */
class DocumentService
{
    use AuthorizesCapability;

    private const VALID_CLASSIFICATION_TIERS = ['public', 'internal', 'sensitive', 'highly_sensitive'];

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    public function create(School $school, CreateDocumentData $data, User $actor): Document
    {
        return $this->context->withSchool($school, function () use ($school, $data, $actor) {
            $ownerColumn = $this->resolveAndAuthorizeOwner($school, $data->owner, $data->classificationTier, $actor);

            $this->assertValidClassification($data->classificationTier);
            [$mimeType, $extension] = $this->assertAllowedType($data->file);
            $this->assertWithinSizeLimit($data->file);

            $disk = (string) config('documents.disk');
            $storageKey = (string) new UuidV7;
            $path = TenantStoragePath::for($school, "documents/{$data->owner->type}/{$data->owner->id}/{$storageKey}.{$extension}");

            $stream = null;

            try {
                $stream = fopen($data->file->getRealPath(), 'r');
                $stored = $stream !== false && Storage::disk($disk)->put($path, $stream);
            } catch (Throwable) {
                $stored = false;
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if ($stored === false) {
                throw new DocumentStorageException;
            }

            try {
                return DB::transaction(function () use ($school, $data, $actor, $ownerColumn, $mimeType, $disk, $path) {
                    $document = Document::query()->create(array_merge([
                        'school_id' => $school->id,
                        'classification_tier' => $data->classificationTier,
                        'storage_disk' => $disk,
                        'storage_path' => $path,
                        'original_filename' => $this->sanitizeDisplayName($data->file->getClientOriginalName()),
                        'mime_type' => $mimeType,
                        'size_bytes' => $data->file->getSize(),
                        'uploaded_by_user_id' => $actor->id,
                        'uploaded_at' => now(),
                        'status' => 'active',
                    ], $ownerColumn));

                    $this->audit->school($school, 'document.created', actor: $actor, subject: $document, metadata: [
                        'documentId' => $document->id,
                        'ownerType' => $data->owner->type,
                        'classificationTier' => $document->classification_tier,
                        'sizeBytes' => $document->size_bytes,
                        'mimeType' => $document->mime_type,
                    ]);

                    return $document;
                });
            } catch (Throwable $e) {
                // Compensation (rule 24/56): the metadata/audit
                // transaction failed, so the object we already wrote
                // must not silently remain as an orphan no Document row
                // references.
                Storage::disk($disk)->delete($path);

                throw $e;
            }
        });
    }

    /**
     * Archives a Document -- status only, matching `documents`' own
     * never-hard-deleted lifecycle (0E.1). The physical object is
     * never touched: archival is a metadata state change, not
     * retention/deletion (docs/modules/DOCUMENTS.md explicitly defers
     * both). Re-derives the correct owner-domain capability from the
     * Document's own owner column rather than trusting a caller-
     * supplied classification/owner claim.
     */
    public function archive(School $school, Document $document, User $actor): Document
    {
        return $this->context->withSchool($school, function () use ($school, $document, $actor) {
            if ($document->school_id !== $school->id) {
                throw new DocumentOwnerNotFoundException($document->owner_type, $document->id);
            }

            $this->authorizeForExistingOwner($school, $document, $actor);

            return DB::transaction(function () use ($school, $document, $actor) {
                $document->update(['status' => 'archived']);

                $this->audit->school($school, 'document.archived', actor: $actor, subject: $document, metadata: [
                    'documentId' => $document->id,
                    'ownerType' => $document->owner_type,
                    'classificationTier' => $document->classification_tier,
                ]);

                return $document->fresh();
            });
        });
    }

    /**
     * @return array<string, string> exactly one exclusive-arc column to set
     */
    private function resolveAndAuthorizeOwner(School $school, DocumentOwner $owner, string $classificationTier, User $actor): array
    {
        return match ($owner->type) {
            'employee' => $this->resolveAndAuthorizeEmployeeOwner($school, $owner->id, $classificationTier, $actor),
            'student', 'guardian' => throw new DocumentOwnerTypeNotSupportedException($owner->type),
            default => throw new DocumentOwnerTypeNotSupportedException($owner->type),
        };
    }

    /**
     * @return array{employee_id: string}
     */
    private function resolveAndAuthorizeEmployeeOwner(School $school, string $employeeId, string $classificationTier, User $actor): array
    {
        $employee = Employee::query()->where('school_id', $school->id)->find($employeeId);

        if ($employee === null) {
            throw new DocumentOwnerNotFoundException('employee', $employeeId);
        }

        $this->authorizeCapabilityFor($actor, $this->employeeDocumentCapability($classificationTier), $school);

        return ['employee_id' => $employee->id];
    }

    private function authorizeForExistingOwner(School $school, Document $document, User $actor): void
    {
        match ($document->owner_type) {
            'employee' => $this->authorizeCapabilityFor($actor, $this->employeeDocumentCapability($document->classification_tier), $school),
            default => throw new DocumentOwnerTypeNotSupportedException($document->owner_type),
        };
    }

    /**
     * The one place the classification-aware Employee-owner
     * authorization boundary lives -- deliberately reuses HR's own
     * already-established two-tier split (Phase 8A.10/8A.11's
     * `hr.employees.sensitive.manage` vs `hr.employees.documents.manage`,
     * see EmployeeDocumentService::assertClassificationCapability())
     * rather than inventing a new Documents-specific policy: this
     * module's four-tier vocabulary collapses to that same two-way
     * split for Employee owners (`highly_sensitive` needs the
     * sensitive capability; `public`/`internal`/`sensitive` all need
     * only the ordinary documents capability).
     */
    private function employeeDocumentCapability(string $classificationTier): string
    {
        return $classificationTier === 'highly_sensitive'
            ? 'hr.employees.sensitive.manage'
            : 'hr.employees.documents.manage';
    }

    private function assertValidClassification(string $tier): void
    {
        if (! in_array($tier, self::VALID_CLASSIFICATION_TIERS, true)) {
            throw new InvalidDocumentClassificationException($tier);
        }
    }

    /**
     * @return array{0: string, 1: string} [sniffed mime type, matched extension]
     */
    private function assertAllowedType(UploadedFile $file): array
    {
        // The REAL, server-inspected content type -- Symfony's
        // mime-type guesser against the file's actual bytes, never the
        // browser-supplied Content-Type header (available separately
        // via getClientMimeType(), never used here). Matches
        // CommunicationAttachmentService::assertAllowedType() exactly.
        $mimeType = $file->getMimeType();
        $declaredExtension = strtolower((string) pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));

        $allowed = (array) config('documents.allowed_mime_types');

        if ($mimeType === null || ! array_key_exists($mimeType, $allowed)) {
            throw new DocumentTypeNotAllowedException;
        }

        if (! in_array($declaredExtension, $allowed[$mimeType], true)) {
            throw new DocumentTypeNotAllowedException;
        }

        return [$mimeType, $declaredExtension];
    }

    private function assertWithinSizeLimit(UploadedFile $file): void
    {
        $maxMb = (int) config('documents.max_file_size_mb');

        if ($file->getSize() > $maxMb * 1024 * 1024) {
            throw new DocumentTooLargeException($maxMb);
        }
    }

    /**
     * Preserves the original filename only as normalized DISPLAY
     * metadata -- never used to build the storage path. Strips
     * directory components (basename only, defeating path traversal
     * via a crafted filename), control characters, and caps length
     * well under the database column limit. Matches
     * CommunicationAttachmentService::sanitizeDisplayName() exactly.
     */
    private function sanitizeDisplayName(string $rawName): string
    {
        $name = basename($rawName);
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '';
        $name = trim($name);

        if ($name === '') {
            $name = 'document';
        }

        return mb_substr($name, 0, 180);
    }
}
