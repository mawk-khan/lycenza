<?php

namespace App\Domain\HR\Application;

use App\Domain\Documents\Application\DocumentContent;
use App\Domain\Documents\Application\DocumentReadService;
use App\Domain\Documents\Application\DocumentService;
use App\Domain\Documents\Infrastructure\Document;
use App\Domain\HR\Application\Exceptions\EmployeeOwnershipMismatchException;
use App\Domain\HR\Application\Exceptions\InvalidEmployeeDocumentException;
use App\Domain\HR\Application\Exceptions\ProfileRecordArchivedException;
use App\Domain\HR\Application\Profile\EmployeeDocumentCategory;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeDocument;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * The only sanctioned write path for an Employee's Restricted-tier
 * document metadata (docs/modules/HR.md "Documents -- narrow scope").
 *
 * HR.2A (S-12, D1-26): records now reference a governed platform Document
 * (`document_id`); the bytes go only through the Documents repository
 * (createForEmployee(), archiveForEmployee(), employeeContent()), and a new
 * record never carries a storage path (database trigger). The text below
 * describes the pre-HR.2A metadata-only records, which keep their legacy
 * path metadata, untouched.
 *
 * METADATA ONLY -- deliberately, structurally. This service has no
 * `upload()`/`download()`/`delete()`-file-content method and never
 * calls `Illuminate\Support\Facades\Storage`. Phase 8A.7's own
 * dependency-discovery gate found no shared Documents module anywhere
 * in this repository (neither this branch nor current `main`) and no
 * safe upload/content-validation/malware-scanning capability exists to
 * build a real upload endpoint against -- per that gate's own rule
 * ("metadata foundation can proceed where appropriate; insecure upload
 * cannot"), `register()` only ever records metadata ABOUT a file a
 * future, appropriately-authorized process places at `storage_path` --
 * it never places one itself. `EmployeeDocumentServiceHasNoFileHandlingCapabilityTest`
 * proves this structurally via reflection, not just by convention.
 *
 * `storage_path` is never a caller-supplied final value -- `register()`
 * always derives it via `TenantStoragePath::for($employee->school,
 * $fragment)` from the caller-supplied relative fragment, so a caller
 * can never control the persisted path outside the Employee's own
 * School prefix (rule 23, extended: the same "no arbitrary storage
 * path" requirement that protects webhook/file paths elsewhere in this
 * codebase). `storage_disk`/`storage_path` are immutable after
 * `register()` -- `update()` strips them, alongside `school_id`/
 * `employee_id`, so a caller cannot silently re-point an existing
 * metadata row at different file content without a new, explicit
 * `register()` call.
 *
 * No `remove()` -- matches HR.md's own explicit lifecycle for this
 * table ("never hard-deleted"); `archive()` is the only removal-
 * adjacent operation, the same reference-entity pattern (rule 73)
 * GradeLevel/Department/Position/etc already use, not the hard-delete
 * pattern 8A.2/8A.6's simpler child records use.
 *
 * `uploaded_by_user_id` is original-registration PROVENANCE, not
 * ordinary caller-editable business data, and not "last updated by"
 * (that is a distinct concept already owned entirely by
 * `AuditRecorder`'s own `actor_user_id` on each audit event).
 * `register()` never trusts a caller-supplied value for this column --
 * it is always derived from the trusted `$actor` argument
 * (`$actor->id`), after stripping any caller-supplied
 * `uploaded_by_user_id` out of `$attributes` first (mass-assignment
 * would otherwise let a caller forge provenance, since the column is
 * `$fillable`). `update()` strips it unconditionally and never
 * replaces it with the current updater's actor id -- once set at
 * registration, `uploaded_by_user_id` is immutable for the life of the
 * row, exactly like `storage_disk`/`storage_path`.
 */
class EmployeeDocumentService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly HrSelfAdministrationGuard $selfAdministration,
        private readonly DocumentService $documents,
        private readonly DocumentReadService $documentReads,
    ) {}

    /**
     * HR.2A (S-12, D1-26): registers an HR document record WITH its bytes,
     * through the platform Documents repository (createForEmployee()): the
     * record and its Document commit together or not at all, and the record
     * never carries a storage path. The category is closed
     * (EmployeeDocumentCategory); an identity document is always Highly
     * Sensitive. The HR tier maps onto the Documents tier (`restricted` ->
     * `sensitive`, `highly_sensitive` -> `highly_sensitive`).
     *
     * @param  array{issued_on?: string|null, expires_on?: string|null}  $dates
     */
    public function register(Employee $employee, UploadedFile $file, EmployeeDocumentCategory $category, string $tier, array $dates, User $actor): EmployeeDocument
    {
        if (! in_array($tier, ['restricted', 'highly_sensitive'], true)) {
            throw new InvalidArgumentException('An HR document is restricted or highly_sensitive.');
        }
        if ($category->requiredTier() !== null && $category->requiredTier() !== $tier) {
            throw new InvalidEmployeeDocumentException('identity_tier', 'An identity document is always Highly Sensitive.');
        }
        $this->assertClassificationCapability($actor, $employee->school, targetTier: $tier, currentTier: null);

        $record = null;
        $this->context->withSchool($employee->school, function () use ($employee, $file, $category, $tier, $dates, $actor, &$record): void {
            // HR.2A (S-3): nobody files documents on their own HR record.
            DB::transaction(fn () => $this->selfAdministration->refuseOwnEmployee($actor, $employee->id, 'profile.document'));
            $this->documents->createForEmployee($employee->school, $employee->id, $file, self::documentsTier($tier), $actor, function (Document $document) use ($employee, $category, $tier, $dates, $actor, &$record): void {
                $this->selfAdministration->refuseOwnEmployee($actor, $employee->id, 'profile.document');
                $record = new EmployeeDocument;
                $record->forceFill([
                    'school_id' => $employee->school_id,
                    'employee_id' => $employee->id,
                    'document_id' => $document->id,
                    'category' => $category->value,
                    'classification_tier' => $tier,
                    'original_filename' => $document->original_filename,
                    'mime_type' => $document->mime_type,
                    'size_bytes' => $document->size_bytes,
                    'uploaded_by_user_id' => $actor->id,
                    'uploaded_at' => now(),
                    'issued_on' => $dates['issued_on'] ?? null,
                    'expires_on' => $dates['expires_on'] ?? null,
                    'status' => 'active',
                ])->save();

                $this->audit->school($employee->school, 'hr.employee_document.created', actor: $actor, subject: $record, metadata: [
                    'employeeId' => $employee->id,
                    'documentId' => $record->id,
                    'category' => $record->category,
                    'classificationTier' => $record->classification_tier,
                ]);
            });
        });

        return $record ?? throw new LogicException('The HR document record was not written.');
    }

    /**
     * Corrects a record's category, dates or classification. The file and
     * the Employee never change. A governed record's reclassification also
     * reclassifies its Document in the same transaction
     * (DocumentService::reclassifyForEmployee()), so the Document's tier --
     * which decides who may download the bytes -- always equals the record's.
     * An identity document stays Highly Sensitive.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Employee $employee, EmployeeDocument $document, array $attributes, User $actor): EmployeeDocument
    {
        $this->assertOwnership($employee, $document);
        $attributes = array_intersect_key($attributes, array_flip(['category', 'classification_tier', 'issued_on', 'expires_on']));
        if (array_key_exists('category', $attributes) && EmployeeDocumentCategory::tryFrom((string) $attributes['category']) === null) {
            throw new InvalidEmployeeDocumentException('category', 'Choose a document category from the list.');
        }
        $category = EmployeeDocumentCategory::tryFrom((string) ($attributes['category'] ?? $document->category));
        $targetTier = (string) ($attributes['classification_tier'] ?? $document->classification_tier);
        if ($category?->requiredTier() !== null && $category->requiredTier() !== $targetTier) {
            throw new InvalidEmployeeDocumentException('identity_tier', 'An identity document is always Highly Sensitive.');
        }
        $this->assertClassificationCapability(
            $actor,
            $employee->school,
            targetTier: array_key_exists('classification_tier', $attributes) ? $attributes['classification_tier'] : $document->classification_tier,
            currentTier: $document->classification_tier,
        );

        return $this->context->withSchool($employee->school, function () use ($employee, $document, $attributes, $actor) {
            return DB::transaction(function () use ($employee, $document, $attributes, $actor) {
                // HR.2A (S-3): nobody administers their own HR documents.
                $this->selfAdministration->refuseOwnEmployee($actor, $employee->id, 'profile.document');
                if ($document->status !== 'active') {
                    throw new ProfileRecordArchivedException;
                }
                $classificationChanged = array_key_exists('classification_tier', $attributes)
                    && $attributes['classification_tier'] !== $document->classification_tier;

                if ($classificationChanged && $document->document_id !== null) {
                    $this->documents->reclassifyForEmployee($employee->school, $employee->id, $document->document_id, self::documentsTier((string) $attributes['classification_tier']), $actor);
                }
                $document->forceFill($attributes)->save();

                $this->audit->school($employee->school, 'hr.employee_document.updated', actor: $actor, subject: $document, metadata: [
                    'employeeId' => $employee->id,
                    'documentId' => $document->id,
                    'fields' => array_keys($attributes),
                    'classificationChanged' => $classificationChanged,
                    // Phase 8A.11: the resulting (event-time) tier, for the timeline.
                    'classificationTier' => $document->classification_tier,
                ]);

                return $document->fresh();
            });
        });
    }

    /**
     * Archives the record (final) and, for a governed record, its Document
     * (status only; the bytes stay until retention), in one transaction.
     */
    public function archive(Employee $employee, EmployeeDocument $document, User $actor): EmployeeDocument
    {
        $this->assertOwnership($employee, $document);
        $this->assertClassificationCapability($actor, $employee->school, targetTier: $document->classification_tier, currentTier: $document->classification_tier);

        $archive = function () use ($employee, $document, $actor): void {
            $this->selfAdministration->refuseOwnEmployee($actor, $employee->id, 'profile.document');
            $locked = EmployeeDocument::query()->where('school_id', $employee->school_id)->whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'active') {
                throw new ProfileRecordArchivedException;
            }
            $locked->forceFill(['status' => 'archived'])->save();

            $this->audit->school($employee->school, 'hr.employee_document.archived', actor: $actor, subject: $locked, metadata: [
                'employeeId' => $employee->id,
                'documentId' => $locked->id,
                // Phase 8A.11: event-time classification tier.
                'classificationTier' => $locked->classification_tier,
            ]);
        };

        $this->context->withSchool($employee->school, function () use ($employee, $document, $actor, $archive): void {
            if ($document->document_id !== null) {
                $this->documents->archiveForEmployee($employee->school, $employee->id, $document->document_id, $actor, fn () => $archive());
            } else {
                DB::transaction($archive);
            }
        });

        return $this->context->withSchool($employee->school, fn () => $document->fresh() ?? $document);
    }

    /**
     * HR.R1-C10 (HRC-3): the generic Documents route, asked to archive a Document
     * this register holds, archives it here. Returns false when the register holds
     * no record of it, which leaves the Document to the generic path.
     */
    public function archiveRegistered(School $school, Document $document, User $actor): bool
    {
        [$employee, $record] = $this->context->withSchool($school, fn (): array => [
            Employee::query()->where('school_id', $school->id)->find($document->employee_id),
            EmployeeDocument::query()->where('school_id', $school->id)->where('document_id', $document->id)->first(),
        ]);
        if ($employee === null || $record === null) {
            return false;
        }

        $this->archive($employee, $record, $actor);

        return true;
    }

    /**
     * HR.2A: the bytes of a governed record, re-authorized by the Document's
     * own tier on every download (DocumentReadService::employeeContent());
     * a Highly Sensitive open is audited there. A legacy path-only record has
     * no governed bytes: NOT FOUND.
     */
    public function content(Employee $employee, EmployeeDocument $document, User $actor): DocumentContent
    {
        $this->assertOwnership($employee, $document);
        if ($document->document_id === null) {
            throw (new ModelNotFoundException)->setModel(EmployeeDocument::class, [$document->id]);
        }

        return $this->documentReads->employeeContent($employee->school, $employee->id, $document->document_id, $actor);
    }

    /** HR tier -> Documents tier. */
    private static function documentsTier(string $tier): string
    {
        return $tier === 'highly_sensitive' ? 'highly_sensitive' : 'sensitive';
    }

    /**
     * The one place classification-aware authorization lives (see this
     * class's docblock). `$currentTier` is `null` only for a brand-new
     * `register()` call (no existing row to have a "current" tier).
     * `hr.employees.sensitive.manage` is required whenever EITHER side
     * of the transition is `highly_sensitive` -- ordinary
     * `hr.employees.documents.manage` is sufficient only when both the
     * current and target tier are `restricted` (or, for register(),
     * simply not `highly_sensitive`).
     */
    private function assertClassificationCapability(User $actor, School $school, ?string $targetTier, ?string $currentTier): void
    {
        $touchesHighlySensitive = $targetTier === 'highly_sensitive' || $currentTier === 'highly_sensitive';

        $this->authorizeCapabilityFor(
            $actor,
            $touchesHighlySensitive ? 'hr.employees.sensitive.manage' : 'hr.employees.documents.manage',
            $school,
        );
    }

    private function assertOwnership(Employee $employee, EmployeeDocument $document): void
    {
        if ($document->employee_id !== $employee->id) {
            throw new EmployeeOwnershipMismatchException($document->id, $employee->id, $document->employee_id);
        }
    }
}
