<?php

use App\Domain\Fees\Application\ChargeService;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
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
// Usage: php cancel-charge.php <schoolId> <chargeId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $chargeId] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $app->make(ChargeService::class)->cancel($school, $chargeId);
    echo 'cancelled';
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}
