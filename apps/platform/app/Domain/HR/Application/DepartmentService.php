<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Application\Exceptions\DepartmentCampusMismatchException;
use App\Domain\HR\Application\Exceptions\DepartmentHierarchyCycleException;
use App\Domain\HR\Application\Exceptions\DepartmentParentMismatchException;
use App\Domain\HR\Infrastructure\Department;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The only sanctioned write path for HR Department reference data
 * (docs/modules/HR.md "Department and Position strategy"), mirroring
 * App\Domain\HR\Application\EmployeeService's exact shape. `$campus`/
 * `$parent` are accepted as real, already-resolved model instances
 * (never a raw caller-supplied id) -- the same "never trust a
 * caller-supplied id, use the authoritative resolved model" principle
 * App\Domain\HR\Application\EmployeeAddressService already established
 * for `Employee $employee`, applied here to Campus/parent-Department
 * ownership.
 *
 * Phase 8A.10: every public method requires a real `User $actor` and
 * authorizes `hr.departments.manage` at $school before doing anything
 * else -- this is the authoritative production entry point (no
 * controller exists yet), so authorization lives here, not "assumed
 * done by a future caller" (docs/modules/HR.md 8A.10 as-built section).
 */
class DepartmentService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly SchoolOperationalGuard $operational,
    ) {}

    /**
     * @param  array{name: string, code: string, description?: string|null}  $attributes
     */
    public function create(School $school, array $attributes, User $actor, ?Campus $campus = null, ?Department $parent = null): Department
    {
        $this->authorizeCapabilityFor($actor, 'hr.departments.manage', $school);

        return $this->context->withSchool($school, function () use ($school, $attributes, $campus, $parent, $actor) {
            $this->assertCampusBelongsToSchool($school, $campus);
            $this->assertParentBelongsToSchool($school, $parent);

            return DB::transaction(function () use ($school, $attributes, $campus, $parent, $actor) {
                $this->operational->requireOperational($school->id);
                $department = Department::query()->forceCreate([
                    'school_id' => $school->id,
                    'campus_id' => $campus?->id,
                    'parent_department_id' => $parent?->id,
                    'name' => $attributes['name'],
                    'code' => $attributes['code'],
                    'description' => $attributes['description'] ?? null,
                    'status' => 'active',
                ]);

                $this->audit->school($school, 'hr.department.created', actor: $actor, subject: $department, metadata: [
                    'code' => $department->code,
                ]);

                return $department;
            });
        });
    }

    /**
     * Simple-field update only (name/code/description) -- campus scope
     * changes and parent changes each have their own dedicated,
     * separately-validated method below (recampus is not required by
     * this checkpoint's scope; reparent() is).
     *
     * @param  array<string, mixed>  $attributes  name/code/description keys are applied; school_id/campus_id/parent_department_id/status are always stripped below
     */
    public function update(Department $department, array $attributes, User $actor): Department
    {
        $school = $department->school;
        $this->authorizeCapabilityFor($actor, 'hr.departments.manage', $school);

        unset($attributes['school_id'], $attributes['campus_id'], $attributes['parent_department_id'], $attributes['status']);

        return $this->context->withSchool($school, function () use ($school, $department, $attributes, $actor) {
            return DB::transaction(function () use ($school, $department, $attributes, $actor) {
                $this->operational->requireOperational($school->id);
                $department->update($attributes);

                $this->audit->school($school, 'hr.department.updated', actor: $actor, subject: $department, metadata: [
                    'fields' => array_keys($attributes),
                ]);

                return $department->fresh();
            });
        });
    }

    public function archive(Department $department, User $actor): Department
    {
        $this->authorizeCapabilityFor($actor, 'hr.departments.manage', $department->school);

        return $this->setStatus($department, 'inactive', 'hr.department.archived', $actor);
    }

    public function reactivate(Department $department, User $actor): Department
    {
        $this->authorizeCapabilityFor($actor, 'hr.departments.manage', $department->school);

        return $this->setStatus($department, 'active', 'hr.department.reactivated', $actor);
    }

    /**
     * Reparents $department under $newParent (or clears it to
     * top-level when $newParent is null). Validates same-School
     * ownership and walks $newParent's ancestry chain to reject any
     * cycle (direct or indirect) before writing -- self-parenting is
     * additionally rejected by the database CHECK constraint as a
     * backstop, but that alone cannot catch an indirect cycle.
     */
    public function reparent(Department $department, ?Department $newParent, User $actor): Department
    {
        $school = $department->school;
        $this->authorizeCapabilityFor($actor, 'hr.departments.manage', $school);

        return $this->context->withSchool($school, function () use ($school, $department, $newParent, $actor) {
            if ($newParent !== null) {
                $this->assertParentBelongsToSchool($school, $newParent);
            }

            return DB::transaction(function () use ($school, $department, $newParent, $actor) {
                $this->operational->requireOperational($school->id);
                // HR.1A (S-9): the cycle check runs INSIDE the transaction, after the
                // School's Department-hierarchy lock -- the same advisory lock the
                // backstop trigger trg_hr_departments_no_cycle takes -- so two opposite
                // concurrent reparents serialize and the second sees the first.
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['hr.departments:'.$school->id]);
                if ($newParent !== null) {
                    $this->assertNoCycle($department, $newParent);
                }

                try {
                    $department->forceFill(['parent_department_id' => $newParent?->id])->save();
                } catch (QueryException $e) {
                    if ($newParent !== null && str_contains($e->getMessage(), 'hr_departments_no_cycle')) {
                        throw new DepartmentHierarchyCycleException($department->id, $newParent->id);
                    }
                    throw $e;
                }

                $this->audit->school($school, 'hr.department.reparented', actor: $actor, subject: $department, metadata: [
                    'newParentId' => $newParent?->id,
                ]);

                return $department->fresh();
            });
        });
    }

    private function setStatus(Department $department, string $status, string $eventType, ?User $actor): Department
    {
        $school = $department->school;

        return $this->context->withSchool($school, function () use ($school, $department, $status, $eventType, $actor) {
            return DB::transaction(function () use ($school, $department, $status, $eventType, $actor) {
                $this->operational->requireOperational($school->id);
                $department->forceFill(['status' => $status])->save();

                $this->audit->school($school, $eventType, actor: $actor, subject: $department);

                return $department->fresh();
            });
        });
    }

    private function assertCampusBelongsToSchool(School $school, ?Campus $campus): void
    {
        if ($campus === null) {
            return;
        }

        if ($campus->school_id !== $school->id) {
            throw new DepartmentCampusMismatchException($campus->id, $school->id, $campus->school_id);
        }
    }

    private function assertParentBelongsToSchool(School $school, ?Department $parent): void
    {
        if ($parent === null) {
            return;
        }

        if ($parent->school_id !== $school->id) {
            throw new DepartmentParentMismatchException($parent->id, $school->id, $parent->school_id);
        }
    }

    /**
     * Deterministic ancestry walk, not a generic graph/cycle engine --
     * each Department has at most one parent, so this is a simple
     * bounded chain, not arbitrary graph traversal.
     */
    private function assertNoCycle(Department $department, Department $proposedParent): void
    {
        $current = Department::query()->find($proposedParent->id);
        $visited = [];

        while ($current !== null) {
            if ($current->id === $department->id) {
                throw new DepartmentHierarchyCycleException($department->id, $proposedParent->id);
            }

            if (in_array($current->id, $visited, true)) {
                break; // defensive: an existing cycle would already have been rejected when it was created
            }

            $visited[] = $current->id;
            // Fresh reads (never a cached relation): the walk must see the committed tree.
            $current = $current->parent_department_id !== null
                ? Department::query()->find($current->parent_department_id)
                : null;
        }
    }
}
