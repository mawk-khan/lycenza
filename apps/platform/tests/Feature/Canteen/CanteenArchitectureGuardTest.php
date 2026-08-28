<?php

namespace Tests\Feature\Canteen;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 10F -- structural/grep-based architecture guards, mirroring
 * Tests\Feature\Inventory\StockMovementArchitectureGuardTest's exact
 * pattern: `canteen_order_lines`/`canteen_order_stock_consumptions`
 * are append-only in APPLICATION semantics (no update/delete route
 * anywhere, no service method mutates a posted row after insert), and
 * no route/controller ever accepts a caller-supplied
 * `stock_movement_id` for consumption-linking -- every Consumption row
 * is built exclusively from the StockMovement objects
 * InventoryStockService::issueMany() itself returns.
 */
class CanteenArchitectureGuardTest extends TestCase
{
    #[Test]
    public function no_mutation_route_exists_for_order_lines(): void
    {
        $routes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'canteen-order') && str_contains($route->uri(), 'line'));

        $this->assertCount(0, $routes, 'No route may exist for canteen_order_lines at all -- CanteenOrderService::place() is the only writer.');
    }

    #[Test]
    public function no_mutation_route_exists_for_stock_consumptions(): void
    {
        $routes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'canteen-order') && (str_contains($route->uri(), 'consumption') || str_contains($route->uri(), 'stock-movement')));

        $this->assertCount(0, $routes, 'No route may exist for canteen_order_stock_consumptions at all -- CanteenOrderService::fulfill() is the only writer.');
    }

    #[Test]
    public function the_canteen_order_line_model_exposes_no_update_or_delete_application_path(): void
    {
        $source = file_get_contents(base_path('app/Domain/Canteen/Infrastructure/CanteenOrderLine.php'));

        foreach (['function update', 'function delete', 'SoftDeletes'] as $needle) {
            $this->assertStringNotContainsString($needle, $source, "CanteenOrderLine.php must never define {$needle}.");
        }
    }

    #[Test]
    public function the_canteen_order_stock_consumption_model_exposes_no_update_or_delete_application_path(): void
    {
        $source = file_get_contents(base_path('app/Domain/Canteen/Infrastructure/CanteenOrderStockConsumption.php'));

        foreach (['function update', 'function delete', 'SoftDeletes'] as $needle) {
            $this->assertStringNotContainsString($needle, $source, "CanteenOrderStockConsumption.php must never define {$needle}.");
        }
    }

    #[Test]
    public function canteen_order_service_never_mutates_a_line_or_consumption_after_insert(): void
    {
        // fulfill() legitimately READS CanteenOrderLine rows
        // (`CanteenOrderLine::query()->where(...)->get()`) to compute
        // the aggregated recipe requirement -- that is not a mutation.
        // What must never appear is an actual write to an
        // already-created line/consumption row.
        $source = file_get_contents(base_path('app/Domain/Canteen/Application/CanteenOrderService.php'));

        foreach ([
            'CanteenOrderLine::query()->update',
            'CanteenOrderLine::query()->delete',
            '$line->update(',
            '$line->delete(',
            '$line->save(',
            'CanteenOrderStockConsumption::query()->update',
            'CanteenOrderStockConsumption::query()->delete',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $source, "CanteenOrderService must only ever create() these rows, never mutate them ({$needle}).");
        }
    }

    #[Test]
    public function no_controller_accepts_a_caller_supplied_stock_movement_id(): void
    {
        $controllerFiles = glob(base_path('app/Domain/Canteen/Http/Controllers/*.php'));
        $controllerFiles = array_merge($controllerFiles, glob(base_path('app/Http/Controllers/App/Canteen/*.php')));

        foreach ($controllerFiles as $file) {
            $source = file_get_contents($file);
            $this->assertStringNotContainsString('stock_movement_id', $source, basename($file).' must never accept a caller-supplied stock_movement_id.');
        }
    }

    #[Test]
    public function canteen_order_service_builds_consumption_rows_only_from_issue_many_return_values(): void
    {
        $source = file_get_contents(base_path('app/Domain/Canteen/Application/CanteenOrderService.php'));

        // The only place 'stock_movement_id' is written must be inside
        // the `foreach ($movements as $movement)` loop iterating
        // issueMany()'s own return value -- never from $request/$data.
        $this->assertStringContainsString("'stock_movement_id' => \$movement->id,", $source);
    }

    #[Test]
    public function canteen_order_service_is_the_only_domain_writer_of_canteen_orders(): void
    {
        $files = $this->allPhpFilesUnder(base_path('app/Domain/Canteen'));
        foreach ($files as $file) {
            if (str_ends_with($file, 'CanteenOrderService.php') || str_ends_with($file, 'CanteenOrder.php')) {
                continue;
            }

            $source = file_get_contents($file);
            $this->assertStringNotContainsString('CanteenOrder::query()->create', $source, basename($file).' must never create a CanteenOrder directly -- only CanteenOrderService::place() may.');
        }
    }

    /**
     * @return list<string>
     */
    private function allPhpFilesUnder(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->getExtension() === 'php') {
                $files[] = $fileInfo->getPathname();
            }
        }

        return $files;
    }
}
