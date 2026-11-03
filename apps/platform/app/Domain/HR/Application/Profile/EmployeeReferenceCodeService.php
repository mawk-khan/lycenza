<?php

namespace App\Domain\HR\Application\Profile;

use App\Domain\HR\Application\EmployeePlacementLock;
use App\Domain\HR\Application\Exceptions\EmployeeReferenceCodeConflictException;
use App\Domain\HR\Application\HrEmployeeAccess;
use App\Domain\HR\Application\HrSelfAdministrationGuard;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeReferenceCode;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * HR.2A (D1-03): the only writer of `employee_reference_codes`.
 *
 *   record()  a new live code of a closed kind; the value is normalized
 *             (trimmed, upper-case) and must match `[A-Z0-9][A-Z0-9/._-]*`,
 *             at most 64 characters
 *   retire()  final, with a closed reason; the value stays reserved
 *
 * Uniqueness is the database's (`employee_reference_codes_value_unique`,
 * `employee_reference_codes_one_live_per_kind`), never a check-then-insert:
 * a concurrent duplicate fails on the key and is translated here. Whole-School
 * `hr.employees.manage` (a School-wide identity reference; declared in
 * CampusScopeCatalogue::WHOLE_SCHOOL_OPERATIONS); never one's own record
 * (rule 95). Audit carries ids and the kind, never the value.
 */
final class EmployeeReferenceCodeService
{
    private const CAPABILITY = 'hr.employees.manage';

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly HrSelfAdministrationGuard $selfAdministration,
        private readonly HrEmployeeAccess $access,
        private readonly EmployeePlacementLock $placement,
    ) {}

    public static function normalize(string $value): string
    {
        return mb_strtoupper(trim($value));
    }

    public function record(Employee $employee, EmployeeReferenceKind $kind, string $value, User $actor): EmployeeReferenceCode
    {
        $school = $employee->school;
        $this->access->requireWholeSchool($actor, self::CAPABILITY, $school);
        $value = self::normalize($value);
        if (preg_match('/^[A-Z0-9][A-Z0-9\/._-]{0,63}$/', $value) !== 1) {
            throw new EmployeeReferenceCodeConflictException('format', 'A reference code is 1-64 letters, digits or / . _ - and starts with a letter or digit.');
        }

        return $this->context->withSchool($school, fn (): EmployeeReferenceCode => DB::transaction(function () use ($school, $employee, $kind, $value, $actor): EmployeeReferenceCode {
            $this->placement->forEmployeeWrite($school, $employee->id);
            $this->selfAdministration->refuseOwnEmployee($actor, $employee->id, 'profile.reference_code');

            $code = new EmployeeReferenceCode;
            try {
                DB::transaction(fn () => $code->forceFill([
                    'school_id' => $school->id,
                    'employee_id' => $employee->id,
                    'kind' => $kind->value,
                    'value' => $value,
                    'recorded_by_user_id' => $actor->id,
                ])->save());
            } catch (QueryException $e) {
                if ($e->getCode() === '23505' && str_contains($e->getMessage(), 'employee_reference_codes_value_unique')) {
                    throw new EmployeeReferenceCodeConflictException('value_taken', 'This '.mb_strtolower($kind->label()).' is already used in this School (codes are never reused, even after retirement).');
                }
                if ($e->getCode() === '23505' && str_contains($e->getMessage(), 'employee_reference_codes_one_live_per_kind')) {
                    throw new EmployeeReferenceCodeConflictException('kind_taken', 'This Employee already has a '.mb_strtolower($kind->label()).'; retire it first.');
                }
                throw $e;
            }

            $this->audit->school($school, 'hr.reference_code.recorded', actor: $actor, subject: $code, metadata: [
                'employeeId' => $employee->id,
                'referenceCodeId' => $code->id,
                'kind' => $kind->value,
            ]);

            return $code;
        }));
    }

    public function retire(Employee $employee, EmployeeReferenceCode $code, ReferenceCodeRetireReason $reason, User $actor): EmployeeReferenceCode
    {
        $school = $employee->school;
        $this->access->requireWholeSchool($actor, self::CAPABILITY, $school);

        return $this->context->withSchool($school, fn (): EmployeeReferenceCode => DB::transaction(function () use ($school, $employee, $code, $reason, $actor): EmployeeReferenceCode {
            $this->placement->forEmployeeWrite($school, $employee->id);
            $this->selfAdministration->refuseOwnEmployee($actor, $employee->id, 'profile.reference_code');
            $locked = EmployeeReferenceCode::query()->where('school_id', $school->id)->where('employee_id', $employee->id)
                ->whereKey($code->id)->lockForUpdate()->firstOrFail();
            if ($locked->retired_at !== null) {
                throw new EmployeeReferenceCodeConflictException('retired', 'This reference code is already retired.');
            }
            $locked->forceFill(['retired_at' => now(), 'retired_by_user_id' => $actor->id, 'retire_reason' => $reason->value])->save();

            $this->audit->school($school, 'hr.reference_code.retired', actor: $actor, subject: $locked, metadata: [
                'employeeId' => $employee->id,
                'referenceCodeId' => $locked->id,
                'kind' => $locked->kind,
                'reason' => $reason->value,
            ]);

            return $locked;
        }));
    }
}
