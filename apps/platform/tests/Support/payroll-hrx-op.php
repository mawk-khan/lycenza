<?php

use App\Domain\Payroll\Application\PayrollPostingAdministrationService;
use App\Domain\Payroll\Application\PayrollRunAdministrationService;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for PayrollHrxConcurrencyTest (HRX.5): one
// Payroll operation in a GENUINELY separate OS process. Leave and Staff
// Attendance operations run through leave-op.php / staff-attendance-op.php.
// `calculate` prints the captured fingerprint of the given employment.
//
// Usage:
//   php payroll-hrx-op.php calculate <schoolId> <runId> <actorId> <employmentRecordId>
//   php payroll-hrx-op.php post      <schoolId> <runId> <actorId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$operation = array_shift($args);

try {
    $school = School::query()->findOrFail($args[0]);
    echo $app->make(TenantContext::class)->withSchool($school, fn () => HeldTransaction::run(function () use ($app, $operation, $args): string {
        $run = PayrollRun::query()->findOrFail($args[1]);
        $actor = User::query()->findOrFail($args[2]);

        return match ($operation) {
            'calculate' => (function () use ($app, $run, $actor, $args) {
                $app->make(PayrollRunAdministrationService::class)->calculate($run, $actor);

                return 'ok:'.DB::table('payroll_run_hrx_inputs')->where('payroll_run_id', $run->id)->where('employment_record_id', $args[3])->value('fingerprint');
            })(),
            'post' => (function () use ($app, $run, $actor) {
                $app->make(PayrollPostingAdministrationService::class)->post($run, $actor);

                return 'ok:posted';
            })(),
            default => throw new InvalidArgumentException("unknown operation {$operation}"),
        };
    }));
} catch (Throwable $e) {
    echo 'rejected:'.(method_exists($e, 'errorCode') ? $e->errorCode() : class_basename($e));
}
