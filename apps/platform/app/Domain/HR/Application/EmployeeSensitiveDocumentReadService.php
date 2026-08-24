<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeDocument;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;

/**
 * Phase 8A.10 -- the ONE narrow, separately-authorized read path for
 * Highly Sensitive `EmployeeDocument` metadata (docs/modules/HR.md
 * 8A.10 as-built). `App\Domain\HR\Application\EmployeeProfileWorkspaceService`
 * NEVER includes `classification_tier = highly_sensitive` rows (8A.9's
 * own acceptance gate, unchanged) -- this is the only place that data
 * is reachable at all, and only for an actor holding
 * `hr.employees.sensitive.view`.
 *
 * Returns the exact same narrow, safe metadata shape 8A.9 already
 * established for Restricted documents (`EmployeeProfileDocumentEntry`:
 * id/category/classification_tier/issued_on/expires_on/status) --
 * never a raw Eloquent model, never `storage_path`/`storage_disk`
 * (this service does not read or expose where a file lives), never a
 * signed/public URL, and never `original_filename`/`mime_type`/
 * `size_bytes`/`uploaded_by_user_id`. No physical file is ever read.
 *
 * Tenant-safe resolution: identical to
 * `EmployeeProfileWorkspaceService::build()` -- returns `null` (not an
 * exception, not a distinguishing message) for both a genuinely
 * nonexistent Employee id and one belonging to a different School. The
 * capability check runs BEFORE the Employee is resolved, so it can
 * never become a cross-tenant/IDOR existence oracle either.
 *
 * Audits exactly ONE logical event per successful read that actually
 * returns Highly Sensitive metadata (`hr.employee_document.sensitive_viewed`)
 * -- never one row per document, and never for a read that returns
 * zero Highly Sensitive documents (nothing sensitive was actually
 * exposed). Audit metadata is limited to the Employee id and the
 * returned document ids -- never storage paths, filenames, categories
 * of any hidden data, or file content (rule 69/CLAUDE.md rule 62).
 */
class EmployeeSensitiveDocumentReadService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @return array<int, EmployeeProfileDocumentEntry>|null
     */
    public function forEmployee(School $school, string $employeeId, User $actor): ?array
    {
        $this->authorizeCapabilityFor($actor, 'hr.employees.sensitive.view', $school);

        return $this->context->withSchool($school, function () use ($school, $employeeId, $actor) {
            $employee = Employee::query()->where('school_id', $school->id)->find($employeeId);

            if ($employee === null) {
                return null;
            }

            $documents = EmployeeDocument::query()
                ->where('school_id', $school->id)
                ->where('employee_id', $employee->id)
                ->where('classification_tier', 'highly_sensitive')
                ->orderByDesc('uploaded_at')
                ->orderBy('id')
                ->get();

            $entries = $documents->map(fn (EmployeeDocument $d) => new EmployeeProfileDocumentEntry(
                id: $d->id,
                category: $d->category,
                classificationTier: $d->classification_tier,
                issuedOn: $d->issued_on?->toDateString(),
                expiresOn: $d->expires_on?->toDateString(),
                status: $d->status,
            ))->all();

            if ($entries !== []) {
                $this->audit->school($school, 'hr.employee_document.sensitive_viewed', actor: $actor, subject: $employee, metadata: [
                    'employeeId' => $employee->id,
                    'documentIds' => $documents->pluck('id')->all(),
                ]);
            }

            return $entries;
        });
    }
}
