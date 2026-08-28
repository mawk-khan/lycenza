<?php

namespace App\Domain\Canteen\Http\Controllers;

use App\Domain\Canteen\Application\CanteenOutletService;
use App\Domain\Canteen\Infrastructure\CanteenOutlet;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\NormalizesCodeInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 10F -- Canteen Outlet directory administrative API. Mirrors
 * App\Domain\Inventory\Http\Controllers\InventoryItemController's shape
 * exactly.
 */
class CanteenOutletController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function index(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('canteen.directory.view', $school);

        $query = CanteenOutlet::query()->orderBy('code');

        if (! $request->boolean('include_inactive')) {
            $query->where('status', 'active');
        }

        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $query->where(fn ($q) => $q->where('code', 'ilike', $term)->orWhere('name', 'ilike', $term));
        }

        $paginator = $query->paginate(20)->withQueryString();

        return response()->json([
            'data' => $paginator->through(fn (CanteenOutlet $o) => $this->present($o))->items(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(Request $request, School $school, CanteenOutletService $service): JsonResponse
    {
        $this->authorizeCapability('canteen.directory.manage', $school);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', Rule::unique('canteen_outlets', 'code')->where('school_id', $school->id)],
            'name' => ['required', 'string', 'max:255'],
            'campus_id' => ['sometimes', 'nullable', 'uuid'],
            'inventory_location_id' => ['required', 'uuid'],
        ]);

        $outlet = $service->create($school, [
            'code' => $validated['code'],
            'name' => $validated['name'],
            'campus_id' => $validated['campus_id'] ?? null,
            'inventory_location_id' => $validated['inventory_location_id'],
        ], $request->user());

        return response()->json(['data' => $this->present($outlet)], 201);
    }

    public function show(School $school, string $canteenOutlet): JsonResponse
    {
        $this->authorizeCapability('canteen.directory.view', $school);

        $outlet = CanteenOutlet::query()->findOrFail($canteenOutlet);

        return response()->json(['data' => $this->present($outlet)]);
    }

    public function update(Request $request, School $school, string $canteenOutlet, CanteenOutletService $service): JsonResponse
    {
        $this->authorizeCapability('canteen.directory.manage', $school);

        $outlet = CanteenOutlet::query()->findOrFail($canteenOutlet);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        $outlet = $service->update($school, $outlet, $validated, $request->user());

        return response()->json(['data' => $this->present($outlet)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CanteenOutlet $outlet): array
    {
        return [
            'id' => $outlet->id,
            'code' => $outlet->code,
            'name' => $outlet->name,
            'campusId' => $outlet->campus_id,
            'inventoryLocationId' => $outlet->inventory_location_id,
            'status' => $outlet->status,
        ];
    }
}
