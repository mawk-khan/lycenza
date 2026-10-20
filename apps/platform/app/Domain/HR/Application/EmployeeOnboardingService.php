<?php

namespace App\Domain\HR\Application;

use App\Domain\Campuses\Application\ActiveCampus;
use App\Domain\HR\Application\Exceptions\DuplicateWorkEmailException;
use App\Domain\HR\Application\Exceptions\HrOnboardingUnavailableException;
use App\Domain\HR\Infrastructure\Department;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\Position;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * ORG.7E-1 (ADR 0076 §43, D-E0-10): THE named Campus-safe onboarding
 * operation -- a new Employee, their first Employment (`active`) and their
 * first, primary, current Assignment on one active Campus, in ONE
 * transaction. Nothing persists unless all three do, so onboarding never
 * leaves an unassigned (Campus-less) Employee behind.
 *
 * - Authority: `hr.employees.manage` AND `hr.employees.assignments.manage`
 *   (the two capabilities the separate whole-School steps need), each
 *   covering the destination Campus. A whole-School actor may name any
 *   active Campus; a Campus-scoped actor one of theirs.
 * - Immediately current: the start date is on or before the School's today
 *   (a future joiner is a whole-School pre-joining matter).
 * - Core, non-sensitive fields only (EmployeeOnboardingData). No account
 *   link, personal, qualification, document, note or pay data.
 * - Never a capture: a work email already used by an Employee of the School
 *   is refused -- generically for a Campus-scoped actor (HR_ONBOARDING_
 *   UNAVAILABLE, naming no Employee or Campus), with the existing duplicate
 *   message for a whole-School one. Existing identities are whole-School.
 * - The Campus row is share-locked first (a concurrent deactivation waits,
 *   or committed first and is refused). Synchronous; no job.
 */
class EmployeeOnboardingService
{
    use AuthorizesCampusScope;

    public function __construct(
        private readonly TenantContext $context,
        private readonly ActiveCampus $activeCampus,
        private readonly EmployeeService $employees,
        private readonly EmploymentService $employments,
        private readonly EmployeeAssignmentService $assignments,
    ) {}

    public function onboard(School $school, EmployeeOnboardingData $data, User $actor): Employee
    {
        $manage = $this->authorizeCampusScopeFor($actor, 'hr.employees.manage', $school);
        $assign = $this->authorizeCampusScopeFor($actor, 'hr.employees.assignments.manage', $school);
        $scoped = ! $manage->isWholeSchool() || ! $assign->isWholeSchool();
        $refuse = fn (\Throwable $whole) => $scoped ? new HrOnboardingUnavailableException : $whole;

        $startsOn = CarbonImmutable::parse($data->startsOn)->toDateString();

        return $this->context->withSchool($school, function () use ($school, $data, $actor, $manage, $assign, $scoped, $refuse, $startsOn): Employee {
            if (! $manage->covers($data->campusId) || ! $assign->covers($data->campusId)) {
                throw new HrOnboardingUnavailableException;
            }
            // Immediately current; a Campus-scoped actor onboards from today only
            // (backdated starts are whole-School history, security review L2).
            $today = app(CurrentEmployeeAssignments::class)->today($school);
            if ($startsOn > $today || ($scoped && $startsOn !== $today)) {
                throw new HrOnboardingUnavailableException;
            }

            try {
                return DB::transaction(function () use ($school, $data, $actor, $startsOn): Employee {
                    $campus = $this->activeCampus->find($school, $data->campusId) ?? throw new HrOnboardingUnavailableException;
                    $position = Position::query()->where('school_id', $school->id)->find($data->positionId) ?? throw new HrOnboardingUnavailableException;
                    $department = $data->departmentId === null ? null
                        : (Department::query()->where('school_id', $school->id)->find($data->departmentId) ?? throw new HrOnboardingUnavailableException);
                    // A Department of another Campus is refused here, before the
                    // assignment's own check could name that Campus.
                    if ($department !== null && $department->campus_id !== null && $department->campus_id !== $campus->id) {
                        throw new HrOnboardingUnavailableException;
                    }

                    $employee = $this->employees->createRecord($school, [
                        'full_name' => $data->fullName,
                        'work_email' => $data->workEmail,
                        'work_phone' => $data->workPhone,
                    ], $actor);
                    $employment = $this->employments->createRecord($employee, [
                        'employment_type' => $data->employmentType,
                        'starts_on' => $startsOn,
                        'status' => 'active',
                    ], $actor);
                    $assignment = $this->assignments->createRecord($employment, ['starts_on' => $startsOn], $position, $actor, $campus, $department);
                    $this->assignments->setPrimaryRecord($assignment, $actor);

                    return $employee->fresh() ?? $employee;
                });
            } catch (DuplicateWorkEmailException $e) {
                throw $refuse($e);
            }
        });
    }
}
