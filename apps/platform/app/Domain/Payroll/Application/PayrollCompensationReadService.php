<?php

namespace App\Domain\Payroll\Application;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\Exceptions\CompensationAssignmentNotFoundException;
use App\Domain\Payroll\Infrastructure\CompensationAssignmentValue;
use App\Domain\Payroll\Infrastructure\EmployeeCompensationAssignment;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;

/**
 * Phase 9.7 -- the two authorized read paths for Employee compensation
 * assignments, deliberately split by sensitivity tier exactly like
 * `App\Domain\HR\Application\EmployeeDocumentService`'s classification-
 * aware authorization: a NON-sensitive identity/history path
 * (`payroll.compensation.view`) and a SEPARATE Highly Sensitive amounts
 * path (`payroll.compensation.sensitive.view`).
 *
 * `listAssignments()` deliberately never touches
 * `compensation_assignment_values` at all -- not merely omitted from
 * the returned DTO (`CompensationAssignmentSummary`, ADR 0032
 * "Sensitive values": "deliberately narrow: identity and effective
 * dates only"), but structurally absent from the query itself (no
 * eager-load of the `values` relation), so a `payroll.compensation.view`-
 * only actor can never trigger even a read against Highly Sensitive
 * rows.
 *
 * `getAssignmentValues()` is the ONLY path to
 * `compensation_assignment_values.amount` outside of `assign()`'s own
 * write-time return -- resolved via a School-scoped "no oracle" lookup
 * and audited exactly once per call, with metadata carrying only the
 * assignment id and a value count, never an amount.
 */
class PayrollCompensationReadService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @return list<CompensationAssignmentSummary>
     */
    public function listAssignments(School $school, EmploymentRecord $employmentRecord, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, 'payroll.compensation.view', $school);

        return $this->context->withSchool($school, function () use ($school, $employmentRecord) {
            return EmployeeCompensationAssignment::query()
                ->where('school_id', $school->id)
                ->where('employment_record_id', $employmentRecord->id)
                ->orderByDesc('effective_from')
                ->get()
                ->map(fn (EmployeeCompensationAssignment $assignment) => CompensationAssignmentSummary::fromModel($assignment))
                ->all();
        });
    }

    /**
     * @return list<CompensationAssignmentValueDetail>
     */
    public function getAssignmentValues(School $school, string $assignmentId, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, 'payroll.compensation.sensitive.view', $school);

        return $this->context->withSchool($school, function () use ($school, $assignmentId, $actor) {
            $assignment = EmployeeCompensationAssignment::query()->where('school_id', $school->id)->find($assignmentId);

            if ($assignment === null) {
                throw new CompensationAssignmentNotFoundException($assignmentId);
            }

            $values = CompensationAssignmentValue::query()->where('assignment_id', $assignment->id)->get();

            $this->audit->school($school, 'payroll.compensation.values_viewed', actor: $actor, subject: $assignment, metadata: [
                'assignmentId' => $assignment->id,
                'valueCount' => $values->count(),
            ]);

            return $values->map(fn (CompensationAssignmentValue $value) => CompensationAssignmentValueDetail::fromModel($value))->all();
        });
    }
}
