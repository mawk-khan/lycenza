<?php

use App\Domain\HR\Application\EmployeeLifecycleService;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\Exceptions\HrException;
use App\Domain\HR\Application\Exceptions\InvalidEmploymentTermsException;
use App\Domain\HR\Infrastructure\Employee;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Concurrency\HeldTransaction;

// HR.R1-C9 (HRC-12): one archive, restore or rehire in a GENUINELY separate OS
// process, the holder held open inside HeldTransaction.
//
// Usage:
//   php archived-identity-op.php <archive|restore|rehire|raw-employment> <schoolId> <employeeId> <actorId>
//   php archived-identity-op.php placement-primary <schoolId> <assignmentId> <0|1>
//   php archived-identity-op.php suspend <schoolId>
//   php archived-identity-op.php leave-type-unpaid <schoolId> <leaveTypeId>
// Prints "ok:<result>" or "rejected:<reason, SQLSTATE or class>".

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$operation = $argv[1];
$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($argv[2]);
$context->set($school);

try {
    echo 'ok:'.HeldTransaction::run(function () use ($app, $operation, $argv, $school): string {
        if ($operation === 'suspend') {
            return (string) DB::table('schools')->where('id', $school->id)->update(['status' => 'suspended']);
        }
        if ($operation === 'leave-type-unpaid') {
            return (string) DB::table('leave_types')->where('id', $argv[3])->update(['is_paid' => false]);
        }
        if ($operation === 'placement-primary') {
            return (string) DB::table('employee_assignments')->where('id', $argv[3])->update(['is_primary' => $argv[4] === '1']);
        }
        $employee = Employee::query()->findOrFail($argv[3]);
        $actor = User::query()->findOrFail($argv[4] ?? null);

        return match ($operation) {
            'archive' => $app->make(EmployeeService::class)->archive($employee, $actor)->record_status,
            'restore' => $app->make(EmployeeService::class)->restore($employee, $actor)->record_status,
            'rehire' => $app->make(EmployeeLifecycleService::class)->rehire($employee, ['employment_type' => 'permanent', 'starts_on' => '2033-01-01'], $actor)->status,
            'raw-employment' => (string) DB::table('employment_records')->insert([
                'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'employee_id' => $employee->id, 'employment_type' => 'permanent',
                'staff_type' => 'teaching', 'starts_on' => '2033-01-01', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]),
            default => throw new InvalidArgumentException("unknown operation {$operation}"),
        };
    });
} catch (InvalidEmploymentTermsException $e) {
    echo 'rejected:'.$e->reason;
} catch (HrException $e) {
    echo 'rejected:'.$e->errorCode();
} catch (QueryException $e) {
    echo 'rejected:'.$e->getCode();
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}
