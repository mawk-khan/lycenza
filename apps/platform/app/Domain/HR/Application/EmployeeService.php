<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Application\Exceptions\UnrelatedUserLinkageException;
use App\Domain\HR\Events\EmployeeCreated;
use App\Domain\HR\Infrastructure\Employee;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The only sanctioned write path for Employee creation (docs/modules/HR.md
 * "Employee creation service", mirroring
 * App\Domain\AcademicStructure\Application\AcademicYearService's exact
 * pattern) -- never write Employee directly from a controller/factory/
 * seeder in application code. One method, one transaction: resolve
 * School/tenant context, validate the (optional) User linkage,
 * atomically allocate the employee number, persist the Employee,
 * audit, emit the domain event.
 */
class EmployeeService
{
    public function __construct(
        private readonly EmployeeNumberAllocator $allocator,
        private readonly EmployeeNumberFormatter $formatter,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array{full_name: string, user_id?: string|null}  $attributes
     */
    public function create(School $school, array $attributes, ?User $actor = null): Employee
    {
        $userId = $attributes['user_id'] ?? null;

        return $this->context->withSchool($school, function () use ($school, $attributes, $userId, $actor) {
            $this->assertUserLinkageIsSafe($school, $userId);

            return DB::transaction(function () use ($school, $attributes, $userId, $actor) {
                $sequenceValue = $this->allocator->allocate($school);

                $employee = Employee::query()->create([
                    'school_id' => $school->id,
                    'user_id' => $userId,
                    'employee_number' => $this->formatter->format($sequenceValue),
                    'full_name' => $attributes['full_name'],
                    'record_status' => 'active',
                ]);

                $this->audit->school($school, 'employee.created', actor: $actor, subject: $employee, metadata: [
                    'employeeNumber' => $employee->employee_number,
                ]);

                event(new EmployeeCreated($school->id, $employee->id, $employee->employee_number));

                return $employee;
            });
        });
    }

    /**
     * See App\Domain\HR\Application\Exceptions\UnrelatedUserLinkageException's
     * docblock for why this check exists and why it is intentionally
     * application-level, checked only at link-time.
     */
    private function assertUserLinkageIsSafe(School $school, ?string $userId): void
    {
        if ($userId === null) {
            return;
        }

        $hasMembership = SchoolMembership::query()
            ->where('user_id', $userId)
            ->where('school_id', $school->id)
            ->exists();

        if (! $hasMembership) {
            throw new UnrelatedUserLinkageException($userId, $school->id);
        }
    }
}
