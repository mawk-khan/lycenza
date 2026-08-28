<?php

use App\Domain\Canteen\Application\CanteenOrderService;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for the Canteen concurrency tests: run in
// a GENUINELY separate OS process (via Symfony\Process::start(),
// non-blocking) so two real, independent PHP processes race
// CanteenOrderService::fulfill() against real PostgreSQL -- not a
// sequential simulation. Mirrors
// tests/Support/issue-many-inventory-stock.php's identical pattern.
//
// Usage: php fulfill-canteen-order.php <schoolId> <orderId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $orderId] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $result = $app->make(CanteenOrderService::class)->fulfill($school, $orderId);

    echo 'fulfilled:'.$result->chargeId;
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}
