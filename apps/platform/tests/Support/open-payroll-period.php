<?php

use App\Domain\Payroll\Application\PayrollPeriodService;
use App\Domain\Payroll\Infrastructure\PayrollPeriod;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for PayrollPeriodConcurrencyTest: run in
// a GENUINELY separate OS process (via Symfony\Process::start(),
// non-blocking) so real, independent PHP processes race
// PayrollPeriodService::open() against real PostgreSQL -- not a
// sequential simulation. Mirrors approve-payroll-run.php's identical
// pattern.
//
// Usage: php open-payroll-period.php <schoolId> <periodId> <actorUserId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $periodId, $actorUserId] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $period = PayrollPeriod::query()->findOrFail($periodId);
    $actor = User::query()->findOrFail($actorUserId);
    $app->make(PayrollPeriodService::class)->open($period, $actor);
    echo 'opened';
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}
