<?php

use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\StaffAttendance\Application\Exceptions\StaffAttendanceException;
use App\Domain\StaffAttendance\Application\StaffAttendanceService;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for StaffAttendanceConcurrencyTest (HRX.3): one
// Staff Attendance write in a GENUINELY separate OS process. Leave approval
// and cancellation run through leave-op.php. A half is `present`, `absent`
// or `-` (no evidence).
//
// Usage:
//   php staff-attendance-op.php record   <schoolId> <employmentId> <date> <first> <second> <actorId>
//   php staff-attendance-op.php register <schoolId> <date> <actorId> <employmentId:first:second> [...]
//   php staff-attendance-op.php correct  <schoolId> <recordId> <expectedVersion> <first> <second> <actorId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$operation = array_shift($args);
$half = fn (string $value): ?string => $value === '-' ? null : $value;

try {
    $school = School::query()->findOrFail($args[0]);
    $service = $app->make(StaffAttendanceService::class);
    echo $app->make(TenantContext::class)->withSchool($school, fn () => HeldTransaction::run(fn (): string => match ($operation) {
        'record' => 'ok:'.$service->record($school, $args[1], $args[2], $half($args[3]), $half($args[4]), User::query()->findOrFail($args[5]))->version,
        'register' => 'ok:'.count($service->recordRegister($school, $args[1], array_map(function (string $item) use ($half) {
            [$employment, $first, $second] = explode(':', $item);

            return ['employment_record_id' => $employment, 'first_half' => $half($first), 'second_half' => $half($second)];
        }, array_slice($args, 3)), User::query()->findOrFail($args[2]))),
        'correct' => 'ok:'.$service->correct($school, $args[1], (int) $args[2], $half($args[3]), $half($args[4]), 'late_information', User::query()->findOrFail($args[5]))->version,
        default => throw new InvalidArgumentException("unknown operation {$operation}"),
    }));
} catch (StaffAttendanceException|LeaveException $e) {
    echo 'rejected:'.$e->errorCode();
} catch (Throwable $e) {
    echo 'rejected:'.class_basename($e);
}
