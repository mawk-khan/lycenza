<?php

namespace Tests\Feature\Canteen;

use App\Domain\Canteen\Application\Exceptions\CanteenOrderAlreadyCancelledException;
use App\Domain\Canteen\Application\Exceptions\CanteenOrderFulfilledCannotBeCancelledException;
use App\Domain\Canteen\Infrastructure\CanteenOrder;
use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Inventory\Infrastructure\StockMovement;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCanteenFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10F -- CanteenOrderService::cancel(). No downstream Inventory/
 * Fees calls in the ordinary path.
 */
class CanteenOrderCancellationTest extends TestCase
{
    use CreatesCanteenFixtures, CreatesFinanceFixtures, CreatesTenancyFixtures;

    #[Test]
    public function a_pending_order_can_be_cancelled(): void
    {
        $school = $this->createSchool();
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $item = $this->createCanteenItem($school);

        $placed = $this->placeCanteenOrder($school, $student->id, $outlet->id, [
            ['canteenItemId' => $item->id, 'quantity' => 1],
        ]);

        $result = $this->cancelCanteenOrder($school, $placed->orderId);

        $this->assertSame('cancelled', $result->status);
        $this->assertNotNull($result->cancelledAt);
        $this->assertNull($result->chargeId);

        $movementCount = app(TenantContext::class)->withSchool($school, fn () => StockMovement::query()->count());
        $this->assertSame(0, $movementCount, 'Cancellation must never touch Inventory.');

        $chargeCount = app(TenantContext::class)->withSchool($school, fn () => Charge::query()->where('student_id', $student->id)->count());
        $this->assertSame(0, $chargeCount, 'Cancellation must never touch Fees.');
    }

    #[Test]
    public function cancelling_an_already_cancelled_order_is_rejected(): void
    {
        $school = $this->createSchool();
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $item = $this->createCanteenItem($school);

        $placed = $this->placeCanteenOrder($school, $student->id, $outlet->id, [
            ['canteenItemId' => $item->id, 'quantity' => 1],
        ]);
        $this->cancelCanteenOrder($school, $placed->orderId);

        $this->expectException(CanteenOrderAlreadyCancelledException::class);
        $this->cancelCanteenOrder($school, $placed->orderId);
    }

    #[Test]
    public function cancelling_a_fulfilled_order_is_rejected(): void
    {
        $school = $this->createSchool();
        $this->createAcademicYear($school, ['status' => 'active']);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $this->configureCanteenBilling($school, $receivable, $revenue);
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $item = $this->createCanteenItem($school);

        $placed = $this->placeCanteenOrder($school, $student->id, $outlet->id, [
            ['canteenItemId' => $item->id, 'quantity' => 1],
        ]);
        $this->fulfillCanteenOrder($school, $placed->orderId);

        $this->expectException(CanteenOrderFulfilledCannotBeCancelledException::class);
        $this->cancelCanteenOrder($school, $placed->orderId);
    }

    #[Test]
    public function a_rejected_cancellation_leaves_the_orders_status_unchanged(): void
    {
        $school = $this->createSchool();
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $item = $this->createCanteenItem($school);

        $placed = $this->placeCanteenOrder($school, $student->id, $outlet->id, [
            ['canteenItemId' => $item->id, 'quantity' => 1],
        ]);
        $this->cancelCanteenOrder($school, $placed->orderId);

        try {
            $this->cancelCanteenOrder($school, $placed->orderId);
        } catch (CanteenOrderAlreadyCancelledException) {
            // expected
        }

        $order = app(TenantContext::class)->withSchool($school, fn () => CanteenOrder::query()->find($placed->orderId));
        $this->assertSame('cancelled', $order->status);
    }
}
