<?php

namespace App\Domain\Payroll\Application;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\Exceptions\CompensationAssignmentOverlapException;
use App\Domain\Payroll\Application\Exceptions\StructureComponentNotFixedAmountException;
use App\Domain\Payroll\Application\Exceptions\StructureNotActiveException;
use App\Domain\Payroll\Infrastructure\CompensationAssignmentValue;
use App\Domain\Payroll\Infrastructure\EmployeeCompensationAssignment;
use App\Domain\Payroll\Infrastructure\SalaryStructure;
use App\Domain\Payroll\Infrastructure\SalaryStructureComponent;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 9.2 -- the sole write path for `employee_compensation_assignments`/
 * `compensation_assignment_values` (ADR 0032 "Compensation assignment").
 * Anchored to `EmploymentRecord`, never bare `Employee` -- a rehired
 * Employee's second EmploymentRecord gets independent compensation
 * history for free, simply because assignments key on
 * `employment_record_id`.
 *
 * `assign()` never mutates historical salary data: a compensation
 * change closes the currently-open assignment (UPDATE `effective_to`
 * only -- the row itself, and its `compensation_assignment_values`,
 * are otherwise untouched) and creates a brand-new assignment + value
 * rows. Mirrors `App\Domain\HR\Application\EmploymentService::create()`'s
 * lock-then-check-then-write shape: the EmploymentRecord row is locked
 * FOR UPDATE before any check, so two concurrent `assign()` calls for
 * the SAME EmploymentRecord serialize on that lock; a DIFFERENT
 * EmploymentRecord takes a different lock and is never blocked by this
 * one (ADR 0032 "Mandatory overlap guarantees" -- no School-wide
 * locking). The database triggers
 * (`trg_compensation_assignments_reject_overlap`,
 * `trg_compensation_assignments_require_active_structure`,
 * `trg_compensation_assignment_values_require_fixed_component`)
 * remain the authoritative, concurrency-safe guarantees regardless of
 * the Application-layer checks below -- including against a raw SQL
 * write that bypasses this service entirely.
 *
 * Never reads/writes HR's own tables beyond the read-only
 * `EmploymentRecord` reference every other Payroll table already
 * carries (rule 4) -- `Employee`/`EmploymentRecord`/`EmployeeAssignment`
 * remain exclusively written by HR's own Application layer.
 *
 * Capability gating (`payroll.compensation.manage`) is deliberately
 * deferred to Checkpoint 9.7 -- see `SalaryComponentService`'s
 * identical note.
 */
