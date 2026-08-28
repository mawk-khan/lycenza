<?php

namespace App\Domain\Canteen\Http\Controllers;

use App\Domain\Canteen\Application\CanteenItemService;
use App\Domain\Canteen\Infrastructure\CanteenItem;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\NormalizesCodeInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 10F -- Canteen menu Item catalogue administrative API. Mirrors
 * App\Domain\Inventory\Http\Controllers\InventoryItemController's shape
 * exactly.
 */
class CanteenItemController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function index(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('canteen.directory.view', $school);

        $query = CanteenItem::query()->orderBy('code');

        if (! $request->boolean('include_inactive')) {
            $query->where('status', 'active');
        }

        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $query->where(fn ($q) => $q->where('code', 'ilike', $term)->orWhere('name', 'ilike', $term));
        }

        $paginator = $query->paginate(20)->withQueryString();

        return response()->json([
            'data' => $paginator->through(fn (CanteenItem $i) => $this->present($i))->items(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(Request $request, School $school, CanteenItemService $service): JsonResponse
    {
        $this->authorizeCapability('canteen.directory.manage', $school);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', Rule::unique('canteen_items', 'code')->where('school_id', $school->id)],
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
        ]);

        $item = $service->create($school, $validated, $request->user());

        return response()->json(['data' => $this->present($item)], 201);
    }

    public function show(School $school, string $canteenItem): JsonResponse
    {
        $this->authorizeCapability('canteen.directory.view', $school);

        $item = CanteenItem::query()->with('inventoryRequirements.inventoryItem')->findOrFail($canteenItem);

        return response()->json(['data' => $this->presentWithRecipe($item)]);
    }

    public function update(Request $request, School $school, string $canteenItem, CanteenItemService $service): JsonResponse
    {
        $this->authorizeCapability('canteen.directory.manage', $school);

        $item = CanteenItem::query()->findOrFail($canteenItem);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'price' => ['sometimes', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        $item = $service->update($school, $item, $validated, $request->user());

        return response()->json(['data' => $this->present($item)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CanteenItem $item): array
    {
        return [
            'id' => $item->id,
            'code' => $item->code,
            'name' => $item->name,
            'price' => $item->price,
            'currency' => $item->currency,
            'status' => $item->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentWithRecipe(CanteenItem $item): array
    {
        return [
            ...$this->present($item),
            'recipe' => $item->inventoryRequirements->map(fn ($r) => [
                'id' => $r->id,
                'inventoryItemId' => $r->inventory_item_id,
                'inventoryItemCode' => $r->inventoryItem->code,
                'inventoryItemName' => $r->inventoryItem->name,
                'quantityRequired' => $r->quantity_required,
            ])->values()->all(),
        ];
    }
}
