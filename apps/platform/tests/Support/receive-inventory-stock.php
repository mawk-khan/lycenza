<?php

use App\Domain\Inventory\Application\InventoryStockService;
use App\Domain\Inventory\Infrastructure\InventoryItem;
use App\Domain\Inventory\Infrastructure\InventoryLocation;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for InventoryStockConcurrencyTest: run in
// a GENUINELY separate OS process (via Symfony\Process::start(),
// non-blocking) so two real, independent PHP processes race
// InventoryStockService::receive() against real PostgreSQL -- not a
// sequential simulation. Mirrors assign-hostel-residency.php's
// identical pattern. Proves the concurrency-safe missing-balance-row
// primitive (docs/modules/INVENTORY.md "Balance creation primitive").
//
// Usage: php receive-inventory-stock.php <schoolId> <itemId> <locationId> <quantity>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $itemId, $locationId, $quantity] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $item = InventoryItem::query()->findOrFail($itemId);
    $location = InventoryLocation::query()->findOrFail($locationId);
    $movement = $app->make(InventoryStockService::class)->receive($item, $location, $quantity);
    echo 'received:'.$movement->id;
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}
