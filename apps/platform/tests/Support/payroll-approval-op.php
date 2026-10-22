<?php

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\PayrollRunAdministrationService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for PayrollApprovalParticipationConcurrencyTest
// (ORG.8B, ADR 0076 §60): one payroll preparation step or approval, in a
// GENUINELY separate OS process (HeldTransaction holder or contender).
//
// Usage:
//   php payroll-approval-op.php calculate <schoolId> <runId> <userId>
//   php payroll-approval-op.php override <schoolId> <runId> <userId> <employmentRecordId> <componentId> <amount>
//   php payroll-approval-op.php approve <schoolId> <runId> <userId>
//   php payroll-approval-op.php approve-entry <schoolId> <runId> <userId>   (the authorized entry)

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $operation, $schoolId, $runId, $userId] = array_slice($argv, 0, 5);
$extra = array_slice($argv, 5);
$school = School::query()->findOrFail($schoolId);
$context = $app->make(TenantContext::class);
// Session-level for the whole process, so the School context holds at the real COMMIT.
$context->set($school);

try {
    echo HeldTransaction::run(function () use ($app, $operation, $runId, $userId, $extra): string {
        $runs = $app->make(PayrollRunService::class);
        $run = PayrollRun::query()->findOrFail($runId);
        $actor = User::query()->findOrFail($userId);

        return match ($operation) {
            'calculate' => $runs->calculate($run, $actor) ? 'calculated' : 'no',
            'override' => (function () use ($runs, $run, $actor, $extra): string {
                $runs->recordManualOverride($run, EmploymentRecord::query()->findOrFail($extra[0]), [$extra[1] => $extra[2]], 'race', $actor);

                return 'overridden';
            })(),
            'approve' => $runs->approve($run, $actor)->status,
            'approve-entry' => $app->make(PayrollRunAdministrationService::class)->approve($run, $actor)->status,
            default => throw new InvalidArgumentException("Unknown operation {$operation}"),
        };
    });
} catch (Throwable $e) {
    echo 'rejected:'.class_basename($e);
} finally {
    $context->clearAll();
}
