<?php

namespace App\Http\Controllers\App\Canteen;

use App\Domain\Canteen\Application\CanteenItemService;
use App\Domain\Canteen\Application\CanteenRecipeService;
use App\Domain\Canteen\Infrastructure\CanteenItem;
use App\Domain\Canteen\Infrastructure\CanteenItemInventoryRequirement;
use App\Domain\Inventory\Infrastructure\InventoryItem;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\NormalizesCodeInput;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 10F -- session-authenticated Inertia pages for the Canteen
 * menu Item catalogue AND its recipe (CanteenItemInventoryRequirement)
 * composition. Mirrors
 * App\Http\Controllers\App\InventoryItemController's shape, extended
 * with recipe endpoints.
 */
class CanteenItemController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function index(Request $request, TenantContext $context, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('canteen.directory.view', $school);

        $validated = $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
        ]);

        $query = CanteenItem::query()->where('status', 'active')->orderBy('code');

        if (isset($validated['search'])) {
            $term = '%'.$validated['search'].'%';
            $query->where(fn ($q) => $q->where('code', 'ilike', $term)->orWhere('name', 'ilike', $term));
        }

        $paginator = $query->paginate(20)->withQueryString();

        return Inertia::render('App/Canteen/Items/Index', [
            'items' => $paginator->through(fn (CanteenItem $i) => [
                'id' => $i->id,
                'code' => $i->code,
                'name' => $i->name,
                'price' => $i->price,
                'status' => $i->status,
            ]),
            'filters' => ['search' => $validated['search'] ?? ''],
            'canManage' => $capabilities->canInSchool($context->actor(), 'canteen.directory.manage', $school),
        ]);
    }

    public function create(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('canteen.directory.manage', $school);

        return Inertia::render('App/Canteen/Items/Create');
    }

    public function store(Request $request, TenantContext $context, CanteenItemService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('canteen.directory.manage', $school);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', Rule::unique('canteen_items', 'code')->where('school_id', $school->id)],
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
        ]);

        $item = $service->create($school, $validated, $context->actor());

        return redirect("/app/canteen-items/{$item->id}")->with('flash', "Item {$item->code} created.");
    }

    public function show(TenantContext $context, CapabilityResolver $capabilities, string $canteenItem): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('canteen.directory.view', $school);

        $item = CanteenItem::query()->with('inventoryRequirements.inventoryItem')->findOrFail($canteenItem);

        return Inertia::render('App/Canteen/Items/Show', [
            'item' => [
                'id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'price' => $item->price,
                'currency' => $item->currency,
                'status' => $item->status,
                'recipe' => $item->inventoryRequirements->map(fn (CanteenItemInventoryRequirement $r) => [
                    'id' => $r->id,
                    'inventoryItemId' => $r->inventory_item_id,
                    'inventoryItemCode' => $r->inventoryItem->code,
                    'inventoryItemName' => $r->inventoryItem->name,
                    'quantityRequired' => $r->quantity_required,
                ])->values()->all(),
            ],
            'canManage' => $capabilities->canInSchool($context->actor(), 'canteen.directory.manage', $school),
        ]);
    }

    public function update(Request $request, TenantContext $context, string $canteenItem, CanteenItemService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('canteen.directory.manage', $school);

        $item = CanteenItem::query()->findOrFail($canteenItem);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'price' => ['sometimes', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        $service->update($school, $item, $validated, $context->actor());

        return redirect("/app/canteen-items/{$item->id}")->with('flash', 'Item updated.');
    }

    /**
     * Phase 10F fix -- narrow, read-only, same-School InventoryItem
     * lookup gated by `canteen.directory.manage` (not
     * `inventory.stock.manage`). Mirrors
     * App\Http\Controllers\App\InventoryStockController::searchItems()'s
     * shape/projection exactly, but under the Canteen module's own
     * capability so composing a recipe never requires a raw Inventory
     * capability. Same precedent as CanteenBillingConfigurationService
     * reading LedgerAccount directly, read-only, for display purposes.
     */
    public function searchInventoryItems(Request $request, TenantContext $context): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('canteen.directory.manage', $school);

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

    public function storeRequirement(Request $request, TenantContext $context, string $canteenItem, CanteenRecipeService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('canteen.directory.manage', $school);

        $item = CanteenItem::query()->findOrFail($canteenItem);

        $validated = $request->validate([
            'inventory_item_id' => ['required', 'uuid'],
            'quantity_required' => ['required', 'regex:/^\d+(\.\d{1,3})?$/'],
        ]);

        $inventoryItem = InventoryItem::query()->findOrFail($validated['inventory_item_id']);

        $service->setRequirement($school, $item, $inventoryItem, $validated['quantity_required'], $context->actor());

        return redirect("/app/canteen-items/{$item->id}")->with('flash', 'Recipe requirement saved.');
    }

    public function destroyRequirement(TenantContext $context, string $canteenItem, string $requirement, CanteenRecipeService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('canteen.directory.manage', $school);

        $item = CanteenItem::query()->findOrFail($canteenItem);
        $requirementModel = CanteenItemInventoryRequirement::query()->where('canteen_item_id', $item->id)->findOrFail($requirement);

        $service->removeRequirement($school, $item, $requirementModel, $context->actor());

        return redirect("/app/canteen-items/{$item->id}")->with('flash', 'Recipe requirement removed.');
    }
}
