<?php

use App\Domain\Canteen\Infrastructure\CanteenOrder;
use App\Domain\Canteen\Infrastructure\CanteenOutlet;
use App\Domain\Inventory\Application\InventoryLocationService;
use App\Domain\Inventory\Application\InventoryStockService;
use App\Domain\Inventory\Application\IssueRequirement;
use App\Domain\Inventory\Infrastructure\InventoryItem;
use App\Domain\Inventory\Infrastructure\InventoryLocation;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for InventoryLocationCampusConcurrencyTest
// (ORG.7H-1, ADR 0076 §54): one first operational use of a Location, or one
// change of its Campus, in a GENUINELY separate OS process (HeldTransaction
// holder or contender). A stock write resolves its Location BEFORE any lock,
// exactly like the controllers (the Campus it was authorized under).
//
// Usage:
//   php inventory-location-op.php receive <schoolId> <itemId> <locationId> <quantity>
//   php inventory-location-op.php issue-many <schoolId> <itemId> <locationId> <quantity>
//   php inventory-location-op.php order <schoolId> <studentId> <outletId>
//   php inventory-location-op.php edit <schoolId> <adminUserId> <locationId> <campusId|null|name:...>
//   php inventory-location-op.php raw-campus <schoolId> <locationId> <campusId|null>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$operation = array_shift($args);
$school = School::query()->findOrFail(array_shift($args));
$context = $app->make(TenantContext::class);
$nullable = fn (string $value): ?string => $value === 'null' ? null : $value;

try {
    echo HeldTransaction::run(fn () => $context->withSchool($school, function () use ($app, $operation, $args, $school, $nullable): string {
        switch ($operation) {
            case 'receive':
                [$itemId, $locationId, $quantity] = $args;
                $app->make(InventoryStockService::class)->receive(InventoryItem::query()->findOrFail($itemId), InventoryLocation::query()->findOrFail($locationId), $quantity);

                return 'ok';
            case 'issue-many':
                [$itemId, $locationId, $quantity] = $args;
                $app->make(InventoryStockService::class)->issueMany(InventoryLocation::query()->findOrFail($locationId), [new IssueRequirement($itemId, $quantity)]);

                return 'ok';
            case 'order':
                [$studentId, $outletId] = $args;
                $outlet = CanteenOutlet::query()->findOrFail($outletId);
                CanteenOrder::factory()->create(['school_id' => $school->id, 'student_id' => $studentId, 'outlet_id' => $outlet->id, 'inventory_location_id' => $outlet->inventory_location_id]);

                return 'ok';
            case 'edit':
                [$adminId, $locationId, $change] = $args;
                $data = str_starts_with($change, 'name:') ? ['name' => substr($change, 5)] : ['campus_id' => $nullable($change)];
                $app->make(InventoryLocationService::class)->update($school, $locationId, $data, User::query()->findOrFail($adminId));

                return 'ok';
            case 'raw-campus':
                [$locationId, $campusId] = $args;
                try {
                    DB::update('update inventory_locations set campus_id = ? where id = ?', [$nullable($campusId), $locationId]);
                } catch (QueryException $e) {
                    throw str_contains($e->getMessage(), 'inventory_location_campus_frozen') ? new RuntimeException('frozen') : $e;
                }

                return 'ok';
            default:
                throw new InvalidArgumentException("Unknown operation {$operation}");
        }
    }));
} catch (Throwable $e) {
    echo 'rejected:'.($e->getMessage() === 'frozen' ? 'frozen' : class_basename($e));
} finally {
    $context->clearAll();
}
