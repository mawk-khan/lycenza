<?php

use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Statutory\Application\StatutoryPayrollPostingService;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for StatutoryPostingConcurrencyTest
// (Checkpoint 9.6H): run in a GENUINELY separate OS process (via
// Symfony\Process::start(), non-blocking) so real, independent PHP
// processes race StatutoryPayrollPostingService::post() against real
// PostgreSQL -- not a sequential simulation. Mirrors
// post-payroll-run.php's identical pattern.
//
// Usage: php post-statutory-payroll-run.php <schoolId> <runId> <actorUserId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $runId, $actorUserId] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $run = PayrollRun::query()->findOrFail($runId);
    $actor = User::query()->findOrFail($actorUserId);
    $posting = $app->make(StatutoryPayrollPostingService::class)->post($run, $actor);
    echo 'posted:'.$posting->journal_entry_id;
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}
