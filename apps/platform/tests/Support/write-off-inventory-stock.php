<?php

use App\Domain\Inventory\Application\InventoryStockService;
use App\Domain\Inventory\Infrastructure\InventoryItem;
use App\Domain\Inventory\Infrastructure\InventoryLocation;
use App\Models\School;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for InventoryWriteOffConcurrencyTest
// (ORG.9C-2, ADR 0076 §70): run in a GENUINELY separate OS process so a
// write-off races another stock write against real PostgreSQL. The fresh
// MFA proof stands for FreshMfaRequirement's (a test-only construction,
// as in the feature tests); the actor's authority is re-read in the service.
//
// Usage: php write-off-inventory-stock.php <schoolId> <itemId> <locationId> <quantity> <actorUserId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $itemId, $locationId, $quantity, $actorId] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $item = InventoryItem::query()->findOrFail($itemId);
    $location = InventoryLocation::query()->findOrFail($locationId);
    $actor = User::query()->findOrFail($actorId);
    $movement = $app->make(InventoryStockService::class)->writeOff($school, $item, $location, $quantity, 'damaged', $actor, new FreshMfaProof($actor->id, CarbonImmutable::now()));
    echo 'written_off:'.$movement->id;
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}
