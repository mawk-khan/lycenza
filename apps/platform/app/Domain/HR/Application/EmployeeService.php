<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Application\Exceptions\DuplicateWorkEmailException;
use App\Domain\HR\Application\Exceptions\EmployeeAlreadyLinkedException;
use App\Domain\HR\Application\Exceptions\EmployeeNotActiveException;
use App\Domain\HR\Application\Exceptions\EmployeeNotLinkedException;
use App\Domain\HR\Application\Exceptions\EmployeeUserLinkNotEditableException;
use App\Domain\HR\Application\Exceptions\UnrelatedUserLinkageException;
use App\Domain\HR\Application\Exceptions\UserAlreadyLinkedException;
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
use Illuminate\Database\UniqueConstraintViolationException;
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
 *
 * TCH.1 (ADR 0063 section 6, D-09): the Employee<->User link is an
 * authorization input (ActingEmployeeResolver), so it has its own explicit,
 * audited lifecycle -- linkUser()/unlinkUser(), plus create()'s optional
 * `user_id`, which goes through the SAME link primitive in the creation
 * transaction. update() no longer accepts `user_id` at all. Changing a
 * link is unlink, then link: never one silent re-pointing.
 */
class EmployeeService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly EmployeeNumberAllocator $allocator,
        private readonly EmployeeNumberFormatter $formatter,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly HrSelfAdministrationGuard $selfAdministration,
    ) {}

    /**
     * @param  array{full_name: string, user_id?: string|null, work_email?: string|null, work_phone?: string|null}  $attributes
     */
    public function create(School $school, array $attributes, User $actor): Employee
    {
        $this->authorizeCapabilityFor($actor, 'hr.employees.manage', $school);

        $userId = $attributes['user_id'] ?? null;
        $workEmail = $attributes['work_email'] ?? null;

        return $this->translatingUserLinkConflict($userId, fn () => $this->context->withSchool($school, function () use ($school, $attributes, $userId, $workEmail, $actor) {
            return DB::transaction(function () use ($school, $attributes, $userId, $workEmail, $actor) {
                $sequenceValue = $this->allocator->allocate($school);

                try {
                    $employee = Employee::query()->create([
                        'school_id' => $school->id,
                        'user_id' => null,
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

                if ($userId !== null) {
                    $this->link($school, $employee, $userId, $actor);
                }

                event(new EmployeeCreated($school->id, $employee->id, $employee->employee_number));

                return $employee->fresh();
            });
        }));
    }

    /**
     * Core-field update only (`full_name`/`work_email`/`work_phone`) --
     * `employee_number` can never appear here even if a
     * caller includes it (the model's own `updating()` guard rejects
     * it structurally, see Employee::booted()); `record_status`
     * transitions through archive()/restore() below, never through a
     * generic field update, mirroring EmploymentService::update()'s
     * identical "simple fields here, lifecycle transitions their own
     * method" split. `user_id` is refused (TCH.1): the link changes only
     * through linkUser()/unlinkUser().
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Employee $employee, array $attributes, User $actor): Employee
    {
        $school = $employee->school;
        $this->authorizeCapabilityFor($actor, 'hr.employees.manage', $school);

        if (array_key_exists('user_id', $attributes)) {
            throw new EmployeeUserLinkNotEditableException;
        }

        unset($attributes['school_id'], $attributes['employee_number'], $attributes['record_status']);

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
                $this->selfAdministration->refuseOwnEmployee($actor, $employee->id, 'archive');

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
                $this->selfAdministration->refuseOwnEmployee($actor, $employee->id, 'restore');

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
     * TCH.1 (ADR 0063 section 6): links this Employee to a User -- the
     * Employee must be active and currently unlinked, and the User enabled
     * with an ACTIVE membership at the Employee's School. Audited as
     * `employee.user_linked`. Relinking to a different User requires
     * unlinkUser() first.
     *
     * @throws UnrelatedUserLinkageException|EmployeeAlreadyLinkedException|EmployeeNotActiveException|UserAlreadyLinkedException
     */
    public function linkUser(Employee $employee, string $userId, User $actor): Employee
    {
        $school = $employee->school;
        $this->authorizeCapabilityFor($actor, 'hr.employees.manage', $school);

        return $this->translatingUserLinkConflict($userId, fn () => $this->context->withSchool($school, function () use ($school, $employee, $userId, $actor) {
            return DB::transaction(function () use ($school, $employee, $userId, $actor) {
                $this->link($school, $employee, $userId, $actor);

                event(new EmployeeUpdated($school->id, $employee->id, ['user_id']));

                return $employee->fresh();
            });
        }));
    }

    /**
     * TCH.1 (ADR 0063 section 6): removes this Employee's User link.
     * Deletes nothing else -- the Employee, its HR history, the User and
     * the membership are untouched. Allowed for an archived Employee
     * (removing an identity link is always the safe direction). Audited as
     * `employee.user_unlinked`. The Employee row is locked FOR UPDATE, so
     * an ActingEmployeeResolver::hold() already holding it finishes first,
     * and one started after this commits sees no link.
     *
     * @throws EmployeeNotLinkedException
     */
    public function unlinkUser(Employee $employee, User $actor): Employee
    {
        $school = $employee->school;
        $this->authorizeCapabilityFor($actor, 'hr.employees.manage', $school);

        return $this->context->withSchool($school, function () use ($school, $employee, $actor) {
            return DB::transaction(function () use ($school, $employee, $actor) {
                $locked = Employee::query()->where('school_id', $school->id)->whereKey($employee->id)->lockForUpdate()->firstOrFail();

                if ($locked->user_id === null) {
                    throw new EmployeeNotLinkedException($locked->id);
                }

                // SR.4 (ADR 0071 §26.2): never unlink yourself.
                $this->selfAdministration->refuseSelfLink($actor, $locked->user_id, 'unlink');

                $previousUserId = $locked->user_id;
                $locked->update(['user_id' => null]);

                $this->audit->school($school, 'employee.user_unlinked', actor: $actor, subject: $locked, metadata: [
                    'employeeId' => $locked->id,
                    'previousUserId' => $previousUserId,
                    'newUserId' => null,
                ]);

                event(new EmployeeUpdated($school->id, $locked->id, ['user_id']));

                return $locked->fresh();
            });
        });
    }

    /**
     * The one link primitive (create() and linkUser()), run inside the
     * caller's transaction and TenantContext. Validation happens here,
     * under locks, never before the transaction: the membership and User
     * rows FOR SHARE (a concurrent suspension, which takes the membership
     * FOR UPDATE, either commits first and is seen, or waits for this
     * link to commit), then the Employee FOR UPDATE (two concurrent links
     * of one Employee serialize; the second sees it linked). Lock order
     * membership -> User -> Employee matches ActingEmployeeResolver::hold().
     * One User linked to two Employees of one School is refused by
     * `employees_school_id_user_id_unique` (see translatingUserLinkConflict()).
     */
    private function link(School $school, Employee $employee, string $userId, User $actor): void
    {
        // SR.4 (ADR 0071 §26.2): never link an Employee to yourself.
        $this->selfAdministration->refuseSelfLink($actor, $userId, 'link');

        $membership = SchoolMembership::query()
            ->where('school_id', $school->id)
            ->where('user_id', $userId)
            ->sharedLock()
            ->first();

        if ($membership === null) {
            throw new UnrelatedUserLinkageException($userId, $school->id, UnrelatedUserLinkageException::NO_MEMBERSHIP);
        }

        if ($membership->status !== SchoolMembership::STATUS_ACTIVE) {
            throw new UnrelatedUserLinkageException($userId, $school->id, UnrelatedUserLinkageException::MEMBERSHIP_NOT_ACTIVE);
        }

        $user = User::query()->whereKey($userId)->sharedLock()->first();

        if ($user === null || $user->isDisabled()) {
            throw new UnrelatedUserLinkageException($userId, $school->id, UnrelatedUserLinkageException::USER_UNAVAILABLE);
        }

        $locked = Employee::query()->where('school_id', $school->id)->whereKey($employee->id)->lockForUpdate()->firstOrFail();

        if (! $locked->isActive()) {
            throw new EmployeeNotActiveException($locked->id);
        }

        if ($locked->user_id !== null) {
            throw new EmployeeAlreadyLinkedException($locked->id);
        }

        $locked->update(['user_id' => $userId]);

        $this->audit->school($school, 'employee.user_linked', actor: $actor, subject: $locked, metadata: [
            'employeeId' => $locked->id,
            'previousUserId' => null,
            'newUserId' => $userId,
        ]);
    }

    /**
     * Runs $operation (which owns the whole transaction) and turns a
     * `employees_school_id_user_id_unique` violation -- the database's
     * answer to "this User is already linked to another Employee of this
     * School", including under concurrency -- into UserAlreadyLinkedException.
     * Caught outside the transaction, so it has already rolled back.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function translatingUserLinkConflict(?string $userId, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (UniqueConstraintViolationException $e) {
            if ($userId !== null && $this->violatesConstraint($e, UserAlreadyLinkedException::CONSTRAINT)) {
                throw new UserAlreadyLinkedException($userId);
            }

            throw $e;
        }
    }

    private function violatesConstraint(QueryException $e, string $constraintName): bool
    {
        return str_contains($e->getMessage(), $constraintName);
    }
}
