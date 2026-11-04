<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Application\Exceptions\InvalidEmploymentEffectiveDateException;
use App\Domain\HR\Application\Exceptions\RehireRequiresEmploymentHistoryException;
use App\Domain\HR\Application\Separation\SeparationException;
use App\Domain\HR\Events\EmployeeRehired;
use App\Domain\HR\Infrastructure\Department;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\EmploymentSeparationCase;
use App\Domain\HR\Infrastructure\Position;
use App\Models\Campus;
use App\Models\User;
use App\Support\Approval\ApprovalDecisionAuthority;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
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
    public function __construct(
        private readonly TenantContext $context,
        private readonly HrEmployeeAccess $access,
        private readonly ApprovalDecisionAuthority $authority,
    ) {}

    /**
     * HR.12 (owner decision 2026-11-04, "deceased only, assured"): the ONE
     * direct employment end left. A resignation, retirement, fixed-term
     * contract end or mutual agreement goes through the approved separation
     * case (SeparationCaseService, HR-061); `terminated` is not recordable
     * until the disciplinary decision OD-HR-8; `deceased` has nobody to
     * resign, so it stays direct WITH ASSURANCE: the whole-School capability,
     * a fresh MFA proof, the actor's CURRENT authority re-read
     * (ApprovalDecisionAuthority, ApprovalWorkflowRegistry::DIRECT_ASSURED),
     * an explicit confirmation at the edge, never a future date (School-local)
     * and never while a separation case is in progress (cancel or withdraw it
     * first). The reason IS the status; there is no free text.
     *
     * Everything else -- the terminal-status set, the already-ended guard,
     * assignment, reporting-line and teaching-ownership closure, audit -- is
     * EmploymentService::end(), unchanged.
     */
    public function separate(EmploymentRecord $employment, string $endsOn, User $actor, FreshMfaProof $mfa, string $status = 'deceased'): EmploymentRecord
    {
        $school = $employment->school;
        // ORG.7E-1 (ADR 0076 §43): separation is terminal -- whole-School only.
        $this->access->requireWholeSchool($actor, 'hr.employees.assignments.manage', $school);

        if ($status !== 'deceased') {
            throw SeparationException::conflict('SEPARATION_USE_CASE', 'Record a resignation, retirement, contract end or mutual agreement as a separation case; another person approves it. Only a death is recorded directly.');
        }
        if (! $mfa->isFor($actor->id)) {
            throw new \LogicException('The fresh MFA proof belongs to another User.');
        }

        // ORG.7E-0: "future" on the School's own calendar date, never the UTC date.
        $today = SchoolTimezone::today($school);
        // Compared as calendar dates, never raw input strings (security review, ORG.7E-0).
        $endsOn = CarbonImmutable::parse($endsOn)->toDateString();
        if ($endsOn > $today) {
            throw new InvalidEmploymentEffectiveDateException($employment->id, $endsOn, 'future_dated');
        }

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $employment, $endsOn, $actor, $status): EmploymentRecord {
            $this->authority->requireCurrent($actor, 'hr.employees.assignments.manage', $school);

            $open = EmploymentSeparationCase::query()->where('school_id', $school->id)->where('employment_record_id', $employment->id)
                ->whereIn('status', EmploymentSeparationCase::OPEN_STATUSES)->exists();
            if ($open) {
                throw SeparationException::conflict('SEPARATION_CASE_IN_PROGRESS', 'This employment has a separation case in progress. Cancel or withdraw it first (reason: employment ends another way).');
            }

            return app(EmploymentService::class)->end($employment, $endsOn, $actor, $status);
        }));
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
        // ORG.7E-1 (ADR 0076 §43): rehire is whole-School only -- a former
        // Employee has no current assignment, so a Campus rehire would let any
        // Campus capture any former Employee.
        $this->access->requireWholeSchool($actor, 'hr.employees.assignments.manage', $school);

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

                event(new EmployeeRehired($school->id, $employee->id, $employment->id));

                return $employment;
            });
        });
    }
}
