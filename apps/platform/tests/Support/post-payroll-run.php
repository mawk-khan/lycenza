<?php

use App\Domain\Payroll\Application\PayrollPostingService;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for PayrollPostingConcurrencyTest: run in
// a GENUINELY separate OS process (via Symfony\Process::start(),
// non-blocking) so real, independent PHP processes race
// PayrollPostingService::post() against real PostgreSQL -- not a
// sequential simulation. Mirrors calculate-payroll-run.php's identical
// pattern.
//
// Usage: php post-payroll-run.php <schoolId> <runId> <actorUserId>

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
    $posting = $app->make(PayrollPostingService::class)->post($run, $actor);
    echo 'posted:'.$posting->journal_entry_id;
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}
