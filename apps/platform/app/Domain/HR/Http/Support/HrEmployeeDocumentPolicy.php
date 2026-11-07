<?php

namespace App\Domain\HR\Http\Support;

use App\Domain\Documents\Application\EmployeeDocumentPolicy;
use App\Domain\Documents\Application\Exceptions\DocumentOwnerNotFoundException;
use App\Domain\Documents\Infrastructure\Document;
use App\Domain\HR\Application\EmployeeDocumentService;
use App\Domain\HR\Application\HrSelfAdministrationGuard;
use App\Domain\HR\Infrastructure\Employee;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\SchoolNotOperationalException;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * HR.R1-C10 (HRC-3): HR's answer to the generic Documents API, so that route is no
 * side door round the register. A Highly Sensitive write takes the same step-up
 * (ADR 0071 §26.7), nobody writes on their own record (S-3), and a Document the
 * register holds is archived by the register. The Employee is found and the
 * self-refusal asked before the step-up, so a forged id or one's own record never
 * spends a code. HR.R1-C13 (rule 86): this route writes HR's files, so the School is
 * asked here too: once before the bytes are stored, and again in the writing transaction,
 * which is what holds it.
 */
class HrEmployeeDocumentPolicy implements EmployeeDocumentPolicy
{
    public function __construct(
        private readonly HighlySensitiveDocumentStepUp $stepUp,
        private readonly HrSelfAdministrationGuard $selfAdministration,
        private readonly TenantContext $context,
        private readonly SchoolOperationalGuard $operational,
    ) {}

    public function beforeUpload(Request $request, School $school, string $employeeId, string $classificationTier): void
    {
        $this->refuseAnUnavailableSchool($school);
        if ($classificationTier !== 'highly_sensitive') {
            return;
        }

        $this->context->withSchool($school, function () use ($school, $employeeId, $request): void {
            if (! Employee::query()->where('school_id', $school->id)->whereKey($employeeId)->exists()) {
                throw new DocumentOwnerNotFoundException('employee', $employeeId);
            }
            $this->selfAdministration->refuseOwnEmployee($request->user(), $employeeId, 'profile.document');
        });

        $this->stepUp->requireFor($request, $request->user(), $school, $classificationTier, null);
    }

    public function beforeArchive(Request $request, School $school, Document $document): void
    {
        $this->refuseAnUnavailableSchool($school);
        $this->context->withSchool($school, fn () => $this->selfAdministration->refuseOwnEmployee($request->user(), (string) $document->employee_id, 'profile.document'));
        $this->stepUp->requireFor($request, $request->user(), $school, null, $document->classification_tier);
    }

    public function refuseOwnRecord(User $actor, string $employeeId): void
    {
        $this->operational->requireOperational($this->context->requireSchool()->id);
        $this->selfAdministration->refuseOwnEmployee($actor, $employeeId, 'profile.document');
    }

    public function archiveIfRegistered(School $school, Document $document, User $actor): bool
    {
        // Resolved here, not injected: the register depends on DocumentService, which depends on this policy.
        return app(EmployeeDocumentService::class)->archiveRegistered($school, $document, $actor);
    }

    /** No lock to hold yet, and no effect to order: this only spares a refused School a storage write. */
    private function refuseAnUnavailableSchool(School $school): void
    {
        if (! $this->operational->isOperational($school->id)) {
            throw new SchoolNotOperationalException;
        }
    }
}
