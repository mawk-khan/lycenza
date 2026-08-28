<?php

namespace Tests\Feature\Canteen;

use App\Domain\Canteen\Application\CanteenOrderLineData;
use App\Domain\Canteen\Application\CanteenOrderResult;
use App\Domain\Canteen\Application\CanteenOrderService;
use App\Domain\Canteen\Application\Exceptions\CanteenItemNotAvailableException;
use App\Domain\Canteen\Application\Exceptions\CanteenItemNotFoundException;
use App\Domain\Canteen\Application\Exceptions\CanteenOutletNotAvailableException;
use App\Domain\Canteen\Application\Exceptions\CanteenOutletNotFoundException;
use App\Domain\Canteen\Application\Exceptions\CanteenStudentNotEligibleException;
use App\Domain\Canteen\Application\Exceptions\CanteenStudentNotFoundException;
use App\Domain\Canteen\Application\Exceptions\DuplicateCanteenItemLineException;
use App\Domain\Canteen\Application\Exceptions\EmptyCanteenOrderException;
use App\Domain\Canteen\Application\PlaceCanteenOrderData;
use App\Domain\Canteen\Infrastructure\CanteenOrder;
use App\Domain\Inventory\Infrastructure\StockMovement;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesCanteenFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10F -- CanteenOrderService::place(). Never touches Inventory or
 * Fees -- only snapshots price/location and creates the Order + lines.
 */
class CanteenOrderPlacementTest extends TestCase
{
    use CreatesCanteenFixtures, CreatesTenancyFixtures;

    private function place(School $school, string $studentId, string $outletId, array $lines): CanteenOrderResult
    {
        $lineData = array_map(fn (array $l) => new CanteenOrderLineData($l['canteenItemId'], $l['quantity']), $lines);

        return app(TenantContext::class)->withSchool(
            $school,
            fn () => app(CanteenOrderService::class)->place($school, new PlaceCanteenOrderData($studentId, $outletId, $lineData)),
        );
    }

    #[Test]
    public function a_multi_line_order_places_successfully_with_exact_price_and_location_snapshots(): void
    {
        $school = $this->createSchool();
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $itemA = $this->createCanteenItem($school, ['price' => '15.50']);
        $itemB = $this->createCanteenItem($school, ['price' => '20.00']);

        $result = $this->place($school, $student->id, $outlet->id, [
            ['canteenItemId' => $itemA->id, 'quantity' => 2],
            ['canteenItemId' => $itemB->id, 'quantity' => 3],
        ]);

        // 2 * 15.50 + 3 * 20.00 = 31.00 + 60.00 = 91.00
        $this->assertSame('91.00', $result->totalAmount);
        $this->assertSame('pending', $result->status);
        $this->assertNull($result->chargeId);

        $order = app(TenantContext::class)->withSchool($school, fn () => CanteenOrder::query()->with('lines')->findOrFail($result->orderId));
        $this->assertSame($outlet->inventory_location_id, $order->inventory_location_id);
        $this->assertCount(2, $order->lines);

        $lineA = $order->lines->firstWhere('canteen_item_id', $itemA->id);
        $this->assertSame('15.50', $lineA->unit_price);
        $this->assertSame('31.00', $lineA->line_total);

        $lineB = $order->lines->firstWhere('canteen_item_id', $itemB->id);
        $this->assertSame('20.00', $lineB->unit_price);
        $this->assertSame('60.00', $lineB->line_total);

        // Placement never touches Inventory or Fees.
        $movementCount = app(TenantContext::class)->withSchool($school, fn () => StockMovement::query()->count());
        $this->assertSame(0, $movementCount);
    }

    #[Test]
    public function a_later_price_change_does_not_affect_an_already_placed_orders_snapshot(): void
    {
        $school = $this->createSchool();
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $item = $this->createCanteenItem($school, ['price' => '10.00']);

        $result = $this->place($school, $student->id, $outlet->id, [['canteenItemId' => $item->id, 'quantity' => 1]]);

        app(TenantContext::class)->withSchool($school, fn () => $item->update(['price' => '999.00']));

        $order = app(TenantContext::class)->withSchool($school, fn () => CanteenOrder::query()->with('lines')->findOrFail($result->orderId));
        $this->assertSame('10.00', $order->lines->first()->unit_price);
        $this->assertSame('10.00', $order->total_amount);
    }

