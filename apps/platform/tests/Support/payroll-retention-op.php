<?php

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\CorrectionDeltaInput;
use App\Domain\Payroll\Application\PayrollPostingService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Application\Retention\PayrollEvidenceRetentionService;
use App\Domain\Payroll\Infrastructure\PayrollPeriod;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Models\School;
use App\Models\User;
use App\Support\Retention\RetentionExpiry;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for PayrollRetentionConcurrencyTest (E21.3F):
// one operation in a GENUINELY separate OS process.
//
// Usage:
//   php payroll-retention-op.php payroll-prune <schoolId>              (evidence, then runs, as the command does)
//   php payroll-retention-op.php rehire        <schoolId> <employeeId>
//   php payroll-retention-op.php reverse       <schoolId> <runId> <userId>
//   php payroll-retention-op.php correct       <schoolId> <runId> <employmentRecordId> <componentId> <userId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$operation = array_shift($args);
$context = $app->make(TenantContext::class);

try {
    $school = School::query()->findOrFail($args[0]);
    // The School context wraps the held transaction: deferred checks (journal balance) run at COMMIT.
    $run = fn () => $context->withSchool($school, fn () => HeldTransaction::run(function () use ($app, $operation, $args, $context, $school): string {
        return match ($operation) {
            'payroll-prune' => (function () use ($app, $school): string {
                $payroll = $app->make(PayrollEvidenceRetentionService::class);
                $cutoff = CarbonImmutable::now($school->timezone)->subYearsNoOverflow(8)->startOfDay();
                $evidence = $payroll->pruneEvidence($school, $cutoff->toDateString(), 100, false);
                $runs = $payroll->pruneRuns($school, $cutoff, 100, false);

                return "deleted:{$evidence['deleted']}/{$runs['deleted']} errors:".($evidence['errors'] + $runs['errors']);
            })(),
            'rehire' => $context->withSchool($school, function () use ($school, $args): string {
                DB::table('employment_records')->insert([
                    'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'employee_id' => $args[1], 'employment_type' => 'permanent',
                    'starts_on' => '2026-01-01', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
                ]);

                return 'rehired';
            }),
            'reverse' => $context->withSchool($school, function () use ($app, $args): string {
                $app->make(PayrollPostingService::class)->reverse(PayrollRun::query()->findOrFail($args[1]), User::query()->findOrFail($args[2]), 'late');

                return 'reversed';
            }),
            'correct' => $context->withSchool($school, function () use ($app, $args): string {
                $original = PayrollRun::query()->findOrFail($args[1]);
                $actor = User::query()->findOrFail($args[4]);
                $runs = $app->make(PayrollRunService::class);
                $correction = $runs->createCorrectionRun($original, PayrollPeriod::query()->findOrFail($original->payroll_period_id), $actor);
                $runs->recordCorrectionDelta($correction, EmploymentRecord::query()->findOrFail($args[2]), [new CorrectionDeltaInput($args[3], '100.00', 'increase')], 'arrears', $actor);

                return 'corrected';
            }),
            default => throw new InvalidArgumentException("unknown operation {$operation}"),
        };
    }));
    // E21-RH.5: the purge runs as the retention identity, so its held transaction is on that connection
    // too (the unit transactions nest in it); the late writes are ordinary runtime writes.
    echo $operation === 'payroll-prune' ? DB::usingConnection(RetentionExpiry::PRIVILEGED_CONNECTION, $run) : $run();
} catch (Throwable $e) {
    echo 'rejected:'.class_basename($e);
}
