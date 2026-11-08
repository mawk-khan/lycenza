<?php

use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\StaffAttendance\Application\Exceptions\StaffAttendanceException;
use App\Domain\StaffAttendance\Application\StaffAttendanceService;
use App\Models\School;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
//   php staff-attendance-op.php lock     <schoolId> <YYYY-MM> <actorId>              (HR.R1-C18)
//   php staff-attendance-op.php approve  <schoolId> <lockedCorrectionId> <actorId>   (HR.R1-C18)
//   php staff-attendance-op.php raw-record-rr <schoolId> <employmentId> <employeeId> <date> <actorId>  (REPEATABLE READ, must be refused)
//   php staff-attendance-op.php raw-record <schoolId> <employmentId> <employeeId> <date> <actorId>  (runtime role, no service)

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$operation = array_shift($args);
$half = fn (string $value): ?string => $value === '-' ? null : $value;

try {
    $school = School::query()->findOrFail($args[0]);
    $service = $app->make(StaffAttendanceService::class);
    echo $operation === 'raw-record-rr' ? $app->make(TenantContext::class)->withSchool($school, fn () => match ($operation) {
        'raw-record-rr' => 'ok:'.DB::transaction(function () use ($school, $args): string {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            DB::table('staff_attendance_records')->insert([
                'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'employment_record_id' => $args[1], 'employee_id' => $args[2],
                'attendance_date' => $args[3], 'first_half_status' => 'present', 'recorded_by_user_id' => $args[4], 'created_at' => now(), 'updated_at' => now(),
            ]);

            return 'inserted';
        }),
    }) : $app->make(TenantContext::class)->withSchool($school, fn () => HeldTransaction::run(fn (): string => match ($operation) {
        'record' => 'ok:'.$service->record($school, $args[1], $args[2], $half($args[3]), $half($args[4]), User::query()->findOrFail($args[5]))->version,
        'register' => 'ok:'.count($service->recordRegister($school, $args[1], array_map(function (string $item) use ($half) {
            [$employment, $first, $second] = explode(':', $item);

            return ['employment_record_id' => $employment, 'first_half' => $half($first), 'second_half' => $half($second)];
        }, array_slice($args, 3)), User::query()->findOrFail($args[2]))),
        'lock' => 'ok:'.$service->lockMonth($school, $args[1], User::query()->findOrFail($args[2]), new FreshMfaProof($args[2], CarbonImmutable::now()))->id,
        'approve' => 'ok:'.$service->approveLockedCorrection($school, $args[1], User::query()->findOrFail($args[2]), new FreshMfaProof($args[2], CarbonImmutable::now()))->status,
        'raw-record' => 'ok:'.(DB::table('staff_attendance_records')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'employment_record_id' => $args[1], 'employee_id' => $args[2],
            'attendance_date' => $args[3], 'first_half_status' => 'present', 'recorded_by_user_id' => $args[4], 'created_at' => now(), 'updated_at' => now(),
        ]) ? 'inserted' : 'none'),
        'correct' => 'ok:'.$service->correct($school, $args[1], (int) $args[2], $half($args[3]), $half($args[4]), 'late_information', User::query()->findOrFail($args[5]))->version,
        default => throw new InvalidArgumentException("unknown operation {$operation}"),
    }));
} catch (StaffAttendanceException|LeaveException $e) {
    echo 'rejected:'.$e->errorCode();
} catch (QueryException $e) {
    echo 'rejected:'.(str_contains($e->getMessage(), 'staff_attendance_month_locked') ? 'staff_attendance_month_locked' : (str_contains($e->getMessage(), 'staff_attendance_isolation') ? 'staff_attendance_isolation' : class_basename($e)));
} catch (Throwable $e) {
    echo 'rejected:'.class_basename($e);
}
