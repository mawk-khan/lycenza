<?php

namespace App\Domain\HR\Application;

use App\Domain\Campuses\Application\ActiveCampus;
use App\Domain\HR\Application\Exceptions\HrAssignmentUnavailableException;
use App\Domain\HR\Application\Exceptions\HrWholeSchoolAuthorityRequiredException;
use App\Domain\HR\Infrastructure\Department;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Models\School;
use App\Support\Authorization\Campus\CampusScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * ORG.7E-1 (ADR 0076 §43): a Campus-scoped HR actor's write-time checks, run
 * by the HR services INSIDE their transaction, AFTER EmployeePlacementLock --
 * so every answer is about committed placement that cannot change until the
 * write commits (a concurrent assignment, end, employment end or archive
 * waits for the lock, or committed first and is seen here; a concurrent
 * Campus deactivation waits for the Campus share lock, or committed first and
 * is refused here).
 *
 *   identity / Employee-level write   EVERY current Campus of the Employee in
 *   (core fields)                     scope, at least one, and no current
 *                                     NULL-Campus (School-wide) assignment
 *   new assignment                    the same, plus the CURRENT employment
 *                                     and an active in-scope destination
 *   per-assignment write (end,        that assignment current, on an active
 *   primary)                          in-scope Campus
 *   reporting line (HR.3A)            the subordinate as for an identity
 *                                     edit; a manager currently on an
 *                                     active in-scope Campus
 *
 * An Employee the scope no longer reaches is NOT FOUND; a reachable one whose
 * placement exceeds the scope is HR_WHOLE_SCHOOL_AUTHORITY_REQUIRED; an
 * impossible assignment change is the generic HR_ASSIGNMENT_UNAVAILABLE.
 * None names another Campus or record. Built only by HrEmployeeAccess.
 */
final class HrEmployeeCampusGuard
{
    public function __construct(
        private readonly School $school,
        private readonly CampusScope $scope,
        private readonly CurrentEmployeeAssignments $current,
    ) {}

    /** Identity edit: after forEmployeeWrite(). */
    public function beforeEmployeeWrite(Employee $lockedEmployee): void
    {
        $this->assertCoversEveryCurrentCampus($lockedEmployee->id);
    }

    /**
     * A new (secondary) assignment for an EXISTING Employee: after
     * forEmployeeWrite(). It starts today or later: backdated placement is
     * whole-School history (security review L2).
     */
    public function beforeAssignmentCreate(EmploymentRecord $employment, ?string $destinationCampusId, ?Department $department, string $startsOn): void
    {
        $this->assertCoversEveryCurrentCampus($employment->employee_id);
        if ($this->current->currentEmployment($this->school, $employment->employee_id)?->id !== $employment->id
            || CarbonImmutable::parse($startsOn)->toDateString() < $this->current->today($this->school)
            || ! is_string($destinationCampusId) || ! $this->scope->covers($destinationCampusId)
            // A Department of another Campus is refused before the assignment's own check could name it.
            || ($department !== null && $department->campus_id !== null && $department->campus_id !== $destinationCampusId)
            || app(ActiveCampus::class)->find($this->school, $destinationCampusId) === null) {
            throw new HrAssignmentUnavailableException;
        }
    }

    /** End, primary or manager of one assignment: after forPlacementChange() and the assignment row lock. */
    public function beforeAssignmentChange(EmployeeAssignment $lockedAssignment): void
    {
        if (! $this->inScopeCurrent($lockedAssignment)) {
            throw (new ModelNotFoundException)->setModel(EmployeeAssignment::class, [$lockedAssignment->id]);
        }
    }

    /**
     * An end: today or later, never before the start, never later than an end
     * already set (only shortening) or the employment's own end; backdated
     * corrections are whole-School (security review L2). Since HR.3A an
     * assignment end never touches reporting lines (they join employments),
     * so the former M1 subordinate check has nothing left to guard.
     */
    public function beforeAssignmentEnd(EmployeeAssignment $lockedAssignment, string $endsOn): void
    {
        $this->beforeAssignmentChange($lockedAssignment);
        $endsOn = CarbonImmutable::parse($endsOn)->toDateString();
        $employmentEndsOn = EmploymentRecord::query()->where('school_id', $this->school->id)->whereKey($lockedAssignment->employment_record_id)->value('ends_on');
        if ($endsOn < $this->current->today($this->school) || $endsOn < $lockedAssignment->starts_on->toDateString()
            || ($lockedAssignment->ends_on !== null && $endsOn > $lockedAssignment->ends_on->toDateString())
            || ($employmentEndsOn !== null && $endsOn > CarbonImmutable::parse($employmentEndsOn)->toDateString())) {
            throw new HrAssignmentUnavailableException;
        }
    }

