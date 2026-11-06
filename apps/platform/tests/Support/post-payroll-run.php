<?php

use App\Domain\Payroll\Application\PayrollPostingService;
use App\Domain\Payroll\Application\PostingAttestation;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Models\School;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Concurrency\HeldTransaction;

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

// HR.R1-C4: an optional 4th argument is the evidence version the poster attests to.
[, $schoolId, $runId, $actorUserId, $attestationVersion] = array_pad($argv, 5, null);

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $run = PayrollRun::query()->findOrFail($runId);
    $actor = User::query()->findOrFail($actorUserId);
    $attestation = $attestationVersion === null ? null : new PostingAttestation($attestationVersion, true);
    // HR.R1-C4: HeldTransaction lets a race hold this posting uncommitted (no-op otherwise).
    $posting = HeldTransaction::run(fn () => $app->make(PayrollPostingService::class)->post($run, $actor, new FreshMfaProof($actor->id, CarbonImmutable::now()), null, $attestation));
    echo 'posted:'.$posting->journal_entry_id;
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}
