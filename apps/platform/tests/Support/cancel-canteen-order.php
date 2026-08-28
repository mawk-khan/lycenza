<?php

use App\Domain\Canteen\Application\CanteenOrderService;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for the Canteen concurrency tests -- see
// fulfill-canteen-order.php's own docblock for the exact rationale.
//
// Usage: php cancel-canteen-order.php <schoolId> <orderId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $orderId] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $app->make(CanteenOrderService::class)->cancel($school, $orderId);

    echo 'cancelled';
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}
