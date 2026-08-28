<?php

namespace App\Domain\Inventory\Http\Controllers;

use App\Domain\Inventory\Application\InventoryStockService;
use App\Domain\Inventory\Infrastructure\InventoryItem;
use App\Domain\Inventory\Infrastructure\InventoryLocation;
use App\Domain\Inventory\Infrastructure\InventoryStockBalance;
use App\Domain\Inventory\Infrastructure\StockMovement;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 10E -- current-stock reads and the three command-style stock
 * mutations. Every mutation delegates to InventoryStockService, the
 * ONE authoritative write path -- this controller never touches
 * `inventory_stock_balances`/`stock_movements` directly and never
 * accepts an arbitrary movement type (checkpoint brief section 37: no
 * generic `POST /stock-movements`). `receive`/`issue`/`transfer` carry
 * the `idempotent` middleware in routes/api.php; `index`/`movements`
 * do not (reads).
 */
class InventoryStockController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('inventory.stock.view', $school);

        $validated = $request->validate([
            'inventory_item_id' => ['sometimes', 'string'],
            'inventory_location_id' => ['sometimes', 'string'],
        ]);

        $query = InventoryStockBalance::query()->with(['item', 'location'])->orderBy('id');

        if (isset($validated['inventory_item_id'])) {
            $query->where('inventory_item_id', $validated['inventory_item_id']);
        }

        if (isset($validated['inventory_location_id'])) {
            $query->where('inventory_location_id', $validated['inventory_location_id']);
        }

        $paginator = $query->paginate(20)->withQueryString();

        return response()->json([
            'data' => $paginator->through(fn (InventoryStockBalance $b) => $this->presentBalance($b))->items(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function movements(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('inventory.stock.view', $school);

        $validated = $request->validate([
            'inventory_item_id' => ['sometimes', 'string'],
            'inventory_location_id' => ['sometimes', 'string'],
            'movement_type' => ['sometimes', Rule::in(['receipt', 'issue', 'transfer'])],
        ]);

        $query = StockMovement::query()->with(['item', 'fromLocation', 'toLocation'])->orderByDesc('occurred_at');

        if (isset($validated['inventory_item_id'])) {
            $query->where('inventory_item_id', $validated['inventory_item_id']);
        }

        if (isset($validated['inventory_location_id'])) {
            $query->where(fn ($q) => $q
                ->where('from_location_id', $validated['inventory_location_id'])
                ->orWhere('to_location_id', $validated['inventory_location_id']));
        }

        if (isset($validated['movement_type'])) {
            $query->where('movement_type', $validated['movement_type']);
        }

        $paginator = $query->paginate(20)->withQueryString();

        return response()->json([
            'data' => $paginator->through(fn (StockMovement $m) => $this->presentMovement($m))->items(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function receive(Request $request, School $school, InventoryStockService $service): JsonResponse
    {
        $this->authorizeCapability('inventory.stock.manage', $school);

        $validated = $this->validateMutation($request);

        $item = InventoryItem::query()->findOrFail($validated['inventory_item_id']);
        $location = InventoryLocation::query()->findOrFail($validated['inventory_location_id']);

        $movement = $service->receive($item, $location, $validated['quantity'], $request->user());

        return response()->json(['data' => $this->presentMovement($movement->load(['item', 'toLocation']))], 201);
    }

    public function issue(Request $request, School $school, InventoryStockService $service): JsonResponse
    {
        $this->authorizeCapability('inventory.stock.manage', $school);

        $validated = $this->validateMutation($request);

        $item = InventoryItem::query()->findOrFail($validated['inventory_item_id']);
        $location = InventoryLocation::query()->findOrFail($validated['inventory_location_id']);

        $movement = $service->issue($item, $location, $validated['quantity'], $request->user());

        return response()->json(['data' => $this->presentMovement($movement->load(['item', 'fromLocation']))], 201);
    }

    public function transfer(Request $request, School $school, InventoryStockService $service): JsonResponse
    {
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

        $movement = $service->transfer($item, $from, $to, $validated['quantity'], $request->user());

        return response()->json(['data' => $this->presentMovement($movement->load(['item', 'fromLocation', 'toLocation']))], 201);
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
            'item' => ['id' => $balance->item->id, 'code' => $balance->item->code, 'name' => $balance->item->name, 'unitOfMeasure' => $balance->item->unit_of_measure],
            'location' => ['id' => $balance->location->id, 'code' => $balance->location->code, 'name' => $balance->location->name],
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
            'item' => ['id' => $movement->item->id, 'code' => $movement->item->code, 'name' => $movement->item->name],
            'fromLocation' => $movement->fromLocation ? ['id' => $movement->fromLocation->id, 'code' => $movement->fromLocation->code] : null,
            'toLocation' => $movement->toLocation ? ['id' => $movement->toLocation->id, 'code' => $movement->toLocation->code] : null,
        ];
    }
}
