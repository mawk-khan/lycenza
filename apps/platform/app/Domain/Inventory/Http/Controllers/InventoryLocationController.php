<?php

namespace App\Domain\Inventory\Http\Controllers;

use App\Domain\Inventory\Application\InventoryLocationService;
use App\Domain\Inventory\Infrastructure\InventoryLocation;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\NormalizesCodeInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Phase 10E -- Inventory Location directory administrative API.
 * Mirrors App\Domain\Hostel\Http\Controllers\HostelController's shape
 * exactly. `campus_id` is optional (docs/modules/INVENTORY.md
 * "Campus / Location ownership"). Writes go through
 * InventoryLocationService (ORG.7H-1: a used Location's Campus is frozen).
 */
class InventoryLocationController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function index(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('inventory.directory.view', $school);

        $query = InventoryLocation::query()->orderBy('code');

        if (! $request->boolean('include_inactive')) {
            $query->where('status', 'active');
        }

        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $query->where(fn ($q) => $q->where('code', 'ilike', $term)->orWhere('name', 'ilike', $term));
        }

        $paginator = $query->paginate(20)->withQueryString();

        return response()->json([
            'data' => $paginator->through(fn (InventoryLocation $l) => $this->present($l))->items(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(Request $request, School $school, InventoryLocationService $service): JsonResponse
    {
        $this->authorizeCapability('inventory.directory.manage', $school);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', Rule::unique('inventory_locations', 'code')->where('school_id', $school->id)],
            'name' => ['required', 'string', 'max:255'],
            'campus_id' => ['nullable', 'string'],
        ]);

        $this->assertCampusBelongsToSchool($school, $validated['campus_id'] ?? null);

        $location = $service->create($school, $validated, $request->user());

        return response()->json(['data' => $this->present($location)], 201);
    }

    public function show(School $school, string $inventoryLocation): JsonResponse
    {
        $this->authorizeCapability('inventory.directory.view', $school);

        $model = InventoryLocation::query()->findOrFail($inventoryLocation);

        return response()->json(['data' => $this->present($model)]);
    }

    public function update(Request $request, School $school, string $inventoryLocation, InventoryLocationService $service): JsonResponse
    {
        $this->authorizeCapability('inventory.directory.manage', $school);

        InventoryLocation::query()->findOrFail($inventoryLocation);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'campus_id' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        if (array_key_exists('campus_id', $validated)) {
            $this->assertCampusBelongsToSchool($school, $validated['campus_id']);
        }

        // ORG.7H-1 (ADR 0076 §54): a used Location's Campus is frozen (409).
        return response()->json(['data' => $this->present($service->update($school, $inventoryLocation, $validated, $request->user()))]);
    }

    private function assertCampusBelongsToSchool(School $school, ?string $campusId): void
    {
        if ($campusId === null) {
            return;
        }

        if (Campus::query()->where('id', $campusId)->doesntExist()) {
            throw ValidationException::withMessages(['campus_id' => ['This Campus does not belong to this School.']]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(InventoryLocation $location): array
    {
        return [
            'id' => $location->id,
            'campusId' => $location->campus_id,
            'code' => $location->code,
            'name' => $location->name,
            'status' => $location->status,
        ];
    }
}
