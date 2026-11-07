<?php

namespace App\Domain\HR\Application;

use App\Domain\Campuses\Application\ActiveCampus;
use App\Domain\HR\Application\Exceptions\AssignmentCampusMismatchException;
use App\Domain\HR\Application\Exceptions\AssignmentDepartmentCampusScopeMismatchException;
use App\Domain\HR\Application\Exceptions\AssignmentDepartmentMismatchException;
use App\Domain\HR\Application\Exceptions\AssignmentInactiveCampusException;
use App\Domain\HR\Application\Exceptions\AssignmentInactiveDepartmentException;
use App\Domain\HR\Application\Exceptions\AssignmentInactivePositionException;
use App\Domain\HR\Application\Exceptions\AssignmentOutsideEmploymentRangeException;
use App\Domain\HR\Application\Exceptions\AssignmentPositionMismatchException;
use App\Domain\HR\Application\Exceptions\ConcurrentPrimaryAssignmentConflictException;
use App\Domain\HR\Application\Exceptions\PlacementChangeConflictException;
use App\Domain\HR\Application\Workforce\PlacementChangeReason;
use App\Domain\HR\Events\AssignmentEnded;
use App\Domain\HR\Events\AssignmentStarted;
use App\Domain\HR\Events\PrimaryAssignmentChanged;
use App\Domain\HR\Infrastructure\Department;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmployeePlacementChange;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\Position;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The only sanctioned write path for EmployeeAssignment
 * (docs/modules/HR.md entity model), mirroring EmploymentService's
 * shape. `$position`/`$campus`/`$department` are accepted as real,
 * already-resolved model instances -- never a raw caller-supplied id
 * -- matching the exact pattern DepartmentService already established
 * for Campus/parent-Department ownership. There is no `employee_id`
 * parameter anywhere in this class because `employee_assignments` has
 * no such column (see the owning migration's docblock) -- the
 * "Assignment attached to the wrong Employee" IDOR shape this
 * checkpoint's brief warns about is structurally impossible here, not
 * merely tested against.
 *
 * Phase 8A.10: every public method requires a real `User $actor` and
 * authorizes `hr.employees.assignments.manage` at the owning School.
 *
 * ORG.7E-1 (ADR 0076 §43): Campus-aware. A Campus-scoped actor creates a new
 * assignment only for an Employee whose EVERY current Campus is in scope,
 * under the CURRENT employment, on an active in-scope Campus (never a
 * NULL-Campus one: no capture of an unassigned or other-Campus Employee), and
 * ends, promotes or re-manages only a CURRENT assignment on an in-scope
 * Campus -- each decided under EmployeePlacementLock and the row locks
 * (HrEmployeeCampusGuard). Whole-School behaviour is unchanged.
 */
class EmployeeAssignmentService
{
    use AuthorizesCampusScope;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly HrSelfAdministrationGuard $selfAdministration,
        private readonly ActiveCampus $activeCampus,
        private readonly HrEmployeeAccess $access,
        private readonly EmployeePlacementLock $placement,
    ) {}

    /**
     * `is_primary` is never settable through `create()` -- a new
     * Assignment is always created non-primary; `setPrimary()` is the
     * sole promotion path, mirroring
     * App\Domain\HR\Application\EmployeeEmergencyContactService's
     * identical add()/setPrimary() split from 8A.2.
     *
     * @param  array{starts_on: string, ends_on?: string|null}  $attributes
     */
    public function create(
        EmploymentRecord $employment,
        array $attributes,
        Position $position,
        User $actor,
        ?Campus $campus = null,
        ?Department $department = null,
    ): EmployeeAssignment {
        $school = $employment->school;
        $guard = $this->access->writeGuard($school, $this->authorizeCampusScopeFor($actor, 'hr.employees.assignments.manage', $school));

        return $this->context->withSchool($school, function () use ($school, $employment, $attributes, $position, $campus, $department, $actor, $guard) {
            return DB::transaction(function () use ($school, $employment, $attributes, $position, $campus, $department, $actor, $guard) {
                if ($guard !== null) {
                    // ORG.7E-1: the decision needs the whole current placement stable.
                    $this->placement->forEmployeeWrite($school, $employment->employee_id);
                } else {
                    $this->placement->forPlacementChange($school, $employment->employee_id);
                }
                $current = $this->holdEmployment($school, $employment->id);
                $guard?->beforeAssignmentCreate($current, $campus?->id, $department, $attributes['starts_on']);

                return $this->createRecord($current, $attributes, $position, $actor, $campus, $department);
            });
        });
    }

    /**
     * The one EmployeeAssignment INSERT (create() and
     * EmployeeOnboardingService): reference validation, the
     * self-administration guard, the active-Campus check, audit and the
     * AssignmentStarted event. No authorization and no placement lock of its
     * own; run inside the caller's transaction and the School's TenantContext.
     *
     * @param  array{starts_on: string, ends_on?: string|null}  $attributes
     */
    public function createRecord(
        EmploymentRecord $employment,
        array $attributes,
        Position $position,
        User $actor,
        ?Campus $campus = null,
        ?Department $department = null,
    ): EmployeeAssignment {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('EmployeeAssignmentService::createRecord() runs only inside the creating transaction.');
        }
        $school = $employment->school;
        $startsOn = $attributes['starts_on'];
        $endsOn = $attributes['ends_on'] ?? null;

        $this->assertPositionBelongsToSchool($school->id, $position);
        $this->assertCampusBelongsToSchool($school->id, $campus);
        $this->assertDepartmentBelongsToSchool($school->id, $department);
        $this->assertDepartmentCampusCompatible($department, $campus);
        $this->assertPositionIsActive($position);
        $this->assertDepartmentIsActive($department);
        $this->assertWithinEmploymentRange($employment, $startsOn, $endsOn);

        // SR.4 (ADR 0071 §26.2): never your own assignments.
        $this->selfAdministration->refuseOwnEmployee($actor, $employment->employee_id, 'assignment.create');

        // ORG.7D-0 R5: no new Assignment on an inactive Campus.
        if ($campus !== null && $this->activeCampus->find($school, $campus->id) === null) {
            throw new AssignmentInactiveCampusException($campus->id);
        }

        $assignment = EmployeeAssignment::query()->create([
            'school_id' => $school->id,
            'employment_record_id' => $employment->id,
            'campus_id' => $campus?->id,
            'department_id' => $department?->id,
            'position_id' => $position->id,
            'is_primary' => false,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
        ]);

        $this->audit->school($school, 'hr.assignment.created', actor: $actor, subject: $assignment, metadata: [
            // Phase 8A.11: employeeId added (purely additive, does
            // not change this event's existing semantics) so the
            // Employee Activity Timeline can resolve this event
            // directly from metadata.
            'employeeId' => $employment->employee_id,
            'employmentRecordId' => $employment->id,
            'positionId' => $position->id,
            'departmentId' => $department?->id,
            'campusId' => $campus?->id,
        ]);

        event(new AssignmentStarted($school->id, $employment->employee_id, $employment->id, $assignment->id));

        return $assignment;
    }

    public function end(EmployeeAssignment $assignment, string $endsOn, User $actor): EmployeeAssignment
    {
        $school = $assignment->school;
        $guard = $this->access->writeGuard($school, $this->authorizeCampusScopeFor($actor, 'hr.employees.assignments.manage', $school));

        return $this->context->withSchool($school, function () use ($school, $assignment, $endsOn, $actor, $guard) {
            return DB::transaction(function () use ($school, $assignment, $endsOn, $actor, $guard) {
                $this->placement->forPlacementChange($school, $assignment->employmentRecord->employee_id);
                $employment = $this->holdEmployment($school, $assignment->employment_record_id);
                if ($guard !== null) {
                    // ORG.7E-1: only a CURRENT in-scope assignment, ended today or later.
                    $guard->beforeAssignmentEnd($this->lockAssignment($assignment->id), $endsOn);
                }
                $this->selfAdministration->refuseOwnEmployee($actor, $assignment->employmentRecord->employee_id, 'assignment.end');

                // HR.R1-C2 (second security review): an assignment ends on or after its own start and within its
                // employment -- never the never-effective empty interval, which only EmploymentService::end()
                // records for a placement planned past the employment's end (database-backed:
                // trg_employee_assignments_never_effective).
                $locked = $this->lockAssignment($assignment->id);
                $this->assertWithinEmploymentRange($employment, $locked->starts_on->toDateString(), $endsOn);

                // HR.3A: reporting lines join employments, so ending one
                // assignment (a transfer's source, a secondary role) never
                // touches them.
                $assignment->update(['ends_on' => $endsOn]);

                $employeeId = $assignment->employmentRecord->employee_id;

                $this->audit->school($school, 'hr.assignment.ended', actor: $actor, subject: $assignment, metadata: [
                    // Phase 8A.11: employeeId added, purely additive (see create()).
                    'employeeId' => $employeeId,
                    'employmentRecordId' => $assignment->employment_record_id,
                    'endsOn' => $endsOn,
                ]);

                event(new AssignmentEnded($school->id, $employeeId, $assignment->id));

                return $assignment->fresh();
            });
        });
    }

    /**
     * Demotes whatever Assignment is currently the open primary for
     * this EmploymentRecord (if any) and promotes $assignment, in one
     * transaction -- mirrors
     * App\Domain\HR\Application\EmployeeEmergencyContactService::setPrimary()'s
     * exact demote-then-promote shape (itself mirroring
     * AcademicYearService::activate()'s), with the partial unique
     * index `employee_assignments_one_primary_open_per_employment` as
     * the concurrency backstop. Only currently-open (`ends_on IS
     * NULL`) primaries are demoted -- a historical, already-ended
     * primary Assignment is left untouched, since it never conflicts
     * with a new open primary (docs/modules/HR.md's partial index is
     * scoped the same way).
     *
     * Phase 8A closure correction (item 10, closure hardening): two
     * concurrent `setPrimary()` calls for two DIFFERENT Assignments
     * under the SAME EmploymentRecord can both pass the demote step
     * (each sees no open primary yet, or demotes the same one) and
     * then both attempt to promote themselves -- the partial unique
     * index lets exactly one commit; the loser's raw
     * `UniqueConstraintViolationException` is caught here and
     * translated into `ConcurrentPrimaryAssignmentConflictException`
     * (409, retry-safe), mirroring
     * `AcademicYearService::activate()`'s identical pattern -- never
     * left as an unhandled raw QueryException that would otherwise
     * surface as an opaque 500 through `EmployeeAssignmentController`.
     * Proven with two real separate OS processes, not a sequential
     * simulation -- see `PrimaryAssignmentConcurrencyTest`.
     */
    public function setPrimary(EmployeeAssignment $assignment, User $actor, ?PlacementChangeReason $reason = null): EmployeeAssignment
    {
        $school = $assignment->school;
        $guard = $this->access->writeGuard($school, $this->authorizeCampusScopeFor($actor, 'hr.employees.assignments.manage', $school));

        return $this->context->withSchool($school, function () use ($school, $assignment, $actor, $guard, $reason) {
            return DB::transaction(function () use ($school, $assignment, $actor, $guard, $reason) {
                if ($guard !== null) {
                    // ORG.7E-1: never demote an assignment outside scope. The
                    // exclusive placement lock keeps every other primary change
                    // out until commit, so the open primary read below cannot go
                    // stale (security review M2).
                    $this->placement->forEmployeeWrite($school, $assignment->employmentRecord->employee_id);
                    $this->holdEmployment($school, $assignment->employment_record_id);
                    $openPrimaryId = EmployeeAssignment::query()->where('employment_record_id', $assignment->employment_record_id)
                        ->where('is_primary', true)->whereNull('ends_on')->where('id', '!=', $assignment->id)->value('id');
                    $locked = [];
                    foreach (array_values(array_unique(array_filter([$assignment->id, $openPrimaryId]))) as $id) {
                        $locked[$id] = null;
                    }
                    ksort($locked);
                    foreach (array_keys($locked) as $id) {
                        $locked[$id] = $this->lockAssignment((string) $id);
                    }
                    $guard->beforeSetPrimary($locked[$assignment->id], $openPrimaryId !== null ? $locked[$openPrimaryId] : null);
                } else {
                    $this->placement->forPlacementChange($school, $assignment->employmentRecord->employee_id);
                    $this->holdEmployment($school, $assignment->employment_record_id);
                }

                return $this->setPrimaryRecord($assignment, $actor, $reason);
            });
        });
    }

    /**
     * The demote-then-promote write of setPrimary() (and of
     * EmployeeOnboardingService's first assignment): the self-administration
     * guard, audit and event. No authorization of its own; run inside the
     * caller's transaction and the School's TenantContext.
     */
    public function setPrimaryRecord(EmployeeAssignment $assignment, User $actor, ?PlacementChangeReason $reason = null): EmployeeAssignment
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('EmployeeAssignmentService::setPrimaryRecord() runs only inside the writing transaction.');
        }
        $school = $assignment->school;

        $this->selfAdministration->refuseOwnEmployee($actor, $assignment->employmentRecord->employee_id, 'assignment.primary');

        $stored = EmployeeAssignment::query()->whereKey($assignment->id)->first(['is_primary', 'starts_on', 'ends_on']);
        // HR.R1-C2: a placement that never took effect stays a record of a plan -- never the primary placement.
        if ($stored?->isNeverEffective() === true) {
            throw new PlacementChangeConflictException('never_effective', 'This placement never took effect; its employment ended before it started.');
        }
        $wasPrimary = (bool) $stored?->is_primary;
        // HR.R1-C9: promoting a placement that is over would demote the open primary and leave the employment with
        // no current primary, and an ended employment keeps its placement history. Database-backed for the second.
        if (! $wasPrimary) {
            $employmentEnded = EmploymentRecord::query()->whereKey($assignment->employment_record_id)->whereNotNull('ends_on')->exists();
            if ($employmentEnded || ($stored?->ends_on !== null && $stored->ends_on->toDateString() < SchoolTimezone::today($school))) {
                throw new PlacementChangeConflictException('placement_ended', 'This placement has ended, so it cannot become the primary placement.');
            }
        }
        $otherPrimaries = EmployeeAssignment::query()
            ->where('employment_record_id', $assignment->employment_record_id)
            ->where('is_primary', true)
            ->where('id', '!=', $assignment->id)
            ->get(['id', 'starts_on', 'ends_on']);
        $demotedId = $otherPrimaries->first(fn (EmployeeAssignment $a) => $a->ends_on === null)?->id;

        // HR.1A: an EARLIER (closed) primary period that overlaps this one stays
        // primary history; re-designating over it is refused (a dated move is a
        // named placement change). The trigger trg_employee_assignments_primary_no_overlap
        // is the backstop; this gives the clean refusal.
        $first = $assignment->starts_on->toDateString();
        $last = $assignment->ends_on?->toDateString();
        foreach ($otherPrimaries as $other) {
            $otherLast = $other->ends_on?->toDateString();
            if ($other->id !== $demotedId && $other->starts_on->toDateString() <= ($last ?? '9999-12-31') && $first <= ($otherLast ?? '9999-12-31')) {
                throw new PlacementChangeConflictException('primary_overlap', 'Another primary assignment already covers part of this period.');
            }
        }

        EmployeeAssignment::query()
            ->where('employment_record_id', $assignment->employment_record_id)
            ->where('is_primary', true)
            ->whereNull('ends_on')
            ->where('id', '!=', $assignment->id)
            ->update(['is_primary' => false]);

        try {
            $assignment->update(['is_primary' => true]);
        } catch (UniqueConstraintViolationException) {
            throw new ConcurrentPrimaryAssignmentConflictException($assignment->employment_record_id);
        } catch (QueryException $e) {
            // A concurrent primary committed first (the trigger's advisory lock serialized us).
            if ($e->getCode() === '23P01') {
                throw new ConcurrentPrimaryAssignmentConflictException($assignment->employment_record_id);
            }
            throw $e;
        }

        // HR.1A: the named record. The first primary of an employment is its
        // initial placement; any later re-designation is a primary correction
        // (dated moves are PlacementChangeService's transfer/promotion/... kinds).
        if (! $wasPrimary) {
            $previousId = $demotedId ?? $otherPrimaries->sortByDesc(fn (EmployeeAssignment $a) => $a->starts_on->toDateString())->first()?->id;
            EmployeePlacementChange::query()->create([
                'school_id' => $school->id,
                'employment_record_id' => $assignment->employment_record_id,
                'change_kind' => $previousId === null ? 'initial_placement' : 'primary_correction',
                'from_assignment_id' => $previousId,
                'to_assignment_id' => $assignment->id,
                'effective_on' => $assignment->starts_on->toDateString(),
                'reason_code' => $previousId === null ? 'initial_placement' : ($reason ?? PlacementChangeReason::ADMINISTRATIVE_CORRECTION)->value,
                'recorded_by_user_id' => $actor->id,
            ]);
        }

        $employeeId = $assignment->employmentRecord->employee_id;

        $this->audit->school($school, 'hr.assignment.primary_changed', actor: $actor, subject: $assignment, metadata: [
            // Phase 8A.11: employeeId added, purely additive (see create()).
            'employeeId' => $employeeId,
            'employmentRecordId' => $assignment->employment_record_id,
        ]);

        event(new PrimaryAssignmentChanged($school->id, $employeeId, $assignment->employment_record_id, $assignment->id));

        return $assignment->fresh();
    }

    private function lockAssignment(string $id): EmployeeAssignment
    {
        return EmployeeAssignment::query()->where('school_id', $this->context->requireSchool()->id)->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function assertPositionBelongsToSchool(string $schoolId, Position $position): void
    {
        if ($position->school_id !== $schoolId) {
            throw new AssignmentPositionMismatchException($position->id, $schoolId, $position->school_id);
        }
    }

    private function assertCampusBelongsToSchool(string $schoolId, ?Campus $campus): void
    {
        if ($campus !== null && $campus->school_id !== $schoolId) {
            throw new AssignmentCampusMismatchException($campus->id, $schoolId, $campus->school_id);
        }
    }

    private function assertDepartmentBelongsToSchool(string $schoolId, ?Department $department): void
    {
        if ($department !== null && $department->school_id !== $schoolId) {
            throw new AssignmentDepartmentMismatchException($department->id, $schoolId, $department->school_id);
        }
    }

    /**
     * docs/modules/HR.md's `hr_departments.campus_id`: null = School-
     * wide (compatible with any Assignment Campus), non-null =
     * Campus-scoped (the Assignment's own campus_id must match
     * exactly).
     */
    private function assertDepartmentCampusCompatible(?Department $department, ?Campus $campus): void
    {
        if ($department === null || $department->campus_id === null) {
            return;
        }

        if ($campus === null || $campus->id !== $department->campus_id) {
            throw new AssignmentDepartmentCampusScopeMismatchException($department->id, $department->campus_id, $campus?->id);
        }
    }

    private function assertPositionIsActive(Position $position): void
    {
        if (! $position->isActive()) {
            throw new AssignmentInactivePositionException($position->id);
        }
    }

    private function assertDepartmentIsActive(?Department $department): void
    {
        if ($department !== null && ! $department->isActive()) {
            throw new AssignmentInactiveDepartmentException($department->id);
        }
    }

    /**
     * HR.R1-C2: the employment row as it stands now, FOR SHARE, in the one
     * order every placement writer uses (placement lock -> employment ->
     * assignment rows). The whole-School placement lock is itself FOR SHARE
     * and never excludes EmploymentService::end(); this row lock does, so a
     * writer that waited for an end decides on the ended row, never on the
     * model it loaded before the wait.
     */
    private function holdEmployment(School $school, string $employmentId): EmploymentRecord
    {
        return EmploymentRecord::query()->where('school_id', $school->id)->whereKey($employmentId)->sharedLock()->firstOrFail();
    }

    private function assertWithinEmploymentRange(EmploymentRecord $employment, string $startsOn, ?string $endsOn): void
    {
        $employmentStartsOn = $employment->starts_on->toDateString();
        $employmentEndsOn = $employment->ends_on?->toDateString();

        $startsWithinRange = $startsOn >= $employmentStartsOn
            && ($employmentEndsOn === null || $startsOn <= $employmentEndsOn);

        // HR.R1-C2: never a reversed range (the empty interval belongs to EmploymentService::end() alone).
        $endsWithinRange = $endsOn === null
            ? $employmentEndsOn === null
            : ($endsOn >= $startsOn && $endsOn >= $employmentStartsOn && ($employmentEndsOn === null || $endsOn <= $employmentEndsOn));

        if (! $startsWithinRange || ! $endsWithinRange) {
            throw new AssignmentOutsideEmploymentRangeException($employment->id, $startsOn, $endsOn);
        }
    }
}
