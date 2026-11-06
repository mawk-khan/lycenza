<?php

use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Models\School;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for PayrollRunLifecycleConcurrencyTest:
// run in a GENUINELY separate OS process (via Symfony\Process::start(),
// non-blocking) so real, independent PHP processes race
// PayrollRunService::approve() against real PostgreSQL -- not a
// sequential simulation. Mirrors activate-academic-year.php's
// identical pattern.
//
// Usage: php approve-payroll-run.php <schoolId> <runId> <approverUserId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

// OD-HR-1: an optional 4th argument names the calculation the approver REVIEWED; without it the
// approver names the latest one at start-up.
[, $schoolId, $runId, $approverUserId, $reviewed, $attribution] = array_pad($argv, 6, null);

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $run = PayrollRun::query()->findOrFail($runId);
    $approver = User::query()->findOrFail($approverUserId);
    $reviewed ??= $app->make(PayrollRunService::class)->latestCalculationId($run);
    // HR.R1-C3: likewise the first-paid attribution, read here, before any lock (the packet the approver saw).
    $attribution ??= $app->make(PayrollRunService::class)->latestAttributionVersion($run);
    HeldTransaction::run(fn () => $app->make(PayrollRunService::class)->approve($run, $approver, new FreshMfaProof($approver->id, CarbonImmutable::now()), $reviewed, $attribution));
    echo 'approved';
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}
