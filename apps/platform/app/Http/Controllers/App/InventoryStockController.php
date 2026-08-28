<?php

namespace App\Http\Controllers\App;

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
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 10E -- session-authenticated Inertia pages for Inventory
 * stock: current balances, movement history, and the
 * receive/issue/transfer forms. Every mutation delegates to
 * InventoryStockService, the exact same service the JSON API
 * controller uses. The Item/Location search endpoints below explicitly
 * call authorizeCapability() BEFORE querying -- carrying forward the
 * Library/Transport/Visitor/Hostel anti-P1 precedent.
 */
class InventoryStockController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, TenantContext $context, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('inventory.stock.view', $school);

        $balances = InventoryStockBalance::query()
            ->with(['item', 'location'])
            ->orderBy('id')
            ->paginate(20, ['*'], 'balancesPage')
            ->withQueryString();

        $movements = StockMovement::query()
            ->with(['item', 'fromLocation', 'toLocation'])
            ->orderByDesc('occurred_at')
            ->paginate(20, ['*'], 'movementsPage')
            ->withQueryString();

        return Inertia::render('App/Inventory/Stock/Index', [
            'balances' => $balances->through(fn (InventoryStockBalance $b) => $this->presentBalance($b)),
            'movements' => $movements->through(fn (StockMovement $m) => $this->presentMovement($m)),
            'canManage' => $capabilities->canInSchool($context->actor(), 'inventory.stock.manage', $school),
        ]);
    }

    public function createReceive(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('inventory.stock.manage', $school);

        return Inertia::render('App/Inventory/Stock/Receive');
    }

    public function createIssue(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('inventory.stock.manage', $school);

        return Inertia::render('App/Inventory/Stock/Issue');
    }

    public function createTransfer(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('inventory.stock.manage', $school);

        return Inertia::render('App/Inventory/Stock/Transfer');
    }

    public function searchItems(Request $request, TenantContext $context): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('inventory.stock.manage', $school);

        $term = (string) $request->query('q', '');

        $items = InventoryItem::query()
            ->where('status', 'active')
            ->when($term !== '', fn ($q) => $q->where('code', 'ilike', "%{$term}%")->orWhere('name', 'ilike', "%{$term}%"))
            ->limit(10)
            ->get();

        return response()->json(['data' => $items->map(fn (InventoryItem $i) => [
            'id' => $i->id,
            'code' => $i->code,
            'name' => $i->name,
            'unitOfMeasure' => $i->unit_of_measure,
        ])->all()]);
    }

    public function searchLocations(Request $request, TenantContext $context): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('inventory.stock.manage', $school);

        $term = (string) $request->query('q', '');

        $locations = InventoryLocation::query()
            ->where('status', 'active')
            ->when($term !== '', fn ($q) => $q->where('code', 'ilike', "%{$term}%")->orWhere('name', 'ilike', "%{$term}%"))
            ->limit(10)
            ->get();

        return response()->json(['data' => $locations->map(fn (InventoryLocation $l) => [
            'id' => $l->id,
            'code' => $l->code,
            'name' => $l->name,
        ])->all()]);
    }

    public function storeReceive(Request $request, TenantContext $context, InventoryStockService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('inventory.stock.manage', $school);

        $validated = $this->validateMutation($request);

        $item = InventoryItem::query()->findOrFail($validated['inventory_item_id']);
        $location = InventoryLocation::query()->findOrFail($validated['inventory_location_id']);

        try {
            $service->receive($item, $location, $validated['quantity'], $context->actor());
        } catch (ItemNotAvailableException|InvalidQuantityException $e) {
            throw ValidationException::withMessages(['inventory_item_id' => [$e->getMessage()]]);
        } catch (LocationNotAvailableException $e) {
            throw ValidationException::withMessages(['inventory_location_id' => [$e->getMessage()]]);
        }

        return redirect('/app/inventory-stock')->with('flash', 'Stock received.');
    }

    public function storeIssue(Request $request, TenantContext $context, InventoryStockService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('inventory.stock.manage', $school);

        $validated = $this->validateMutation($request);

        $item = InventoryItem::query()->findOrFail($validated['inventory_item_id']);
        $location = InventoryLocation::query()->findOrFail($validated['inventory_location_id']);

        try {
            $service->issue($item, $location, $validated['quantity'], $context->actor());
        } catch (ItemNotAvailableException|InvalidQuantityException|InsufficientStockException $e) {
            throw ValidationException::withMessages(['inventory_item_id' => [$e->getMessage()]]);
        } catch (LocationNotAvailableException $e) {
            throw ValidationException::withMessages(['inventory_location_id' => [$e->getMessage()]]);
        }

        return redirect('/app/inventory-stock')->with('flash', 'Stock issued.');
    }

    public function storeTransfer(Request $request, TenantContext $context, InventoryStockService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('inventory.stock.manage', $school);

        $validated = $request->validate([
            'inventory_item_id' => ['required', 'string'],
            'from_location_id' => ['required', 'string'],
            'to_location_id' => ['required', 'string'],
            'quantity' => ['required', 'regex:/^\d+(\.\d{1,3})?$/'],
        ]);

        $item = InventoryItem::query()->findOrFail($validated['inventory_item_id']);
        $from = InventoryLocation::query()->findOrFail($validated['from_location_id']);
        $to = InventoryLocation::query()->findOrFail($validated['to_location_id']);

        try {
            $service->transfer($item, $from, $to, $validated['quantity'], $context->actor());
        } catch (SameLocationTransferException $e) {
            throw ValidationException::withMessages(['to_location_id' => [$e->getMessage()]]);
        } catch (ItemNotAvailableException|InvalidQuantityException|InsufficientStockException $e) {
            throw ValidationException::withMessages(['inventory_item_id' => [$e->getMessage()]]);
        } catch (LocationNotAvailableException $e) {
            throw ValidationException::withMessages(['from_location_id' => [$e->getMessage()]]);
        }

        return redirect('/app/inventory-stock')->with('flash', 'Stock transferred.');
    }

    /**
     * @return array{inventory_item_id: string, inventory_location_id: string, quantity: string}
     */
    private function validateMutation(Request $request): array
    {
        return $request->validate([
            'inventory_item_id' => ['required', 'string'],
            'inventory_location_id' => ['required', 'string'],
            'quantity' => ['required', 'regex:/^\d+(\.\d{1,3})?$/'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentBalance(InventoryStockBalance $balance): array
    {
        return [
            'id' => $balance->id,
            'quantityOnHand' => $balance->quantity_on_hand,
            'itemCode' => $balance->item->code,
            'itemName' => $balance->item->name,
            'unitOfMeasure' => $balance->item->unit_of_measure,
            'locationCode' => $balance->location->code,
            'locationName' => $balance->location->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentMovement(StockMovement $movement): array
    {
        return [
            'id' => $movement->id,
            'movementType' => $movement->movement_type,
            'quantity' => $movement->quantity,
            'occurredAt' => $movement->occurred_at->toIso8601String(),
            'itemCode' => $movement->item->code,
            'fromLocationCode' => $movement->fromLocation?->code,
            'toLocationCode' => $movement->toLocation?->code,
        ];
    }
}
