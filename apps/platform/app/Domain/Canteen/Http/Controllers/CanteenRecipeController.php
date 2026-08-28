<?php

namespace App\Domain\Canteen\Http\Controllers;

use App\Domain\Canteen\Application\CanteenRecipeService;
use App\Domain\Canteen\Infrastructure\CanteenItem;
use App\Domain\Canteen\Infrastructure\CanteenItemInventoryRequirement;
use App\Domain\Inventory\Infrastructure\InventoryItem;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 10F -- recipe (CanteenItemInventoryRequirement) management
 * under a CanteenItem. Every write delegates to CanteenRecipeService,
 * which locks the owning CanteenItem row before mutating -- this
 * controller never writes canteen_item_inventory_requirements
 * directly.
 */
class CanteenRecipeController extends Controller
{
    use AuthorizesCapability;

    public function index(School $school, string $canteenItem): JsonResponse
    {
        $this->authorizeCapability('canteen.directory.view', $school);

        $item = CanteenItem::query()->findOrFail($canteenItem);
        $requirements = $item->inventoryRequirements()->with('inventoryItem')->get();

        return response()->json(['data' => $requirements->map(fn (CanteenItemInventoryRequirement $r) => $this->present($r))->values()->all()]);
    }

    public function store(Request $request, School $school, string $canteenItem, CanteenRecipeService $service): JsonResponse
    {
        $this->authorizeCapability('canteen.directory.manage', $school);

        $item = CanteenItem::query()->findOrFail($canteenItem);

        $validated = $request->validate([
            'inventory_item_id' => ['required', 'uuid'],
            'quantity_required' => ['required', 'regex:/^\d+(\.\d{1,3})?$/'],
        ]);

        $inventoryItem = InventoryItem::query()->findOrFail($validated['inventory_item_id']);

        $requirement = $service->setRequirement($school, $item, $inventoryItem, $validated['quantity_required'], $request->user());

        return response()->json(['data' => $this->present($requirement->load('inventoryItem'))], 201);
    }

    public function update(Request $request, School $school, string $canteenItem, string $requirement, CanteenRecipeService $service): JsonResponse
    {
        $this->authorizeCapability('canteen.directory.manage', $school);

        $item = CanteenItem::query()->findOrFail($canteenItem);
        $requirementModel = CanteenItemInventoryRequirement::query()->where('canteen_item_id', $item->id)->findOrFail($requirement);

        $validated = $request->validate([
            'quantity_required' => ['required', 'regex:/^\d+(\.\d{1,3})?$/'],
        ]);

        $inventoryItem = InventoryItem::query()->findOrFail($requirementModel->inventory_item_id);

        $updated = $service->setRequirement($school, $item, $inventoryItem, $validated['quantity_required'], $request->user());

        return response()->json(['data' => $this->present($updated->load('inventoryItem'))]);
    }

    public function destroy(School $school, string $canteenItem, string $requirement, CanteenRecipeService $service): JsonResponse
    {
        $this->authorizeCapability('canteen.directory.manage', $school);

        $item = CanteenItem::query()->findOrFail($canteenItem);
        $requirementModel = CanteenItemInventoryRequirement::query()->where('canteen_item_id', $item->id)->findOrFail($requirement);

        $service->removeRequirement($school, $item, $requirementModel, request()->user());

        return response()->json(status: 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CanteenItemInventoryRequirement $requirement): array
    {
        return [
            'id' => $requirement->id,
            'inventoryItemId' => $requirement->inventory_item_id,
            'inventoryItemCode' => $requirement->inventoryItem->code,
            'inventoryItemName' => $requirement->inventoryItem->name,
            'quantityRequired' => $requirement->quantity_required,
        ];
    }
}