    /**
     * HR.1A: a named placement change (transfer, promotion, designation or
     * department change) by a Campus-scoped actor -- after forEmployeeWrite()
     * and the row locks. It ends the source and opens a new placement, so it
     * needs everything both need: EVERY current Campus of the Employee in
     * scope (as for a new assignment), the source current on an active
     * in-scope Campus, an effective date of today or later (backdated
     * placement is whole-School history, security review L2) and an active
     * in-scope destination. Reporting lines are not touched (HR.3A).
     */
    public function beforePlacementChange(EmployeeAssignment $lockedSource, string $effectiveOn, ?string $destinationCampusId, ?Department $department): void
    {
        $employeeId = (string) EmploymentRecord::query()->where('school_id', $this->school->id)->whereKey($lockedSource->employment_record_id)->value('employee_id');
        $this->assertCoversEveryCurrentCampus($employeeId);
        $this->beforeAssignmentChange($lockedSource);
        if (CarbonImmutable::parse($effectiveOn)->toDateString() < $this->current->today($this->school)
            || ! is_string($destinationCampusId) || ! $this->scope->covers($destinationCampusId)
            || ($department !== null && $department->campus_id !== null && $department->campus_id !== $destinationCampusId)
            || app(ActiveCampus::class)->find($this->school, $destinationCampusId) === null) {
            throw new HrAssignmentUnavailableException;
        }
    }

    /** Setting primary never demotes an assignment outside scope (or a NULL-Campus one). */
    public function beforeSetPrimary(EmployeeAssignment $lockedAssignment, ?EmployeeAssignment $lockedOpenPrimary): void
    {
        $this->beforeAssignmentChange($lockedAssignment);
        if ($lockedOpenPrimary !== null && $lockedOpenPrimary->id !== $lockedAssignment->id
            && ! (is_string($lockedOpenPrimary->campus_id) && $this->scope->covers($lockedOpenPrimary->campus_id))) {
            throw new HrWholeSchoolAuthorityRequiredException;
        }
    }

    /**
     * HR.3A: a reporting-line change (assign, change, remove) by a
     * Campus-scoped actor, after the reporting lock and the employment share
     * locks. The line belongs to the whole EMPLOYMENT, so the subordinate
     * Employee must be wholly in scope (as for an identity edit) and
     * currently employed by it; the manager, when named, must be currently
     * employed on an active in-scope Campus; the effective date is today or
     * later (dated history and corrections are whole-School, security review
     * L2).
     */
    public function beforeReportingChange(EmploymentRecord $subordinate, ?EmploymentRecord $manager, string $effectiveOn): void
    {
        $this->assertCoversEveryCurrentCampus($subordinate->employee_id);
        if ($this->current->currentEmployment($this->school, $subordinate->employee_id)?->id !== $subordinate->id
            || CarbonImmutable::parse($effectiveOn)->toDateString() < $this->current->today($this->school)) {
            throw new HrAssignmentUnavailableException;
        }
        if ($manager === null) {
            return;
        }
        $managerCampuses = $this->current->currentEmployment($this->school, $manager->employee_id)?->id === $manager->id
            ? $this->current->campusesFor($this->school, $manager->employee_id) : [];
        foreach ($managerCampuses as $campusId) {
            if ($this->scope->covers($campusId) && app(ActiveCampus::class)->find($this->school, $campusId) !== null) {
                return;
            }
        }
        throw new HrAssignmentUnavailableException;
    }

    private function assertCoversEveryCurrentCampus(string $employeeId): void
    {
        $campuses = $this->current->campusesFor($this->school, $employeeId);
        if (array_intersect($campuses, $this->scope->campusIds()) === []) {
            throw (new ModelNotFoundException)->setModel(Employee::class, [$employeeId]);
        }
        $unplaced = $this->current->currentAssignmentsOf($this->school, [$employeeId])->contains(fn (EmployeeAssignment $a) => $a->campus_id === null);
        if ($unplaced || array_diff($campuses, $this->scope->campusIds()) !== []) {
            throw new HrWholeSchoolAuthorityRequiredException;
        }
        // Each Campus the decision rests on stays active until commit (a
        // concurrent deactivation waits; one that committed is refused).
        foreach ($campuses as $campusId) {
            if (app(ActiveCampus::class)->find($this->school, $campusId) === null) {
                throw (new ModelNotFoundException)->setModel(Employee::class, [$employeeId]);
            }
        }
    }

    private function inScopeCurrent(EmployeeAssignment $assignment): bool
    {
        $campusId = $assignment->campus_id;

        return is_string($campusId) && $this->scope->covers($campusId)
            && $this->current->isCurrentAssignment($this->school, $assignment->id)
            && app(ActiveCampus::class)->find($this->school, $campusId) !== null;
    }
}
