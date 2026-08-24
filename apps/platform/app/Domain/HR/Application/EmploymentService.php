<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Application\Exceptions\EmploymentOverlapException;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The only sanctioned write path for EmploymentRecord (docs/modules/HR.md
 * "Temporal data strategy"/"Rehire strategy"), mirroring EmployeeService's
 * shape. `create()` doubles as the rehire pathway -- a second call for
 * an Employee whose first EmploymentRecord has already ended is exactly
 * what a rehire is; nothing at the schema level distinguishes "first
 * hire" from "rehire" beyond it being the Employee's 2nd+ row, so no
 * separate `rehire()` method exists.
 *
 * Phase 8A.10: every public method requires a real `User $actor` and
 * authorizes `hr.employees.assignments.manage` at the Employee's School
 * (docs/modules/HR.md 8A.10 as-built: Employment/Assignment history and
 * reporting-manager changes share one capability boundary).
 */
class EmploymentService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly AssignmentClosureCascade $closureCascade,
    ) {}

    /**
     * @param  array{employment_type: string, starts_on: string, ends_on?: string|null, status?: string, probation_ends_on?: string|null}  $attributes
     */
    public function create(Employee $employee, array $attributes, User $actor): EmploymentRecord
    {
        $school = $employee->school;
        $this->authorizeCapabilityFor($actor, 'hr.employees.assignments.manage', $school);

        $startsOn = $attributes['starts_on'];
        $endsOn = $attributes['ends_on'] ?? null;

        return $this->context->withSchool($school, function () use ($school, $employee, $attributes, $startsOn, $endsOn, $actor) {
            return DB::transaction(function () use ($school, $employee, $attributes, $startsOn, $endsOn, $actor) {
                // Lock the Employee row itself (not just existing EmploymentRecord
                // rows) so two concurrent create() calls for the SAME employee --
                // including the very first hire, where no EmploymentRecord rows
                // exist yet to lock -- serialize on this row instead of racing.
                // Mirrors EmployeeNumberAllocator/AcademicYearService's lock-then-
                // check-then-write pattern.
                Employee::query()->where('id', $employee->id)->lockForUpdate()->firstOrFail();

                $this->assertNoOverlap($employee, $startsOn, $endsOn);

                $employment = EmploymentRecord::query()->create([
                    'school_id' => $school->id,
                    'employee_id' => $employee->id,
                    'employment_type' => $attributes['employment_type'],
                    'starts_on' => $startsOn,
                    'ends_on' => $endsOn,
                    'probation_ends_on' => $attributes['probation_ends_on'] ?? null,
                    'status' => $attributes['status'] ?? 'active',
                ]);

                $this->audit->school($school, 'hr.employment.created', actor: $actor, subject: $employment, metadata: [
                    'employeeId' => $employee->id,
                    'employmentType' => $employment->employment_type,
                    'startsOn' => $startsOn,
                ]);

                return $employment;
            });
        });
    }

    /**
     * Simple-field update only (employment_type/probation_ends_on) --
     * starts_on/ends_on/status changes each have their own reasoning:
     * ends_on+status transition through end() (see below); starts_on
     * is immutable once assignments may already depend on it falling
     * within the Employment's interval, and no dedicated correction
     * workflow is required by this checkpoint.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(EmploymentRecord $employment, array $attributes, User $actor): EmploymentRecord
    {
        $school = $employment->school;
        $this->authorizeCapabilityFor($actor, 'hr.employees.assignments.manage', $school);

        unset($attributes['school_id'], $attributes['employee_id'], $attributes['starts_on'], $attributes['ends_on'], $attributes['status']);

        return $this->context->withSchool($school, function () use ($school, $employment, $attributes, $actor) {
            return DB::transaction(function () use ($school, $employment, $attributes, $actor) {
                $employment->update($attributes);

                $this->audit->school($school, 'hr.employment.updated', actor: $actor, subject: $employment, metadata: [
                    // Phase 8A.11: added so the Employee Activity Timeline
                    // can resolve this event to its Employee directly from
                    // metadata, without depending on EmploymentRecord still
                    // being reachable -- purely additive, does not change
                    // this event's existing semantics.
                    'employeeId' => $employment->employee_id,
                    'fields' => array_keys($attributes),
                ]);

                return $employment->fresh();
            });
        });
    }

    /**
     * Ends the EmploymentRecord and, in the SAME transaction, closes
     * every currently-open Assignment under it to the same $endsOn
     * date (docs/modules/HR.md leaves this checkpoint's decision
     * explicit: an ended Employment must never leave an Assignment
     * open indefinitely -- Option A from the brief, chosen over
     * rejecting the call until assignments are manually closed first,
     * since that would make ending an Employment error-prone for the
     * common case). Never touches `users`/`school_memberships` --
     * account deactivation is a distinct, explicit action outside this
     * checkpoint's scope (docs/modules/HR.md principle 2.6).
     */
    public function end(EmploymentRecord $employment, string $endsOn, User $actor, string $status = 'separated'): EmploymentRecord
    {
        $school = $employment->school;
        $this->authorizeCapabilityFor($actor, 'hr.employees.assignments.manage', $school);

        return $this->context->withSchool($school, function () use ($school, $employment, $endsOn, $status, $actor) {
            return DB::transaction(function () use ($school, $employment, $endsOn, $status, $actor) {
                $employment->update(['ends_on' => $endsOn, 'status' => $status]);

                $closedAssignmentIds = $employment->assignments()
                    ->whereNull('ends_on')
                    ->pluck('id');

                $employment->assignments()->whereNull('ends_on')->update(['ends_on' => $endsOn]);

                $this->closureCascade->clearDanglingManagerReferences($closedAssignmentIds->all());

                $this->audit->school($school, 'hr.employment.ended', actor: $actor, subject: $employment, metadata: [
                    'employeeId' => $employment->employee_id,
                    'endsOn' => $endsOn,
                    'status' => $status,
                    'closedAssignmentIds' => $closedAssignmentIds->all(),
                ]);

                return $employment->fresh();
            });
        });
    }

    /**
     * See docs/modules/HR.md "Temporal data strategy" -- overlap is
     * NOT database-constrained (no daterange/EXCLUDE extension exists
     * or is introduced here); this is the sole enforcement point,
     * called only after the Employee row lock above has been taken.
     */
    private function assertNoOverlap(Employee $employee, string $startsOn, ?string $endsOn): void
    {
        $existing = EmploymentRecord::query()->where('employee_id', $employee->id)->get();

        foreach ($existing as $record) {
            $existingStartsOn = $record->starts_on->toDateString();
            $existingEndsOn = $record->ends_on?->toDateString();

            $newStartsBeforeExistingEnds = $existingEndsOn === null || $startsOn <= $existingEndsOn;
            $existingStartsBeforeNewEnds = $endsOn === null || $existingStartsOn <= $endsOn;

            if ($newStartsBeforeExistingEnds && $existingStartsBeforeNewEnds) {
                throw new EmploymentOverlapException($employee->id, $record->id);
            }
        }
    }
}
