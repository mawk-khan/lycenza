<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Application\Exceptions\InvalidEmploymentEffectiveDateException;
use App\Domain\HR\Application\Exceptions\RehireRequiresEmploymentHistoryException;
use App\Domain\HR\Infrastructure\Department;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\Position;
use App\Models\Campus;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Phase 8A.13 -- the explicit Employee lifecycle COMMAND layer:
 * `separate()` and `rehire()`. This is an ORCHESTRATOR only, exactly
 * like `App\Domain\HR\Application\EmployeeImportService` (8A.12) --
 * every write goes through the existing authoritative services
 * (`EmploymentService`, `EmployeeAssignmentService`), never a direct
 * `EmploymentRecord::update()`/`EmployeeAssignment::insert()`/etc. This
 * class introduces NO new authorization capability, NO new audit event
 * family, and NO new table -- Employment overlap, Assignment closure,
 * reporting-manager cleanup, and audit writing all remain exactly where
 * 8A.4/8A.5 already put them.
 *
 * Employee identity (`employees.id`/`employee_number`) is never
 * touched by either operation -- `separate()` only ever calls
 * `EmploymentService::end()` on an existing EmploymentRecord, and
 * `rehire()` only ever calls `EmploymentService::create()` for a NEW
 * EmploymentRecord under the SAME, already-existing Employee. Neither
 * operation reads or writes `Employee.record_status`,
 * `employees.user_id`, or any `users`/`school_memberships` row --
 * Employee-record lifecycle and account/membership lifecycle remain
 * fully independent of Employment lifecycle (docs/modules/HR.md
 * principle 2.6, "Employee lifecycle -- state responsibility matrix").
 */
class EmployeeLifecycleService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly TenantContext $context,
    ) {}

    /**
     * Ends the given EmploymentRecord with a real terminal status.
     * Thin, additive wrapper over `EmploymentService::end()` (which
     * already validates the terminal-status set, rejects a repeated
     * end under lock, closes open Assignments, clears dangling manager
     * pointers, and audits `hr.employment.ended`) -- the ONE thing this
     * method adds on top is a lifecycle-level POLICY restriction not
     * appropriate for the lower-level primitive itself: rejecting a
     * future-dated effective date (checkpoint 8A.13 section 15 -- HR.md
     * does not define scheduled-separation semantics, so the
     * conservative default is chosen here, at the COMMAND layer, not
     * inside `EmploymentService::end()`, which stays available as a
     * more permissive primitive for any future backdated-correction
     * workflow).
     */
    public function separate(EmploymentRecord $employment, string $endsOn, User $actor, string $status = 'separated'): EmploymentRecord
    {
        $school = $employment->school;
        $this->authorizeCapabilityFor($actor, 'hr.employees.assignments.manage', $school);

        $today = Carbon::today()->toDateString();
        if ($endsOn > $today) {
            throw new InvalidEmploymentEffectiveDateException($employment->id, $endsOn, 'future_dated');
        }

        return app(EmploymentService::class)->end($employment, $endsOn, $actor, $status);
    }

    /**
     * Creates a NEW EmploymentRecord for an EXISTING Employee who has
     * been employed before -- never `EmployeeService::create()`, which
     * would mint a second Employee identity. Requires at least one
     * prior EmploymentRecord to exist for this Employee
     * (`RehireRequiresEmploymentHistoryException` otherwise, checkpoint
     * 8A.13 section 26) -- an Employee with NO employment history at
     * all is a first hire, which continues to go through
     * `EmploymentService::create()` directly, unchanged from 8A.4; this
     * method exists specifically for the "employed before, employed
     * again" case.
     *
     * Employment overlap is enforced entirely by the reused
     * `EmploymentService::create()` (its existing Employee-row lock +
     * overlap scan, proven concurrency-safe since 8A.4) -- nothing here
     * duplicates that check, which is also what makes "rehire while a
     * current Employment is still open" impossible by construction
     * (section 26): an open-ended or not-yet-ended prior Employment
     * always overlaps any new one.
     *
     * The optional initial Assignment (`$position` non-null) is created
     * inside the SAME transaction via the existing
     * `EmployeeAssignmentService::create()`/`setPrimary()` -- a brand
     * NEW Assignment row, never a reactivation of any prior historical
     * Assignment (section 30) and never inheriting a prior reporting
     * manager (section 31). If Assignment creation fails, the whole
     * rehire -- including the just-created EmploymentRecord -- rolls
     * back (section 34); omitting `$position` entirely is a valid,
     * deliberate "Employment-only" rehire (mirrors
     * `EmployeeImportService`'s identical optional-Assignment shape).
     *
     * @param  array{employment_type: string, starts_on: string, ends_on?: string|null, probation_ends_on?: string|null}  $employmentAttributes
     * @param  array{starts_on: string, ends_on?: string|null}|null  $assignmentAttributes
     */
    public function rehire(
        Employee $employee,
        array $employmentAttributes,
        User $actor,
        ?array $assignmentAttributes = null,
        ?Position $position = null,
        ?Department $department = null,
        ?Campus $campus = null,
    ): EmploymentRecord {
        $school = $employee->school;
        $this->authorizeCapabilityFor($actor, 'hr.employees.assignments.manage', $school);

        if ($assignmentAttributes !== null && $position === null) {
            throw new InvalidArgumentException('An initial Assignment requires a Position.');
        }

        return $this->context->withSchool($school, function () use ($school, $employee, $employmentAttributes, $assignmentAttributes, $position, $department, $campus, $actor) {
            return DB::transaction(function () use ($school, $employee, $employmentAttributes, $assignmentAttributes, $position, $department, $campus, $actor) {
                $hasPriorEmployment = EmploymentRecord::query()
                    ->where('school_id', $school->id)
                    ->where('employee_id', $employee->id)
                    ->exists();

                if (! $hasPriorEmployment) {
                    throw new RehireRequiresEmploymentHistoryException($employee->id);
                }

                $employment = app(EmploymentService::class)->create($employee, $employmentAttributes, $actor);

                if ($assignmentAttributes !== null) {
                    $assignment = app(EmployeeAssignmentService::class)->create(
                        $employment,
                        $assignmentAttributes,
                        $position,
                        $actor,
                        campus: $campus,
                        department: $department,
                    );

                    app(EmployeeAssignmentService::class)->setPrimary($assignment, $actor);
                }

                return $employment;
            });
        });
    }
}
