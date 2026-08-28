<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Application\Exceptions\DuplicateWorkEmailException;
use App\Domain\HR\Application\Exceptions\UnrelatedUserLinkageException;
use App\Domain\HR\Events\EmployeeArchived;
use App\Domain\HR\Events\EmployeeCreated;
use App\Domain\HR\Events\EmployeeUpdated;
use App\Domain\HR\Infrastructure\Employee;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The only sanctioned write path for Employee creation (docs/modules/HR.md
 * "Employee creation service", mirroring
 * App\Domain\AcademicStructure\Application\AcademicYearService's exact
 * pattern) -- never write Employee directly from a controller/factory/
 * seeder in application code. One method, one transaction: resolve
 * School/tenant context, validate the (optional) User linkage,
 * atomically allocate the employee number, persist the Employee,
 * audit, emit the domain event.
 *
 * Phase 8A.10: requires a real `User $actor` and authorizes
 * `hr.employees.manage` at $school before doing anything else -- an
 * ordinary School member must not be able to create HR records merely
 * by having any membership.
 */
class EmployeeService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly EmployeeNumberAllocator $allocator,
        private readonly EmployeeNumberFormatter $formatter,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array{full_name: string, user_id?: string|null, work_email?: string|null, work_phone?: string|null}  $attributes
     */
    public function create(School $school, array $attributes, User $actor): Employee
    {
        $this->authorizeCapabilityFor($actor, 'hr.employees.manage', $school);

        $userId = $attributes['user_id'] ?? null;
        $workEmail = $attributes['work_email'] ?? null;

        return $this->context->withSchool($school, function () use ($school, $attributes, $userId, $workEmail, $actor) {
            $this->assertUserLinkageIsSafe($school, $userId);

            return DB::transaction(function () use ($school, $attributes, $userId, $workEmail, $actor) {
                $sequenceValue = $this->allocator->allocate($school);

                try {
                    $employee = Employee::query()->create([
                        'school_id' => $school->id,
                        'user_id' => $userId,
                        'employee_number' => $this->formatter->format($sequenceValue),
                        'full_name' => $attributes['full_name'],
                        'work_email' => $workEmail,
                        'work_phone' => $attributes['work_phone'] ?? null,
                        'record_status' => 'active',
                    ]);
                } catch (QueryException $e) {
                    if ($workEmail !== null && $this->violatesConstraint($e, 'employees_work_email_unique')) {
                        throw new DuplicateWorkEmailException($workEmail);
                    }

                    throw $e;
                }

                $this->audit->school($school, 'employee.created', actor: $actor, subject: $employee, metadata: [
                    'employeeNumber' => $employee->employee_number,
                ]);

                event(new EmployeeCreated($school->id, $employee->id, $employee->employee_number));

                return $employee;
            });
        });
    }

    /**
     * Core-field update only (`full_name`/`work_email`/`work_phone`/
     * `user_id`) -- `employee_number` can never appear here even if a
     * caller includes it (the model's own `updating()` guard rejects
     * it structurally, see Employee::booted()); `record_status`
     * transitions through archive()/restore() below, never through a
     * generic field update, mirroring EmploymentService::update()'s
     * identical "simple fields here, lifecycle transitions their own
     * method" split.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Employee $employee, array $attributes, User $actor): Employee
    {
        $school = $employee->school;
        $this->authorizeCapabilityFor($actor, 'hr.employees.manage', $school);

        unset($attributes['school_id'], $attributes['employee_number'], $attributes['record_status']);

        if (array_key_exists('user_id', $attributes)) {
            $this->assertUserLinkageIsSafe($school, $attributes['user_id']);
        }

        return $this->context->withSchool($school, function () use ($school, $employee, $attributes, $actor) {
            return DB::transaction(function () use ($school, $employee, $attributes, $actor) {
                try {
                    $employee->update($attributes);
                } catch (QueryException $e) {
                    $workEmail = $attributes['work_email'] ?? null;
                    if ($workEmail !== null && $this->violatesConstraint($e, 'employees_work_email_unique')) {
                        throw new DuplicateWorkEmailException($workEmail);
                    }

                    throw $e;
                }

                $this->audit->school($school, 'employee.updated', actor: $actor, subject: $employee, metadata: [
                    'fields' => array_keys($attributes),
                ]);

                event(new EmployeeUpdated($school->id, $employee->id, array_keys($attributes)));

                return $employee->fresh();
            });
        });
    }

    /**
     * Transitions `Employee.record_status` `active` -> `archived`. This
     * is the Employee-record's OWN existence state (docs/modules/HR.md
     * principle 2.6) -- it never touches EmploymentRecord/
     * EmployeeAssignment rows, `users`, or `school_memberships`. An
     * already-archived Employee re-archiving is a harmless no-op (idempotent
     * by construction, no exception), matching the read side's own
     * `record_status`-filter semantics rather than inventing a new error.
     */
    public function archive(Employee $employee, User $actor): Employee
    {
        $school = $employee->school;
        $this->authorizeCapabilityFor($actor, 'hr.employees.manage', $school);

        return $this->context->withSchool($school, function () use ($school, $employee, $actor) {
            return DB::transaction(function () use ($school, $employee, $actor) {
                if ($employee->record_status === 'archived') {
                    return $employee;
                }

                $employee->update(['record_status' => 'archived']);

                $this->audit->school($school, 'employee.archived', actor: $actor, subject: $employee);

                event(new EmployeeArchived($school->id, $employee->id));

                return $employee->fresh();
            });
        });
    }

    /**
     * The inverse of archive() -- restores `record_status` to `active`.
     * Not named in the original 8A.0 plan's domain-event list, so it
     * deliberately dispatches no dedicated event of its own (only
     * `EmployeeArchived` was planned); it is still audited.
     */
    public function restore(Employee $employee, User $actor): Employee
    {
        $school = $employee->school;
        $this->authorizeCapabilityFor($actor, 'hr.employees.manage', $school);

        return $this->context->withSchool($school, function () use ($school, $employee, $actor) {
            return DB::transaction(function () use ($school, $employee, $actor) {
                if ($employee->record_status === 'active') {
                    return $employee;
                }

                $employee->update(['record_status' => 'active']);

                $this->audit->school($school, 'employee.restored', actor: $actor, subject: $employee);

                return $employee->fresh();
            });
        });
    }

    /**
     * See App\Domain\HR\Application\Exceptions\UnrelatedUserLinkageException's
     * docblock for why this check exists and why it is intentionally
     * application-level, checked only at link-time.
     */
    private function assertUserLinkageIsSafe(School $school, ?string $userId): void
    {
        if ($userId === null) {
            return;
        }

        $hasMembership = SchoolMembership::query()
            ->where('user_id', $userId)
            ->where('school_id', $school->id)
            ->exists();

        if (! $hasMembership) {
            throw new UnrelatedUserLinkageException($userId, $school->id);
        }
    }

    private function violatesConstraint(QueryException $e, string $constraintName): bool
    {
        return str_contains($e->getMessage(), $constraintName);
    }
}
