<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\Application\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Application\Exceptions\InvalidQuantityException;
use App\Domain\Inventory\Application\Exceptions\ItemNotAvailableException;
use App\Domain\Inventory\Application\Exceptions\LocationNotAvailableException;
use App\Domain\Inventory\Application\Exceptions\SameLocationTransferException;
use App\Domain\Inventory\Application\InventoryStockService;
use App\Domain\Inventory\Infrastructure\InventoryItem;
use App\Domain\Inventory\Infrastructure\InventoryLocation;
use App\Domain\Inventory\Infrastructure\InventoryStockBalance;
use App\Domain\Inventory\Infrastructure\StockMovement;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10E -- Inventory stock lifecycle. Every test exercises the
 * real App\Domain\Inventory\Application\InventoryStockService, never a
 * raw model write, mirroring HostelResidencyServiceTest's exact
 * pattern.
 */
class InventoryStockServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function balance(InventoryItem $item, InventoryLocation $location): InventoryStockBalance
    {
        return app(TenantContext::class)->withSchool(
            $item->school,
            fn () => InventoryStockBalance::query()->where('inventory_item_id', $item->id)->where('inventory_location_id', $location->id)->first(),
        );
    }

    // --- Receive -----------------------------------------------------------

    #[Test]
    public function receive_creates_a_balance_row_on_the_first_ever_receipt_and_records_an_audit_event(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $item = $this->createInventoryItem($school);
        $location = $this->createInventoryLocation($school);

        $movement = app(TenantContext::class)->withSchool($school, fn () => app(InventoryStockService::class)->receive($item, $location, '10.000', $admin));

        $this->assertSame('receipt', $movement->movement_type);
        $this->assertSame('10.000', $movement->quantity);
        $this->assertNull($movement->from_location_id);
        $this->assertSame($location->id, $movement->to_location_id);

        $balance = $this->balance($item, $location);
        $this->assertSame('10.000', $balance->quantity_on_hand);

        $event = app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()
            ->where('event_type', 'inventory.stock.received')
            ->where('subject_id', $movement->id)
            ->first());
        $this->assertNotNull($event, 'An inventory.stock.received audit event must be recorded.');
        $this->assertSame($admin->id, $event->actor_user_id);
    }

    #[Test]
    public function a_subsequent_receipt_adds_to_the_existing_balance(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $item = $this->createInventoryItem($school);
        $location = $this->createInventoryLocation($school);
        $service = app(InventoryStockService::class);

        app(TenantContext::class)->withSchool($school, fn () => $service->receive($item, $location, '10.000', $admin));
        app(TenantContext::class)->withSchool($school, fn () => $service->receive($item, $location, '5.000', $admin));

        $balance = $this->balance($item, $location);
        $this->assertSame('15.000', $balance->quantity_on_hand);
    }

    #[Test]
    public function receive_accepts_an_exact_fractional_quantity_for_a_fractional_unit(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $item = $this->createInventoryItem($school, ['unit_of_measure' => 'kg']);
        $location = $this->createInventoryLocation($school);

        app(TenantContext::class)->withSchool($school, fn () => app(InventoryStockService::class)->receive($item, $location, '1.250', $admin));

        $this->assertSame('1.250', $this->balance($item, $location)->quantity_on_hand);
    }

    #[Test]
    public function receive_rejects_a_fractional_quantity_for_a_non_fractional_unit(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $item = $this->createInventoryItem($school, ['unit_of_measure' => 'each']);
        $location = $this->createInventoryLocation($school);

        $this->expectException(InvalidQuantityException::class);
        app(TenantContext::class)->withSchool($school, fn () => app(InventoryStockService::class)->receive($item, $location, '1.500', $admin));
    }

    #[Test]
    public function receive_rejects_a_zero_quantity(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $item = $this->createInventoryItem($school);
        $location = $this->createInventoryLocation($school);

        $this->expectException(InvalidQuantityException::class);
        app(TenantContext::class)->withSchool($school, fn () => app(InventoryStockService::class)->receive($item, $location, '0.000', $admin));
    }

    #[Test]
    public function receive_refuses_an_inactive_item(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $item = $this->createInventoryItem($school, ['status' => 'inactive']);
        $location = $this->createInventoryLocation($school);

        $this->expectException(ItemNotAvailableException::class);
        app(TenantContext::class)->withSchool($school, fn () => app(InventoryStockService::class)->receive($item, $location, '1.000', $admin));
    }

    #[Test]
    public function receive_refuses_an_inactive_location(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $item = $this->createInventoryItem($school);
        $location = $this->createInventoryLocation($school, ['status' => 'inactive']);

        $this->expectException(LocationNotAvailableException::class);
        app(TenantContext::class)->withSchool($school, fn () => app(InventoryStockService::class)->receive($item, $location, '1.000', $admin));
    }

    // --- Issue -------------------------------------------------------------

    #[Test]
    public function issue_decrements_the_balance_and_records_an_audit_event(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $item = $this->createInventoryItem($school);
        $location = $this->createInventoryLocation($school);
        $service = app(InventoryStockService::class);
        app(TenantContext::class)->withSchool($school, fn () => $service->receive($item, $location, '10.000', $admin));

        $movement = app(TenantContext::class)->withSchool($school, fn () => $service->issue($item, $location, '4.000', $admin));

        $this->assertSame('issue', $movement->movement_type);
        $this->assertSame($location->id, $movement->from_location_id);
        $this->assertNull($movement->to_location_id);
        $this->assertSame('6.000', $this->balance($item, $location)->quantity_on_hand);

        $event = app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()
            ->where('event_type', 'inventory.stock.issued')
            ->where('subject_id', $movement->id)
            ->first());
        $this->assertNotNull($event);
    }

    #[Test]
    public function issue_refuses_insufficient_stock(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $item = $this->createInventoryItem($school);
        $location = $this->createInventoryLocation($school);
        $service = app(InventoryStockService::class);
        app(TenantContext::class)->withSchool($school, fn () => $service->receive($item, $location, '5.000', $admin));

        $this->expectException(InsufficientStockException::class);
        app(TenantContext::class)->withSchool($school, fn () => $service->issue($item, $location, '6.000', $admin));
    }

    #[Test]
    public function issue_allows_issuing_the_exact_remaining_quantity_down_to_zero(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $item = $this->createInventoryItem($school);
        $location = $this->createInventoryLocation($school);
        $service = app(InventoryStockService::class);
        app(TenantContext::class)->withSchool($school, fn () => $service->receive($item, $location, '5.000', $admin));

        app(TenantContext::class)->withSchool($school, fn () => $service->issue($item, $location, '5.000', $admin));

        $this->assertSame('0.000', $this->balance($item, $location)->quantity_on_hand);
    }

    #[Test]
    public function issue_against_a_location_with_no_balance_row_yet_is_insufficient_stock_not_an_error(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $item = $this->createInventoryItem($school);
        $location = $this->createInventoryLocation($school);

        $this->expectException(InsufficientStockException::class);
        app(TenantContext::class)->withSchool($school, fn () => app(InventoryStockService::class)->issue($item, $location, '1.000', $admin));
    }

    #[Test]
    public function issue_refuses_an_inactive_item(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $item = $this->createInventoryItem($school, ['status' => 'inactive']);
        $location = $this->createInventoryLocation($school);

        $this->expectException(ItemNotAvailableException::class);
        app(TenantContext::class)->withSchool($school, fn () => app(InventoryStockService::class)->issue($item, $location, '1.000', $admin));
    }

    #[Test]
    public function issue_refuses_an_inactive_location(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $item = $this->createInventoryItem($school);
        $location = $this->createInventoryLocation($school, ['status' => 'inactive']);

        $this->expectException(LocationNotAvailableException::class);
        app(TenantContext::class)->withSchool($school, fn () => app(InventoryStockService::class)->issue($item, $location, '1.000', $admin));
    }

    // --- Transfer ----------------------------------------------------------

    #[Test]
    public function transfer_moves_quantity_between_two_locations_atomically(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $item = $this->createInventoryItem($school);
        $from = $this->createInventoryLocation($school);
        $to = $this->createInventoryLocation($school);
        $service = app(InventoryStockService::class);
        app(TenantContext::class)->withSchool($school, fn () => $service->receive($item, $from, '10.000', $admin));

        $movement = app(TenantContext::class)->withSchool($school, fn () => $service->transfer($item, $from, $to, '4.000', $admin));

        $this->assertSame('transfer', $movement->movement_type);
        $this->assertSame($from->id, $movement->from_location_id);
        $this->assertSame($to->id, $movement->to_location_id);
        $this->assertSame('6.000', $this->balance($item, $from)->quantity_on_hand);
        $this->assertSame('4.000', $this->balance($item, $to)->quantity_on_hand);
    }

    #[Test]
    public function transfer_to_the_destination_with_no_prior_balance_creates_it(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $item = $this->createInventoryItem($school);
        $from = $this->createInventoryLocation($school);
        $to = $this->createInventoryLocation($school);
        $service = app(InventoryStockService::class);
        app(TenantContext::class)->withSchool($school, fn () => $service->receive($item, $from, '10.000', $admin));

        $existsBefore = app(TenantContext::class)->withSchool($school, fn () => InventoryStockBalance::query()->where('inventory_item_id', $item->id)->where('inventory_location_id', $to->id)->exists());
        $this->assertFalse($existsBefore);

        app(TenantContext::class)->withSchool($school, fn () => $service->transfer($item, $from, $to, '3.000', $admin));

        $this->assertSame('3.000', $this->balance($item, $to)->quantity_on_hand);
    }

    #[Test]
    public function transfer_refuses_the_same_source_and_destination(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $item = $this->createInventoryItem($school);
        $location = $this->createInventoryLocation($school);

        $this->expectException(SameLocationTransferException::class);
        app(TenantContext::class)->withSchool($school, fn () => app(InventoryStockService::class)->transfer($item, $location, $location, '1.000', $admin));
    }

    #[Test]
    public function transfer_refuses_insufficient_source_stock(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $item = $this->createInventoryItem($school);
        $from = $this->createInventoryLocation($school);
        $to = $this->createInventoryLocation($school);
        $service = app(InventoryStockService::class);
        app(TenantContext::class)->withSchool($school, fn () => $service->receive($item, $from, '2.000', $admin));

        $this->expectException(InsufficientStockException::class);
        app(TenantContext::class)->withSchool($school, fn () => $service->transfer($item, $from, $to, '3.000', $admin));
    }

    #[Test]
    public function a_failed_transfer_does_not_mutate_either_balance_or_create_a_movement(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $item = $this->createInventoryItem($school);
        $from = $this->createInventoryLocation($school);
        $to = $this->createInventoryLocation($school);
        $service = app(InventoryStockService::class);
        app(TenantContext::class)->withSchool($school, fn () => $service->receive($item, $from, '2.000', $admin));

        try {
            app(TenantContext::class)->withSchool($school, fn () => $service->transfer($item, $from, $to, '10.000', $admin));
            $this->fail('Expected InsufficientStockException.');
        } catch (InsufficientStockException) {
            // expected
        }

        $this->assertSame('2.000', $this->balance($item, $from)->quantity_on_hand);
        $destExists = app(TenantContext::class)->withSchool($school, fn () => InventoryStockBalance::query()->where('inventory_item_id', $item->id)->where('inventory_location_id', $to->id)->exists());
        $this->assertFalse($destExists, 'A failed transfer must not leave behind a newly-created zero destination balance row.');

        $movementCount = app(TenantContext::class)->withSchool($school, fn () => StockMovement::query()->where('movement_type', 'transfer')->count());
        $this->assertSame(0, $movementCount);
    }

    #[Test]
    public function transfer_refuses_an_inactive_source_or_destination_location(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $item = $this->createInventoryItem($school);
        $from = $this->createInventoryLocation($school, ['status' => 'inactive']);
        $to = $this->createInventoryLocation($school);

        $this->expectException(LocationNotAvailableException::class);
        app(TenantContext::class)->withSchool($school, fn () => app(InventoryStockService::class)->transfer($item, $from, $to, '1.000', $admin));
    }

    // --- History -------------------------------------------------------------

    #[Test]
    public function each_mutation_creates_exactly_one_movement(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $item = $this->createInventoryItem($school);
        $from = $this->createInventoryLocation($school);
        $to = $this->createInventoryLocation($school);
        $service = app(InventoryStockService::class);

        app(TenantContext::class)->withSchool($school, fn () => $service->receive($item, $from, '10.000', $admin));
        app(TenantContext::class)->withSchool($school, fn () => $service->issue($item, $from, '2.000', $admin));
        app(TenantContext::class)->withSchool($school, fn () => $service->transfer($item, $from, $to, '3.000', $admin));

        $count = app(TenantContext::class)->withSchool($school, fn () => StockMovement::query()->count());
        $this->assertSame(3, $count);
    }

    #[Test]
    public function movement_history_remains_valid_after_the_item_and_location_are_deactivated(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $item = $this->createInventoryItem($school);
        $location = $this->createInventoryLocation($school);
        $movement = app(TenantContext::class)->withSchool($school, fn () => app(InventoryStockService::class)->receive($item, $location, '10.000', $admin));

        $item->update(['status' => 'inactive']);
        $location->update(['status' => 'inactive']);

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $movement->fresh());
        $this->assertSame('receipt', $fresh->movement_type);
        $this->assertSame('10.000', $fresh->quantity);
        $this->assertSame($location->id, $fresh->to_location_id);
    }
}
