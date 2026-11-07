<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Application\Exceptions\EmployeeProfileChangedException;
use App\Domain\HR\Application\Profile\EmployeeProfileVersion;
use App\Domain\HR\Application\Profile\ProfileContactNormalizer;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeePersonalDetail;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The only sanctioned write path for an Employee's Restricted-tier
 * personal details (docs/modules/HR.md entity model:
 * `EmployeePersonalDetail` is 1:1). `setDetails()` creates the row if
 * absent, otherwise updates the existing one -- a find-or-new under the Employee lock
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
        private readonly HrSelfAdministrationGuard $selfAdministration,
        private readonly SchoolOperationalGuard $operational,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function setDetails(Employee $employee, array $attributes, User $actor, ?string $expectedVersion = null): EmployeePersonalDetail
    {
        $this->authorizeCapabilityFor($actor, 'hr.employees.personal.manage', $employee->school);

        unset($attributes['school_id'], $attributes['employee_id']);
        // HR.2A: one server-side normal form for contact values.
        $attributes = ProfileContactNormalizer::normalize($attributes);

        return $this->context->withSchool($employee->school, function () use ($employee, $attributes, $actor, $expectedVersion) {
            return DB::transaction(function () use ($employee, $attributes, $actor, $expectedVersion) {
                $this->operational->requireOperational($employee->school_id);
                // HR.2A: the Employee row lock FIRST (it serializes the first insert and the token
                // check); then the self-administration read (a weaker lock on the same row).
                Employee::query()->whereKey($employee->id)->lock('for no key update')->firstOrFail();
                // HR.2A (S-3): nobody edits their own personal record.
                $this->selfAdministration->refuseOwnEmployee($actor, $employee->id, 'profile.personal');
                if ($expectedVersion !== null) {
                    $current = EmployeePersonalDetail::query()->where('employee_id', $employee->id)->first();
                    if (! EmployeeProfileVersion::matches(EmployeeProfileVersion::personal($employee->id, $current), $expectedVersion)) {
                        throw new EmployeeProfileChangedException;
                    }
                }
                // HR.R1-C13 (GOV-6): the owner's columns are stated, and the caller's fields pass through fill().
                // The Employee lock above serializes the first insert, so this read decides.
                $detail = EmployeePersonalDetail::query()->where('employee_id', $employee->id)->first()
                    ?? (new EmployeePersonalDetail)->forceFill(['employee_id' => $employee->id, 'school_id' => $employee->school_id]);
                $detail->fill($attributes)->save();

                $this->audit->school($employee->school, 'employee.personal_details.updated', actor: $actor, subject: $detail, metadata: [
                    'employeeId' => $employee->id,
                    'fields' => array_keys($attributes),
                ]);

                return $detail;
            });
        });
    }
}
