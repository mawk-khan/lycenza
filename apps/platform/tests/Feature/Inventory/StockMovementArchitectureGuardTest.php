<?php

namespace Tests\Feature\Inventory;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 10E (docs/modules/INVENTORY.md "Movement immutability limits").
 * `stock_movements` is append-only in APPLICATION semantics -- there is
 * no update/delete endpoint anywhere, and no service method mutates a
 * posted row after insert. This does NOT claim PostgreSQL itself
 * enforces immutability (unlike Finance's journal-line-set trigger
 * enforcement) -- the repository deliberately avoided introducing a new
 * trigger-function framework solely for this checkpoint, given the
 * known shared-test-reset persistence issue with raw-SQL trigger
 * functions (docs/modules/INVENTORY.md "Movement immutability limits").
 * Reversal/correction is explicitly deferred. Mirrors
 * FinanceHttpArchitectureGuardTest's grep/route-inspection pattern.
 */
class StockMovementArchitectureGuardTest extends TestCase
{
    #[Test]
    public function no_movement_mutation_route_exists_anywhere_in_the_api(): void
    {
        $routes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'inventory-stock/movements'))
            ->filter(fn ($route) => in_array('PATCH', $route->methods(), true) || in_array('PUT', $route->methods(), true) || in_array('DELETE', $route->methods(), true));

        $this->assertCount(0, $routes, 'No PATCH/PUT/DELETE route may exist under inventory-stock/movements -- StockMovement is append-only.');
    }

    #[Test]
    public function no_generic_movement_creation_route_accepting_an_arbitrary_type_exists(): void
    {
        $routes = collect(Route::getRoutes())
            ->filter(fn ($route) => $route->uri() === 'api/v1/schools/{school}/stock-movements' || str_contains($route->uri(), 'stock-movements'));

        $this->assertCount(0, $routes, 'No generic "create a stock movement" endpoint may exist -- only the explicit receive/issue/transfer commands.');
    }

    #[Test]
    public function the_stock_movement_model_exposes_no_update_or_delete_application_path(): void
    {
        $source = file_get_contents(base_path('app/Domain/Inventory/Infrastructure/StockMovement.php'));

        foreach (['function update', 'function delete', 'SoftDeletes'] as $needle) {
            $this->assertStringNotContainsString($needle, $source, "StockMovement.php must never define {$needle} -- no application mutation path may exist.");
        }
    }

    #[Test]
    public function the_inventory_stock_service_never_updates_or_deletes_a_movement(): void
    {
        $source = file_get_contents(base_path('app/Domain/Inventory/Application/InventoryStockService.php'));

        $this->assertStringNotContainsString('StockMovement::query()->where', $source, 'InventoryStockService must only ever create() a StockMovement, never query-then-update/delete one.');

        foreach (['$movement->update(', '$movement->delete(', '$movement->save('] as $needle) {
            $this->assertStringNotContainsString($needle, $source, "InventoryStockService must never mutate a created StockMovement ({$needle}).");
        }
    }
}
