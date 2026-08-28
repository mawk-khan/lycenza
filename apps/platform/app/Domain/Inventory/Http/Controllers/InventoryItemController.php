<?php

namespace App\Domain\Inventory\Http\Controllers;

use App\Domain\Inventory\Infrastructure\InventoryItem;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\NormalizesCodeInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Phase 10E -- Inventory Item catalogue administrative API. Mirrors
 * App\Domain\Hostel\Http\Controllers\HostelController's shape exactly.
 */
class InventoryItemController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function index(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('inventory.directory.view', $school);

        $query = InventoryItem::query()->orderBy('code');

        if (! $request->boolean('include_inactive')) {
            $query->where('status', 'active');
        }

        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $query->where(fn ($q) => $q->where('code', 'ilike', $term)->orWhere('name', 'ilike', $term));
        }

        $paginator = $query->paginate(20)->withQueryString();

        return response()->json([
            'data' => $paginator->through(fn (InventoryItem $i) => $this->present($i))->items(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('inventory.directory.manage', $school);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', Rule::unique('inventory_items', 'code')->where('school_id', $school->id)],
            'name' => ['required', 'string', 'max:255'],
            'unit_of_measure' => ['required', Rule::in(['each', 'box', 'packet', 'kg', 'litre'])],
        ]);

        $item = DB::transaction(function () use ($school, $validated, $request) {
            $item = InventoryItem::query()->create([
                ...$validated,
                'school_id' => $school->id,
                'status' => 'active',
            ]);

            app(AuditRecorder::class)->school($school, 'inventory.item.created', actor: $request->user(), subject: $item, metadata: [
                'itemId' => $item->id,
                'code' => $item->code,
                'unitOfMeasure' => $item->unit_of_measure,
            ]);

            return $item;
        });

        return response()->json(['data' => $this->present($item)], 201);
    }

    public function show(School $school, string $inventoryItem): JsonResponse
    {
        $this->authorizeCapability('inventory.directory.view', $school);

        $model = InventoryItem::query()->findOrFail($inventoryItem);

        return response()->json(['data' => $this->present($model)]);
    }

    public function update(Request $request, School $school, string $inventoryItem): JsonResponse
    {
        $this->authorizeCapability('inventory.directory.manage', $school);

        $model = InventoryItem::query()->findOrFail($inventoryItem);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        $before = $model->only(array_keys($validated));
        $model->update($validated);

        app(AuditRecorder::class)->school($school, 'inventory.item.updated', actor: $request->user(), subject: $model, metadata: [
            'itemId' => $model->id,
            'statusBefore' => $before['status'] ?? null,
            'statusAfter' => $validated['status'] ?? null,
        ]);

        return response()->json(['data' => $this->present($model->refresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(InventoryItem $item): array
    {
        return [
            'id' => $item->id,
            'code' => $item->code,
            'name' => $item->name,
            'unitOfMeasure' => $item->unit_of_measure,
            'allowsFractionalQuantity' => $item->allowsFractionalQuantity(),
            'status' => $item->status,
        ];
    }
}
