<?php

use App\Domain\Canteen\Application\CanteenRecipeService;
use App\Domain\Canteen\Infrastructure\CanteenItem;
use App\Domain\Inventory\Infrastructure\InventoryItem;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for the Canteen concurrency tests -- see
// fulfill-canteen-order.php's own docblock for the exact rationale.
// Proves CanteenRecipeService::setRequirement()'s own CanteenItem row
// lock genuinely serializes against a concurrent fulfillment reading
// that same Item's recipe.
//
// Usage: php set-canteen-recipe-requirement.php <schoolId> <canteenItemId> <inventoryItemId> <quantityRequired>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $canteenItemId, $inventoryItemId, $quantityRequired] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $item = CanteenItem::query()->findOrFail($canteenItemId);
    $inventoryItem = InventoryItem::query()->findOrFail($inventoryItemId);

    $app->make(CanteenRecipeService::class)->setRequirement($school, $item, $inventoryItem, $quantityRequired);

    echo 'set';
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}
