<?php

use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency\HeldTransaction;

// HR.1A: a RAW workforce write (no service, no application lock) run in a
// genuinely separate OS process, to prove the DATABASE backstops serialize
// two concurrent writers by themselves (trigger + advisory lock).
//
// Usage:
//   php hr-workforce-raw-write.php <schoolId> employment <employeeId> <startsOn>
//   php hr-workforce-raw-write.php <schoolId> primary <employmentRecordId> <positionId> <startsOn>
//   php hr-workforce-raw-write.php <schoolId> reparent <departmentId> <parentDepartmentId>
// Prints "ok:<id>" or "rejected:<SQLSTATE>".

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $schoolId, $kind] = $argv;
$context = $app->make(TenantContext::class);
$context->set(School::query()->findOrFail($schoolId));

try {
    $id = HeldTransaction::run(function () use ($kind, $argv, $schoolId): string {
        return match ($kind) {
            'employment' => EmploymentRecord::query()->forceCreate([
                'school_id' => $schoolId, 'employee_id' => $argv[3], 'employment_type' => 'permanent',
                'staff_type' => 'teaching', 'starts_on' => $argv[4], 'ends_on' => null, 'status' => 'active',
            ])->id,
            'primary' => EmployeeAssignment::query()->create([
                'school_id' => $schoolId, 'employment_record_id' => $argv[3], 'position_id' => $argv[4],
                'is_primary' => true, 'starts_on' => $argv[5], 'ends_on' => null,
            ])->id,
            'reparent' => (string) DB::table('hr_departments')->where('id', $argv[3])->update(['parent_department_id' => $argv[4]]),
            default => throw new InvalidArgumentException("unknown kind {$kind}"),
        };
    });
    echo 'ok:'.$id;
} catch (QueryException $e) {
    echo 'rejected:'.$e->getCode();
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}
