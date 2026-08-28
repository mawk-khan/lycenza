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
// InventoryStockService::transfer() against real PostgreSQL -- not a
// sequential simulation. Used for the opposing-transfer proof
// (A->B and B->A concurrently) -- proves the deterministic
// ascending-balance-id lock order is genuinely deadlock-free
// (docs/modules/INVENTORY.md "Transfer / lock ordering").
//
// Usage: php transfer-inventory-stock.php <schoolId> <itemId> <fromLocationId> <toLocationId> <quantity>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $itemId, $fromLocationId, $toLocationId, $quantity] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $item = InventoryItem::query()->findOrFail($itemId);
    $from = InventoryLocation::query()->findOrFail($fromLocationId);
    $to = InventoryLocation::query()->findOrFail($toLocationId);
    $movement = $app->make(InventoryStockService::class)->transfer($item, $from, $to, $quantity);
    echo 'transferred:'.$movement->id;
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}