    #[Test]
    public function duplicate_canteen_item_lines_are_rejected_outright(): void
    {
        $school = $this->createSchool();
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $item = $this->createCanteenItem($school);

        $this->expectException(DuplicateCanteenItemLineException::class);
        $this->place($school, $student->id, $outlet->id, [
            ['canteenItemId' => $item->id, 'quantity' => 1],
            ['canteenItemId' => $item->id, 'quantity' => 2],
        ]);
    }

    #[Test]
    public function an_empty_line_set_is_rejected(): void
    {
        $school = $this->createSchool();
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);

        $this->expectException(EmptyCanteenOrderException::class);
        $this->place($school, $student->id, $outlet->id, []);
    }

    #[Test]
    public function an_inactive_student_cannot_place_an_order(): void
    {
        $school = $this->createSchool();
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school, ['status' => 'inactive']);
        $item = $this->createCanteenItem($school);

        $this->expectException(CanteenStudentNotEligibleException::class);
        $this->place($school, $student->id, $outlet->id, [['canteenItemId' => $item->id, 'quantity' => 1]]);
    }

    #[Test]
    public function a_nonexistent_student_is_rejected(): void
    {
        $school = $this->createSchool();
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $item = $this->createCanteenItem($school);

        $this->expectException(CanteenStudentNotFoundException::class);
        $this->place($school, (string) new UuidV7, $outlet->id, [['canteenItemId' => $item->id, 'quantity' => 1]]);
    }

    #[Test]
    public function an_inactive_outlet_cannot_accept_an_order(): void
    {
        $school = $this->createSchool();
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location, ['status' => 'inactive']);
        $student = $this->createStudent($school);
        $item = $this->createCanteenItem($school);

        $this->expectException(CanteenOutletNotAvailableException::class);
        $this->place($school, $student->id, $outlet->id, [['canteenItemId' => $item->id, 'quantity' => 1]]);
    }

    #[Test]
    public function a_nonexistent_outlet_is_rejected(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $item = $this->createCanteenItem($school);

        $this->expectException(CanteenOutletNotFoundException::class);
        $this->place($school, $student->id, (string) new UuidV7, [['canteenItemId' => $item->id, 'quantity' => 1]]);
    }

    #[Test]
    public function an_inactive_canteen_item_cannot_be_ordered(): void
    {
        $school = $this->createSchool();
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $item = $this->createCanteenItem($school, ['status' => 'inactive']);

        $this->expectException(CanteenItemNotAvailableException::class);
        $this->place($school, $student->id, $outlet->id, [['canteenItemId' => $item->id, 'quantity' => 1]]);
    }

    #[Test]
    public function a_nonexistent_canteen_item_is_rejected(): void
    {
        $school = $this->createSchool();
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);

        $this->expectException(CanteenItemNotFoundException::class);
        $this->place($school, $student->id, $outlet->id, [['canteenItemId' => (string) new UuidV7, 'quantity' => 1]]);
    }

    #[Test]
    public function a_rejected_placement_creates_no_order_or_lines(): void
    {
        $school = $this->createSchool();
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school, ['status' => 'inactive']);
        $item = $this->createCanteenItem($school);

        try {
            $this->place($school, $student->id, $outlet->id, [['canteenItemId' => $item->id, 'quantity' => 1]]);
        } catch (CanteenStudentNotEligibleException) {
            // expected
        }

        $orderCount = app(TenantContext::class)->withSchool($school, fn () => CanteenOrder::query()->count());
        $this->assertSame(0, $orderCount);
    }

    #[Test]
    public function a_cross_school_student_is_treated_as_not_found(): void
    {
        $school = $this->createSchool();
        $otherSchool = $this->createSchool();
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $foreignStudent = $this->createStudent($otherSchool);
        $item = $this->createCanteenItem($school);

        $this->expectException(CanteenStudentNotFoundException::class);
        $this->place($school, $foreignStudent->id, $outlet->id, [['canteenItemId' => $item->id, 'quantity' => 1]]);
    }
}
