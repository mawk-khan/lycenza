<?php

use App\Domain\Leave\Application\Retention\LeaveEvidenceRetentionService;
use App\Domain\StaffAttendance\Application\Retention\StaffAttendanceEvidenceRetentionService;
use App\Domain\StaffAttendance\Application\StaffAttendanceService;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for HrxRetentionConcurrencyTest (HRX.6):
// one operation in a GENUINELY separate OS process.
//
// Usage:
//   php hrx-retention-op.php prune   <schoolId>                              (Leave, then Staff Attendance, as the command does)
//   php hrx-retention-op.php correct <schoolId> <recordId> <version> <userId> (a late attendance correction)

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$operation = array_shift($args);
$context = $app->make(TenantContext::class);

try {
    $school = School::query()->findOrFail($args[0]);
    echo $context->withSchool($school, fn () => HeldTransaction::run(function () use ($app, $operation, $args, $school): string {
        return match ($operation) {
            'prune' => (function () use ($app, $school): string {
                $cutoff = CarbonImmutable::now($school->timezone)->subYearsNoOverflow(8)->toDateString();
                $leave = $app->make(LeaveEvidenceRetentionService::class)->prune($school, $cutoff, 100, false);
                $attendance = $app->make(StaffAttendanceEvidenceRetentionService::class)->prune($school, $cutoff, 100, false);

                return "deleted:{$leave['deleted']}/{$attendance['deleted']} blocked:{$leave['dependency_blocked']}/{$attendance['dependency_blocked']} errors:".($leave['errors'] + $attendance['errors']);
            })(),
            'correct' => (function () use ($app, $school, $args): string {
                $app->make(StaffAttendanceService::class)->correct($school, $args[1], (int) $args[2], 'present', 'present', 'late_information', User::query()->findOrFail($args[3]));

                return 'corrected';
            })(),
            default => throw new InvalidArgumentException("unknown operation {$operation}"),
        };
    }));
} catch (Throwable $e) {
    echo 'rejected:'.class_basename($e);
}
