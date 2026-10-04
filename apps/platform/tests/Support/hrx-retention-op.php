<?php

use App\Domain\Leave\Application\Retention\LeaveEvidenceRetentionService;
use App\Domain\StaffAttendance\Application\Retention\StaffAttendanceEvidenceRetentionService;
use App\Domain\StaffAttendance\Application\StaffAttendanceService;
use App\Models\School;
use App\Models\User;
use App\Support\Retention\RetentionExpiry;
use App\Support\Retention\RetentionHolds;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for HrxRetentionConcurrencyTest (HRX.6):
// one operation in a GENUINELY separate OS process.
//
// Usage:
//   php hrx-retention-op.php prune   <schoolId>                              (Leave, then Staff Attendance, as the command does)
//   php hrx-retention-op.php correct <schoolId> <recordId> <version> <userId> (a late attendance correction)
//   php hrx-retention-op.php place-platform   <schoolId>  (E21-RH.3: place the platform hold, maintenance connection)
//   php hrx-retention-op.php release-platform <schoolId>  (E21-RH.3: release it)

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$operation = array_shift($args);
$context = $app->make(TenantContext::class);

try {
    $school = School::query()->findOrFail($args[0]);
    $held = fn (callable $operation): string => $context->withSchool($school, fn () => HeldTransaction::run($operation));
    echo match ($operation) {
        // The purge runs as the retention identity (the migration/owner connection), so its held
        // transaction is on that connection too; the late correction is an ordinary runtime write.
        'prune' => DB::usingConnection(RetentionExpiry::PRIVILEGED_CONNECTION, fn () => $held(function () use ($app, $school): string {
            $cutoff = CarbonImmutable::now($school->timezone)->subYearsNoOverflow(8)->toDateString();
            $leave = $app->make(LeaveEvidenceRetentionService::class)->prune($school, $cutoff, 100, false);
            $attendance = $app->make(StaffAttendanceEvidenceRetentionService::class)->prune($school, $cutoff, 100, false);

            return "deleted:{$leave['deleted']}/{$attendance['deleted']} blocked:{$leave['dependency_blocked']}/{$attendance['dependency_blocked']} errors:".($leave['errors'] + $attendance['errors']);
        })),
        // E21-RH.3: the operator's hold changes run on the maintenance connection, held there.
        'place-platform' => DB::usingConnection(RetentionHolds::MAINTENANCE_CONNECTION, fn () => $held(function () use ($app): string {
            return $app->make(RetentionHolds::class)->place(null, 'regulatory_inquiry', 'RACE-1')['created'] ? 'placed:created' : 'placed:existing';
        })),
        'release-platform' => DB::usingConnection(RetentionHolds::MAINTENANCE_CONNECTION, fn () => $held(function () use ($app): string {
            $app->make(RetentionHolds::class)->release(null, 'inquiry_closed', 'RACE-1');

            return 'released';
        })),
        'correct' => $held(function () use ($app, $school, $args): string {
            $app->make(StaffAttendanceService::class)->correct($school, $args[1], (int) $args[2], 'present', 'present', 'late_information', User::query()->findOrFail($args[3]));

            return 'corrected';
        }),
        default => throw new InvalidArgumentException("unknown operation {$operation}"),
    };
} catch (Throwable $e) {
    echo 'rejected:'.class_basename($e);
}
