<?php

use App\Domain\Fees\Application\ChargeCancellationReason;
use App\Domain\Fees\Application\ChargeService;
use App\Models\School;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for ChargeConcurrencyTest: run in a
// GENUINELY separate OS process (via Symfony\Process::start(),
// non-blocking) so two real, independent PHP processes race to call
// App\Domain\Fees\Application\ChargeService::cancel() for the SAME
// charge against real PostgreSQL -- not a sequential simulation.
// Mirrors reverse-journal-entry.php's identical pattern. The real
// concurrency guarantee is still journal_entries_reversal_of_unique
// (ADR 0030), reached via LedgerService::reverseById() -- this proves
// it holds through the real Fees code path.
//
// Usage: php cancel-charge.php <schoolId> <chargeId> <actorUserId>   (ORG.8G: a finance.charges.manage member)

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $chargeId, $actorId] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $actor = User::query()->findOrFail($actorId);
    $app->make(ChargeService::class)->cancel($school, $chargeId, $actor, ChargeCancellationReason::CANCEL, 'charged_in_error', null, new FreshMfaProof($actor->id, CarbonImmutable::now()));
    echo 'cancelled';
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}
