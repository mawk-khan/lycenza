<?php

namespace Tests\Feature\Inventory;

use App\Support\Authorization\Campus\CampusScopeCatalogue;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ORG.7H-1 (ADR 0076 §54): structural pins for the Inventory Location Campus
 * freeze -- one writer of the Location directory, which locks the Location
 * and asks the ONE database predicate; every stock write share-locks its
 * Location(s) first and re-checks the Campus it was authorized under; the
 * directory stays whole-School. The database rule itself is pinned by
 * Tests\Feature\Postgres\InventoryLocationCampusFreezeDbTest.
 */
class InventoryLocationCampusArchitectureTest extends TestCase
{
    private function code(string $file): string
    {
        return (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($file));
    }

    /** @return list<string> */
    private function appFiles(): array
    {
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }
        sort($out);

        return $out;
    }

    #[Test]
    public function the_location_directory_has_one_writer(): void
    {
        $writers = [];
        foreach ($this->appFiles() as $file) {
            $code = $this->code($file);
            $touchesLocations = str_contains($code, 'InventoryLocation');
            if (preg_match('/InventoryLocation::(query\(\)[^;]*->)?(create|update|forceCreate|upsert|insert)\(/s', $code) === 1
                || preg_match('/table\([\'"]inventory_locations[\'"]\)/', $code) === 1
                || preg_match('/(update|insert\s+into)\s+inventory_locations/i', $code) === 1
                || ($touchesLocations && preg_match('/\$\w*[lL]ocation\w*->(campus_id\s*=[^=>]|(force)?[fF]ill\(|save\(|update\()/', $code) === 1)) {
                $writers[] = substr($file, strlen(app_path()) + 1);
            }
        }
        $this->assertSame(['Domain/Inventory/Application/InventoryLocationService.php'], $writers, 'InventoryLocationService is the only writer of a Location');

        foreach (['Domain/Inventory/Http/Controllers/InventoryLocationController.php', 'Http/Controllers/App/InventoryLocationController.php'] as $path) {
            $controller = $this->code(app_path($path));
            $this->assertStringContainsString('$service->create(', $controller, $path);
            $this->assertStringContainsString('$service->update(', $controller, $path);
            $this->assertStringNotContainsString('->update($validated)', $controller, "{$path}: a controller never writes the Location itself");
        }

        preg_match('/public function update\(.*?\n    \}/s', $this->code(app_path('Http/Controllers/App/InventoryLocationController.php')), $web);
        $this->assertStringNotContainsString('campus_id', $web[0] ?? 'missing', 'the web edit form never changes a Campus');
    }

    #[Test]
    public function the_writer_locks_the_location_and_asks_the_one_database_predicate(): void
    {
        $service = $this->code(app_path('Domain/Inventory/Application/InventoryLocationService.php'));

        preg_match('/public function update\(.*?\n    \}/s', $service, $update);
        $update = $update[0] ?? '';
        $this->assertStringContainsString('->lockForUpdate()->findOrFail($locationId)', $update);
        $this->assertLessThan(strpos($update, '$location->update($data)'), strpos($update, '->hasOperationalHistory($location)'), 'decided before the write');
        $this->assertStringContainsString('throw new LocationCampusFrozenException', $update);
        $this->assertStringContainsString('SELECT inventory_location_has_operational_history(?, ?)', $service);

        foreach (['StockMovement', 'InventoryStockBalance', 'CanteenOrder', 'stock_movements', 'canteen_orders'] as $evidence) {
            $this->assertStringNotContainsString($evidence, $service, 'the database predicate is the only definition of "used" (and Inventory never reads Canteen, rule 4)');
        }
    }

    #[Test]
    public function every_stock_write_share_locks_its_locations_first_and_rechecks_the_campus(): void
    {
        $stock = $this->code(app_path('Domain/Inventory/Application/InventoryStockService.php'));

        foreach (['receive' => '$location', 'issue' => '$location', 'transfer' => '$from, $to', 'issueMany' => '$location'] as $method => $locations) {
            preg_match('/public function '.$method.'\(.*?\n    \}/s', $stock, $body);
            $body = $body[0] ?? '';
            $lock = strpos($body, "\$this->lockLocations({$locations});");
            $this->assertNotFalse($lock, "{$method}() share-locks its Location(s)");
            $this->assertLessThan(strpos($body, '$this->ensureBalanceRow('), $lock, "{$method}(): the Location lock comes first");
            $this->assertLessThan($lock, strpos($body, 'DB::transaction('), "{$method}(): inside the transaction");
        }

        preg_match('/private function lockLocations\(.*?\n    \}/s', $stock, $lockLocations);
        $lockLocations = $lockLocations[0] ?? '';
        $this->assertStringContainsString("->orderBy('id')", $lockLocations);
        $this->assertStringContainsString('->sharedLock()', $lockLocations);
        $this->assertStringContainsString('$locked->campus_id !== $location->campus_id', $lockLocations);
        $this->assertStringContainsString('throw new LocationNotAvailableException', $lockLocations);
    }

    #[Test]
    public function the_location_directory_stays_whole_school(): void
    {
        foreach (['api.v1.schools.inventory-locations.store', 'api.v1.schools.inventory-locations.update'] as $name) {
            $middleware = Route::getRoutes()->getByName($name)?->gatherMiddleware() ?? [];
            $this->assertContains('capability:inventory.directory.manage', $middleware, $name);
            $this->assertEmpty(array_filter($middleware, fn ($m) => is_string($m) && str_ends_with($m, ',campus')), "{$name} has no Campus floor");
        }

        $this->assertFalse(CampusScopeCatalogue::isAdopted('inventory.directory.manage'));
        $this->assertFalse(CampusScopeCatalogue::isAdopted('inventory.directory.view'));
    }
}
