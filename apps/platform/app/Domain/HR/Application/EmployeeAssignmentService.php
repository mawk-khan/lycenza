<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Application\Exceptions\AssignmentCampusMismatchException;
use App\Domain\HR\Application\Exceptions\AssignmentDepartmentCampusScopeMismatchException;
use App\Domain\HR\Application\Exceptions\AssignmentDepartmentMismatchException;
use App\Domain\HR\Application\Exceptions\AssignmentInactiveDepartmentException;
use App\Domain\HR\Application\Exceptions\AssignmentInactivePositionException;
use App\Domain\HR\Application\Exceptions\AssignmentOutsideEmploymentRangeException;
use App\Domain\HR\Application\Exceptions\AssignmentPositionMismatchException;
use App\Domain\HR\Infrastructure\Department;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\Position;
use App\Models\Campus;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
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
 */
class EmployeeAssignmentService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly AssignmentClosureCascade $closureCascade,
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
        ?Campus $campus = null,
        ?Department $department = null,
        ?User $actor = null,
    ): EmployeeAssignment {
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

        return $this->context->withSchool($school, function () use ($school, $employment, $position, $campus, $department, $startsOn, $endsOn, $actor) {
            return DB::transaction(function () use ($school, $employment, $position, $campus, $department, $startsOn, $endsOn, $actor) {
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
                    'employmentRecordId' => $employment->id,
                    'positionId' => $position->id,
                    'departmentId' => $department?->id,
                    'campusId' => $campus?->id,
                ]);

                return $assignment;
            });
        });
    }

    public function end(EmployeeAssignment $assignment, string $endsOn, ?User $actor = null): EmployeeAssignment
    {
        $school = $assignment->school;

        return $this->context->withSchool($school, function () use ($school, $assignment, $endsOn, $actor) {
            return DB::transaction(function () use ($school, $assignment, $endsOn, $actor) {
                $assignment->update(['ends_on' => $endsOn]);

                $this->closureCascade->clearDanglingManagerReferences([$assignment->id]);

                $this->audit->school($school, 'hr.assignment.ended', actor: $actor, subject: $assignment, metadata: [
                    'employmentRecordId' => $assignment->employment_record_id,
                    'endsOn' => $endsOn,
                ]);

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
     */
    public function setPrimary(EmployeeAssignment $assignment, ?User $actor = null): EmployeeAssignment
    {
        $school = $assignment->school;

        return $this->context->withSchool($school, function () use ($school, $assignment, $actor) {
            return DB::transaction(function () use ($school, $assignment, $actor) {
                EmployeeAssignment::query()
                    ->where('employment_record_id', $assignment->employment_record_id)
                    ->where('is_primary', true)
                    ->whereNull('ends_on')
                    ->where('id', '!=', $assignment->id)
                    ->update(['is_primary' => false]);

                $assignment->update(['is_primary' => true]);

                $this->audit->school($school, 'hr.assignment.primary_changed', actor: $actor, subject: $assignment, metadata: [
                    'employmentRecordId' => $assignment->employment_record_id,
                ]);

                return $assignment->fresh();
            });
        });
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

    private function assertWithinEmploymentRange(EmploymentRecord $employment, string $startsOn, ?string $endsOn): void
    {
        $employmentStartsOn = $employment->starts_on->toDateString();
        $employmentEndsOn = $employment->ends_on?->toDateString();

        $startsWithinRange = $startsOn >= $employmentStartsOn
            && ($employmentEndsOn === null || $startsOn <= $employmentEndsOn);

        $endsWithinRange = $endsOn === null
            ? $employmentEndsOn === null
            : ($endsOn >= $employmentStartsOn && ($employmentEndsOn === null || $endsOn <= $employmentEndsOn));

        if (! $startsWithinRange || ! $endsWithinRange) {
            throw new AssignmentOutsideEmploymentRangeException($employment->id, $startsOn, $endsOn);
        }
    }
}
