<?php

use App\Domain\Inventory\Application\InventoryStockService;
use App\Domain\Inventory\Application\IssueRequirement;
use App\Domain\Inventory\Infrastructure\InventoryLocation;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

// Standalone bootstrap script for InventoryStockConcurrencyTest: run in
// a GENUINELY separate OS process (via Symfony\Process::start(),
// non-blocking) so two real, independent PHP processes race
// InventoryStockService::issueMany() against real PostgreSQL -- not a
// sequential simulation. Mirrors issue-inventory-stock.php/
// transfer-inventory-stock.php's identical pattern. Proves that
// lockBalancesInDeterministicOrder() is genuinely deadlock-free when
// two concurrent issueMany() callers request the SAME two Items from
// the SAME Location but in OPPOSITE array order.
//
// Usage: php issue-many-inventory-stock.php <schoolId> <locationId> <itemIdA> <quantityA> <itemIdB> <quantityB>
// Issues item A then item B, in exactly that array order, from one
// issueMany() call against the given Location.

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

[, $schoolId, $locationId, $itemIdA, $quantityA, $itemIdB, $quantityB] = $argv;

$context = $app->make(TenantContext::class);
$school = School::query()->findOrFail($schoolId);
$context->set($school);

try {
    $location = InventoryLocation::query()->findOrFail($locationId);

    $movements = $app->make(InventoryStockService::class)->issueMany($location, [
        new IssueRequirement($itemIdA, $quantityA),
        new IssueRequirement($itemIdB, $quantityB),
    ]);

    echo 'issued:'.implode(',', array_map(fn ($m) => $m->id, $movements));
} catch (Throwable $e) {
    echo 'rejected:'.$e::class;
} finally {
    $context->clearAll();
}
