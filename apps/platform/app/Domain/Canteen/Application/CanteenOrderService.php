<?php

namespace App\Domain\Canteen\Application;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\Canteen\Application\Exceptions\CanteenInvalidQuantityException;
use App\Domain\Canteen\Application\Exceptions\CanteenItemNotAvailableException;
use App\Domain\Canteen\Application\Exceptions\CanteenItemNotFoundException;
use App\Domain\Canteen\Application\Exceptions\CanteenNoActiveAcademicYearException;
use App\Domain\Canteen\Application\Exceptions\CanteenOrderAlreadyCancelledException;
use App\Domain\Canteen\Application\Exceptions\CanteenOrderAlreadyFulfilledException;
use App\Domain\Canteen\Application\Exceptions\CanteenOrderCancelledCannotBeFulfilledException;
use App\Domain\Canteen\Application\Exceptions\CanteenOrderFulfilledCannotBeCancelledException;
use App\Domain\Canteen\Application\Exceptions\CanteenOrderNotFoundException;
use App\Domain\Canteen\Application\Exceptions\CanteenOutletNotAvailableException;
use App\Domain\Canteen\Application\Exceptions\CanteenOutletNotFoundException;
use App\Domain\Canteen\Application\Exceptions\CanteenStudentNotEligibleException;
use App\Domain\Canteen\Application\Exceptions\CanteenStudentNotFoundException;
use App\Domain\Canteen\Application\Exceptions\DuplicateCanteenItemLineException;
use App\Domain\Canteen\Application\Exceptions\EmptyCanteenOrderException;
use App\Domain\Canteen\Infrastructure\CanteenItem;
use App\Domain\Canteen\Infrastructure\CanteenItemInventoryRequirement;
use App\Domain\Canteen\Infrastructure\CanteenOrder;
use App\Domain\Canteen\Infrastructure\CanteenOrderLine;
use App\Domain\Canteen\Infrastructure\CanteenOrderStockConsumption;
use App\Domain\Canteen\Infrastructure\CanteenOutlet;
use App\Domain\Fees\Application\AssessChargeData;
use App\Domain\Fees\Application\ChargeService;
use App\Domain\Inventory\Application\InventoryStockService;
use App\Domain\Inventory\Application\IssueRequirement;
use App\Domain\Inventory\Infrastructure\InventoryLocation;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The core Canteen write path: `place()`, `fulfill()`, `cancel()`.
 *
 * `place()` never touches Inventory or Fees -- it only snapshots
 * prices/location and creates the Order + lines.
 *
 * `fulfill()` is the cross-domain orchestration boundary
 * (docs pattern: same "trusted internal orchestrator" role
 * `App\Domain\Fees\Application\ChargeService::assess()` plays relative
 * to `App\Domain\Finance\Application\LedgerService::post()`). It calls
 * `InventoryStockService::issueMany()` and `ChargeService::assess()`
 * directly with NO internal capability re-check -- authorization is the
 * HTTP layer's job (`canteen.orders.manage` alone is sufficient to
 * place/fulfill/cancel), matching the established Fees->Finance/
 * Payments->Fees precedent (CLAUDE.md rule: "no internal capability
 * re-check" boundary). Everything happens inside ONE outer
 * `DB::transaction()` -- both `issueMany()`'s and `assess()`'s own
 * internal transactions become PostgreSQL SAVEPOINTs nested inside it
 * (Laravel's automatic nested-transaction behavior, already proven safe
 * by the Fees->Finance composition this mirrors), so a failure at ANY
 * step -- including a Charge-assessment failure AFTER Inventory has
 * already been decremented -- rolls back the ENTIRE transaction:
 * Order stays pending, no Charge, no stock movement, no consumption
 * row.
 *
 * Lock ordering (deadlock avoidance, mirrors
 * `InventoryStockService::lockBalancesInDeterministicOrder()`'s exact
 * reasoning): `fulfill()` first locks the ONE `canteen_orders` row,
 * then locks the involved `canteen_items` rows in deterministic
 * ASCENDING id order (never Order-line insertion order) BEFORE reading
 * their current recipe -- this is what makes a concurrent recipe
 * mutation (`CanteenRecipeService`, which locks the SAME CanteenItem
 * row before writing) and a concurrent fulfillment reading that same
 * Item's recipe serialize cleanly rather than tear.
 */
class CanteenOrderService
{
    public function __construct(
        private readonly InventoryStockService $inventoryStock,
        private readonly ChargeService $chargeService,
        private readonly CanteenBillingConfigurationService $billingConfig,
        private readonly AuditRecorder $audit,
    ) {}

    public function place(School $school, PlaceCanteenOrderData $data, ?User $actor = null): CanteenOrderResult
    {
        $student = Student::query()->where('school_id', $school->id)->find($data->studentId);
        if ($student === null) {
            throw new CanteenStudentNotFoundException($data->studentId);
        }
        if (! $student->isActive()) {
            throw new CanteenStudentNotEligibleException;
        }

        $outlet = CanteenOutlet::query()->where('school_id', $school->id)->find($data->outletId);
        if ($outlet === null) {
            throw new CanteenOutletNotFoundException($data->outletId);
        }
        if (! $outlet->isActive()) {
            throw new CanteenOutletNotAvailableException;
        }

        if ($data->lines === []) {
            throw new EmptyCanteenOrderException;
        }

        $seenItemIds = [];
        foreach ($data->lines as $line) {
            if (isset($seenItemIds[$line->canteenItemId])) {
                throw new DuplicateCanteenItemLineException($line->canteenItemId);
            }
            $seenItemIds[$line->canteenItemId] = true;

            if ($line->quantity <= 0) {
                throw new CanteenInvalidQuantityException;
            }
        }

        $items = CanteenItem::query()
            ->where('school_id', $school->id)
            ->whereIn('id', array_keys($seenItemIds))
            ->get()
            ->keyBy('id');

        foreach (array_keys($seenItemIds) as $itemId) {
            $item = $items->get($itemId);

            if ($item === null) {
                throw new CanteenItemNotFoundException($itemId);
            }
            if (! $item->isActive()) {
                throw new CanteenItemNotAvailableException;
            }
        }

        return DB::transaction(function () use ($school, $student, $outlet, $data, $items, $actor) {
            $lineRows = [];
            $totalAmount = '0.00';

            foreach ($data->lines as $line) {
                $item = $items->get($line->canteenItemId);
                $lineTotal = bcmul($item->price, (string) $line->quantity, 2);
                $totalAmount = bcadd($totalAmount, $lineTotal, 2);

                $lineRows[] = [
                    'canteen_item_id' => $item->id,
                    'quantity' => $line->quantity,
                    'unit_price' => $item->price,
                    'line_total' => $lineTotal,
                    'currency' => 'INR',
                ];
            }

            $order = CanteenOrder::query()->create([
                'school_id' => $school->id,
                'student_id' => $student->id,
                'outlet_id' => $outlet->id,
                'inventory_location_id' => $outlet->inventory_location_id,
                'status' => 'pending',
                'total_amount' => $totalAmount,
                'currency' => 'INR',
                'charge_id' => null,
                'placed_at' => now(),
            ]);

            $sumOfLineTotals = '0.00';
            foreach ($lineRows as $row) {
                CanteenOrderLine::query()->create([
                    'school_id' => $school->id,
                    'order_id' => $order->id,
                    ...$row,
                ]);
                $sumOfLineTotals = bcadd($sumOfLineTotals, $row['line_total'], 2);
            }

            // Defensive reconciliation assertion -- true by construction,
            // asserted regardless (checkpoint brief's placement-time
            // reconciliation target).
            if (bccomp($sumOfLineTotals, $order->total_amount, 2) !== 0) {
                throw new RuntimeException('Canteen order line totals do not reconcile with the order total -- this should be unreachable.');
            }

            $this->audit->school($school, 'canteen.order.placed', actor: $actor, subject: $order, metadata: [
                'orderId' => $order->id,
                'studentId' => $order->student_id,
                'outletId' => $order->outlet_id,
                'lineCount' => count($lineRows),
                'totalAmount' => $order->total_amount,
                'currency' => $order->currency,
            ]);

            return CanteenOrderResult::fromModel($order->refresh());
        });
    }

    public function fulfill(School $school, string $orderId, ?User $actor = null): CanteenOrderResult
    {
        return DB::transaction(function () use ($school, $orderId, $actor) {
            $order = CanteenOrder::query()->where('school_id', $school->id)->lockForUpdate()->find($orderId);

            if ($order === null) {
                throw new CanteenOrderNotFoundException($orderId);
            }

            if ($order->isFulfilled()) {
                throw new CanteenOrderAlreadyFulfilledException($order->id);
            }
            if ($order->isCancelled()) {
                throw new CanteenOrderCancelledCannotBeFulfilledException($order->id);
            }

            $billingConfig = $this->billingConfig->resolveValidated($school);

            $academicYear = AcademicYear::query()
                ->where('school_id', $school->id)
                ->where('status', 'active')
                ->first();

            if ($academicYear === null) {
                throw new CanteenNoActiveAcademicYearException;
            }

            $lines = CanteenOrderLine::query()->where('school_id', $school->id)->where('order_id', $order->id)->get();

            $canteenItemIds = $lines->pluck('canteen_item_id')->unique()->values()->all();
            sort($canteenItemIds, SORT_STRING);

            $lockedItems = [];
            foreach ($canteenItemIds as $itemId) {
                $lockedItems[$itemId] = CanteenItem::query()->where('school_id', $school->id)->where('id', $itemId)->lockForUpdate()->firstOrFail();
            }

            $requirements = CanteenItemInventoryRequirement::query()
                ->where('school_id', $school->id)
                ->whereIn('canteen_item_id', $canteenItemIds)
                ->get()
                ->groupBy('canteen_item_id');

            /** @var array<string, string> $aggregated inventory_item_id => quantity string */
            $aggregated = [];
            foreach ($lines as $line) {
                $itemRequirements = $requirements->get($line->canteen_item_id, collect());

                foreach ($itemRequirements as $requirement) {
                    $needed = bcmul($requirement->quantity_required, (string) $line->quantity, 3);
                    $aggregated[$requirement->inventory_item_id] = isset($aggregated[$requirement->inventory_item_id])
                        ? bcadd($aggregated[$requirement->inventory_item_id], $needed, 3)
                        : $needed;
                }
            }

            $movements = [];
            if ($aggregated !== []) {
                $location = InventoryLocation::query()->where('school_id', $school->id)->findOrFail($order->inventory_location_id);

                $issueRequirements = [];
                foreach ($aggregated as $inventoryItemId => $quantity) {
                    $issueRequirements[] = new IssueRequirement($inventoryItemId, $quantity);
                }

                $movements = $this->inventoryStock->issueMany($location, $issueRequirements, $actor);
            }

            $chargeResult = $this->chargeService->assess($school, new AssessChargeData(
                studentId: $order->student_id,
                academicYearId: $academicYear->id,
                description: "Canteen order {$order->id}",
                amount: Money::of($order->total_amount, $order->currency),
                receivableLedgerAccountId: $billingConfig->receivable_ledger_account_id,
                revenueLedgerAccountId: $billingConfig->revenue_ledger_account_id,
                dueDate: null,
            ), $actor);

            foreach ($movements as $movement) {
                CanteenOrderStockConsumption::query()->create([
                    'school_id' => $school->id,
                    'canteen_order_id' => $order->id,
                    'stock_movement_id' => $movement->id,
                ]);
            }

            $order->update([
                'charge_id' => $chargeResult->chargeId,
                'status' => 'fulfilled',
                'fulfilled_at' => now(),
            ]);

            $this->audit->school($school, 'canteen.order.fulfilled', actor: $actor, subject: $order, metadata: [
                'orderId' => $order->id,
                'studentId' => $order->student_id,
                'outletId' => $order->outlet_id,
                'chargeId' => $chargeResult->chargeId,
                'totalAmount' => $order->total_amount,
                'currency' => $order->currency,
                'stockMovementCount' => count($movements),
            ]);

            return CanteenOrderResult::fromModel($order->refresh());
        });
    }

    public function cancel(School $school, string $orderId, ?User $actor = null): CanteenOrderResult
    {
        return DB::transaction(function () use ($school, $orderId, $actor) {
            $order = CanteenOrder::query()->where('school_id', $school->id)->lockForUpdate()->find($orderId);

            if ($order === null) {
                throw new CanteenOrderNotFoundException($orderId);
            }

            if ($order->isCancelled()) {
                throw new CanteenOrderAlreadyCancelledException($order->id);
            }
            if ($order->isFulfilled()) {
                throw new CanteenOrderFulfilledCannotBeCancelledException($order->id);
            }

            $order->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);

            $this->audit->school($school, 'canteen.order.cancelled', actor: $actor, subject: $order, metadata: [
                'orderId' => $order->id,
                'studentId' => $order->student_id,
                'outletId' => $order->outlet_id,
            ]);

            return CanteenOrderResult::fromModel($order->refresh());
        });
    }
}
