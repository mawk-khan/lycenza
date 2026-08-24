<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeePersonalDetail;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The only sanctioned write path for an Employee's Restricted-tier
 * personal details (docs/modules/HR.md entity model:
 * `EmployeePersonalDetail` is 1:1). `setDetails()` creates the row if
 * absent, otherwise updates the existing one -- `updateOrCreate()`
 * keyed on `employee_id`, mirroring
 * App\Support\Settings\SchoolSettingsService's own upsert pattern --
 * with the table's `unique(employee_id)` constraint as the database-
 * level backstop against a genuine race producing two rows.
 */
class EmployeePersonalDetailService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function setDetails(Employee $employee, array $attributes, User $actor): EmployeePersonalDetail
    {
        $this->authorizeCapabilityFor($actor, 'hr.employees.personal.manage', $employee->school);

        unset($attributes['school_id'], $attributes['employee_id']);

        return $this->context->withSchool($employee->school, function () use ($employee, $attributes, $actor) {
            return DB::transaction(function () use ($employee, $attributes, $actor) {
                $detail = EmployeePersonalDetail::query()->updateOrCreate(
                    ['employee_id' => $employee->id, 'school_id' => $employee->school_id],
                    $attributes,
                );

                $this->audit->school($employee->school, 'employee.personal_details.updated', actor: $actor, subject: $detail, metadata: [
                    'employeeId' => $employee->id,
                    'fields' => array_keys($attributes),
                ]);

                return $detail;
            });
        });
    }
}
