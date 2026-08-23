<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Application\Exceptions\EmployeeOwnershipMismatchException;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeDocument;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantStoragePath;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only sanctioned write path for an Employee's Restricted-tier
 * document metadata (docs/modules/HR.md "Documents -- narrow scope").
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
 * (`$actor?->id`), after stripping any caller-supplied
 * `uploaded_by_user_id` out of `$attributes` first (mass-assignment
 * would otherwise let a caller forge provenance, since the column is
 * `$fillable`). `update()` strips it unconditionally and never
 * replaces it with the current updater's actor id -- once set at
 * registration, `uploaded_by_user_id` is immutable for the life of the
 * row, exactly like `storage_disk`/`storage_path`.
 */
class EmployeeDocumentService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  Must include a
     *                                            `storage_path` key holding a RELATIVE fragment (e.g.
     *                                            "employee-documents/contract.pdf") -- never a final,
     *                                            already-tenant-prefixed value. The persisted column is
     *                                            always `TenantStoragePath::for($employee->school,
     *                                            $fragment)`, never the caller's raw input. `uploaded_at`
     *                                            defaults to the moment of this call but may be overridden
     *                                            by the caller (e.g. a future backfill/import process
     *                                            recording a historical upload time).
     */
    public function register(Employee $employee, array $attributes, ?User $actor = null): EmployeeDocument
    {
        unset(
            $attributes['school_id'], $attributes['employee_id'], $attributes['status'],
            $attributes['uploaded_by_user_id'],
        );

        $fragment = $attributes['storage_path'] ?? null;
        if (! is_string($fragment) || $fragment === '') {
            throw new InvalidArgumentException('storage_path fragment is required to register an Employee document.');
        }

        return $this->context->withSchool($employee->school, function () use ($employee, $attributes, $fragment, $actor) {
            return DB::transaction(function () use ($employee, $attributes, $fragment, $actor) {
                $document = EmployeeDocument::query()->create([
                    'uploaded_at' => now(),
                    ...$attributes,
                    'employee_id' => $employee->id,
                    'school_id' => $employee->school_id,
                    'storage_path' => TenantStoragePath::for($employee->school, $fragment),
                    'uploaded_by_user_id' => $actor?->id,
                    'status' => 'active',
                ]);

                $this->audit->school($employee->school, 'hr.employee_document.created', actor: $actor, subject: $document, metadata: [
                    'employeeId' => $employee->id,
                    'documentId' => $document->id,
                    'category' => $document->category,
                    'classificationTier' => $document->classification_tier,
                ]);

                return $document;
            });
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Employee $employee, EmployeeDocument $document, array $attributes, ?User $actor = null): EmployeeDocument
    {
        $this->assertOwnership($employee, $document);
        unset(
            $attributes['school_id'], $attributes['employee_id'], $attributes['status'],
            $attributes['storage_disk'], $attributes['storage_path'], $attributes['uploaded_by_user_id'],
        );

        return $this->context->withSchool($employee->school, function () use ($employee, $document, $attributes, $actor) {
            return DB::transaction(function () use ($employee, $document, $attributes, $actor) {
                $classificationChanged = array_key_exists('classification_tier', $attributes)
                    && $attributes['classification_tier'] !== $document->classification_tier;

                $document->update($attributes);

                $this->audit->school($employee->school, 'hr.employee_document.updated', actor: $actor, subject: $document, metadata: [
                    'employeeId' => $employee->id,
                    'documentId' => $document->id,
                    'fields' => array_keys($attributes),
                    'classificationChanged' => $classificationChanged,
                ]);

                return $document->fresh();
            });
        });
    }

    public function archive(Employee $employee, EmployeeDocument $document, ?User $actor = null): EmployeeDocument
    {
        $this->assertOwnership($employee, $document);

        return $this->context->withSchool($employee->school, function () use ($employee, $document, $actor) {
            return DB::transaction(function () use ($employee, $document, $actor) {
                $document->update(['status' => 'archived']);

                $this->audit->school($employee->school, 'hr.employee_document.archived', actor: $actor, subject: $document, metadata: [
                    'employeeId' => $employee->id,
                    'documentId' => $document->id,
                ]);

                return $document->fresh();
            });
        });
    }

    private function assertOwnership(Employee $employee, EmployeeDocument $document): void
    {
        if ($document->employee_id !== $employee->id) {
            throw new EmployeeOwnershipMismatchException($document->id, $employee->id, $document->employee_id);
        }
    }
}
