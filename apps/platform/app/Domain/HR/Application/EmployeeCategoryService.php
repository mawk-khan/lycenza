<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Infrastructure\EmployeeCategory;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8A closure correction ("EmployeeCategory"). The only sanctioned
 * write path for EmployeeCategory reference data, mirroring
 * App\Domain\HR\Application\PositionService's shape exactly. School-
 * configurable classification (Teaching/Non-Teaching/Contract/
 * Visiting, ...) -- reference data, not a hardcoded enum.
 *
 * Every public method requires a real `User $actor` and authorizes
 * `hr.categories.manage` at $school before doing anything else.
 */
class EmployeeCategoryService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array{name: string, code: string}  $attributes
     */
    public function create(School $school, array $attributes, User $actor): EmployeeCategory
    {
        $this->authorizeCapabilityFor($actor, 'hr.categories.manage', $school);

        return $this->context->withSchool($school, function () use ($school, $attributes, $actor) {
            return DB::transaction(function () use ($school, $attributes, $actor) {
                $category = EmployeeCategory::query()->create([
                    'school_id' => $school->id,
                    'name' => $attributes['name'],
                    'code' => $attributes['code'],
                    'status' => 'active',
                ]);

                $this->audit->school($school, 'hr.employee_category.created', actor: $actor, subject: $category, metadata: [
                    'code' => $category->code,
                ]);

                return $category;
            });
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(EmployeeCategory $category, array $attributes, User $actor): EmployeeCategory
    {
        $school = $category->school;
        $this->authorizeCapabilityFor($actor, 'hr.categories.manage', $school);

        unset($attributes['school_id'], $attributes['status']);

        return $this->context->withSchool($school, function () use ($school, $category, $attributes, $actor) {
            return DB::transaction(function () use ($school, $category, $attributes, $actor) {
                $category->update($attributes);

                $this->audit->school($school, 'hr.employee_category.updated', actor: $actor, subject: $category, metadata: [
                    'fields' => array_keys($attributes),
                ]);

                return $category->fresh();
            });
        });
    }

    public function archive(EmployeeCategory $category, User $actor): EmployeeCategory
    {
        $this->authorizeCapabilityFor($actor, 'hr.categories.manage', $category->school);

        return $this->setStatus($category, 'inactive', 'hr.employee_category.archived', $actor);
    }

    public function reactivate(EmployeeCategory $category, User $actor): EmployeeCategory
    {
        $this->authorizeCapabilityFor($actor, 'hr.categories.manage', $category->school);

        return $this->setStatus($category, 'active', 'hr.employee_category.reactivated', $actor);
    }

    private function setStatus(EmployeeCategory $category, string $status, string $eventType, ?User $actor): EmployeeCategory
    {
        $school = $category->school;

        return $this->context->withSchool($school, function () use ($school, $category, $status, $eventType, $actor) {
            return DB::transaction(function () use ($school, $category, $status, $eventType, $actor) {
                $category->update(['status' => $status]);

                $this->audit->school($school, $eventType, actor: $actor, subject: $category);

                return $category->fresh();
            });
        });
    }
}
