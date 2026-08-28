<?php

namespace App\Http\Controllers\App\Canteen;

use App\Domain\Canteen\Application\CanteenOutletService;
use App\Domain\Canteen\Infrastructure\CanteenOutlet;
use App\Domain\Inventory\Infrastructure\InventoryLocation;
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
 * Outlet directory. Mirrors
 * App\Http\Controllers\App\InventoryItemController's shape exactly.
 */
class CanteenOutletController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function index(Request $request, TenantContext $context, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('canteen.directory.view', $school);

        $validated = $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
        ]);

        $query = CanteenOutlet::query()->with(['campus', 'inventoryLocation'])->where('status', 'active')->orderBy('code');

        if (isset($validated['search'])) {
            $term = '%'.$validated['search'].'%';
            $query->where(fn ($q) => $q->where('code', 'ilike', $term)->orWhere('name', 'ilike', $term));
        }

        $paginator = $query->paginate(20)->withQueryString();

        return Inertia::render('App/Canteen/Outlets/Index', [
            'outlets' => $paginator->through(fn (CanteenOutlet $o) => [
                'id' => $o->id,
                'code' => $o->code,
                'name' => $o->name,
                'status' => $o->status,
                'campusName' => $o->campus?->name,
                'inventoryLocationCode' => $o->inventoryLocation?->code,
                'inventoryLocationName' => $o->inventoryLocation?->name,
            ]),
            'filters' => ['search' => $validated['search'] ?? ''],
            'canManage' => $capabilities->canInSchool($context->actor(), 'canteen.directory.manage', $school),
        ]);
    }

    /**
     * Phase 10F fix -- narrow, read-only, same-School InventoryLocation
     * lookup gated by `canteen.directory.manage` (not
     * `inventory.stock.manage`). Mirrors
     * App\Http\Controllers\App\InventoryStockController::searchLocations()'s
     * shape/projection exactly, but under the Canteen module's own
     * capability so Canteen staff never need a raw Inventory
     * capability just to pick an Outlet's backing Location. Same
     * precedent as CanteenBillingConfigurationService reading
     * LedgerAccount directly, read-only, for display purposes.
     */
    public function searchInventoryLocations(Request $request, TenantContext $context): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('canteen.directory.manage', $school);

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

    public function create(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('canteen.directory.manage', $school);

        return Inertia::render('App/Canteen/Outlets/Create');
    }

    public function store(Request $request, TenantContext $context, CanteenOutletService $service): RedirectResponse
    {
        $school = $context->requireSchool();
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
        ], $context->actor());

        return redirect('/app/canteen-outlets')->with('flash', "Outlet {$outlet->code} created.");
    }

    public function update(Request $request, TenantContext $context, string $canteenOutlet, CanteenOutletService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('canteen.directory.manage', $school);

        $outlet = CanteenOutlet::query()->findOrFail($canteenOutlet);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        $service->update($school, $outlet, $validated, $context->actor());

        return redirect('/app/canteen-outlets')->with('flash', 'Outlet updated.');
    }
}