class CompensationService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  list<FixedComponentValueInput>  $fixedValues
     */
    public function assign(
        School $school,
        EmploymentRecord $employmentRecord,
        SalaryStructure $structure,
        Carbon $effectiveFrom,
        array $fixedValues,
        User $actor,
    ): EmployeeCompensationAssignment {
        if (! $structure->isActive()) {
            throw new StructureNotActiveException($structure->id, $structure->status);
        }

        return $this->context->withSchool($school, function () use ($school, $employmentRecord, $structure, $effectiveFrom, $fixedValues, $actor) {
            return DB::transaction(function () use ($school, $employmentRecord, $structure, $effectiveFrom, $fixedValues, $actor) {
                // Serializes concurrent assign() calls for the SAME
                // EmploymentRecord; a different EmploymentRecord takes a
                // different row lock and proceeds independently.
                EmploymentRecord::query()->where('id', $employmentRecord->id)->lockForUpdate()->firstOrFail();

                $currentOpen = EmployeeCompensationAssignment::query()
                    ->where('employment_record_id', $employmentRecord->id)
                    ->whereNull('effective_to')
                    ->first();

                if ($currentOpen !== null) {
                    if ($effectiveFrom->lte($currentOpen->effective_from)) {
                        throw new CompensationAssignmentOverlapException($employmentRecord->id, $currentOpen->id);
                    }

                    // Close, never mutate anything else about the row --
                    // its own compensation_assignment_values remain
                    // exactly as they were (immutable, ADR 0032).
                    $currentOpen->update(['effective_to' => $effectiveFrom->copy()->subDay()]);
                }

                $this->assertNoOverlap($employmentRecord->id, $effectiveFrom);

                try {
                    $assignment = EmployeeCompensationAssignment::query()->create([
                        'school_id' => $school->id,
                        'employment_record_id' => $employmentRecord->id,
                        'salary_structure_id' => $structure->id,
                        'effective_from' => $effectiveFrom,
                        'effective_to' => null,
                    ]);
                } catch (QueryException $e) {
                    if (str_contains($e->getMessage(), 'overlaps existing assignment')) {
                        throw new CompensationAssignmentOverlapException($employmentRecord->id);
                    }

                    if (str_contains($e->getMessage(), 'must reference an active revision')) {
                        throw new StructureNotActiveException($structure->id, $structure->fresh()->status);
                    }

                    throw $e;
                }

                foreach ($fixedValues as $value) {
                    $this->createValue($school, $assignment, $value);
                }

                // Entity references only -- never the amount (ADR 0032
                // "Sensitive values": Highly Sensitive, never logged,
                // never in audit metadata, never in an event payload).
                $this->audit->school($school, 'payroll.compensation.assigned', actor: $actor, subject: $assignment, metadata: [
                    'employmentRecordId' => $employmentRecord->id,
                    'salaryStructureId' => $structure->id,
                    'effectiveFrom' => $effectiveFrom->toDateString(),
                    'componentCount' => count($fixedValues),
                    'previousAssignmentId' => $currentOpen?->id,
                ]);

                return $assignment->fresh();
            });
        });
    }

    public function resolveEffective(EmploymentRecord $employmentRecord, Carbon $asOf): ?CompensationAssignmentSummary
    {
        $assignment = EmployeeCompensationAssignment::query()
            ->where('employment_record_id', $employmentRecord->id)
            ->where('effective_from', '<=', $asOf->toDateString())
            ->where(function ($query) use ($asOf) {
                $query->whereNull('effective_to')->orWhere('effective_to', '>=', $asOf->toDateString());
            })
            ->first();

        return $assignment !== null ? CompensationAssignmentSummary::fromModel($assignment) : null;
    }

    private function createValue(School $school, EmployeeCompensationAssignment $assignment, FixedComponentValueInput $value): CompensationAssignmentValue
    {
        $component = SalaryStructureComponent::query()->find($value->salaryStructureComponentId);

        if ($component === null || ! $component->isFixedAmount()) {
            throw new StructureComponentNotFixedAmountException($value->salaryStructureComponentId);
        }

        try {
            return CompensationAssignmentValue::query()->create([
                'school_id' => $school->id,
                'assignment_id' => $assignment->id,
                'salary_structure_component_id' => $value->salaryStructureComponentId,
                'amount' => $value->amount,
            ]);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'must be a fixed_amount component')) {
                throw new StructureComponentNotFixedAmountException($value->salaryStructureComponentId);
            }

            throw $e;
        }
    }

    /**
     * Application-layer overlap validation -- checked against every
     * OTHER assignment for this EmploymentRecord (the currently-open
     * one, if any, has already been closed by the caller above before
     * this runs). The database trigger re-validates the identical rule
     * at INSERT time regardless.
     */
    private function assertNoOverlap(string $employmentRecordId, Carbon $effectiveFrom): void
    {
        $conflict = EmployeeCompensationAssignment::query()
            ->where('employment_record_id', $employmentRecordId)
            ->where(function ($query) use ($effectiveFrom) {
                $query->whereNull('effective_to')->orWhere('effective_to', '>=', $effectiveFrom->toDateString());
            })
            ->first();

        if ($conflict !== null) {
            throw new CompensationAssignmentOverlapException($employmentRecordId, $conflict->id);
        }
    }
}
