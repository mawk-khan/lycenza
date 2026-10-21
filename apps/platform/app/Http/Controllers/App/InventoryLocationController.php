<?php

namespace App\Http\Controllers\App;

use App\Domain\Inventory\Application\InventoryLocationService;
use App\Domain\Inventory\Infrastructure\InventoryLocation;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\NormalizesCodeInput;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 10E -- session-authenticated Inertia pages for the Inventory
 * Location directory. Mirrors
 * App\Http\Controllers\App\VisitorController's shape exactly. Writes go
 * through InventoryLocationService (ORG.7H-1); this page never edits a
 * Location's Campus.
 */
class InventoryLocationController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function index(Request $request, TenantContext $context, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('inventory.directory.view', $school);

        $validated = $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
        ]);

        $query = InventoryLocation::query()->where('status', 'active')->orderBy('code');

        if (isset($validated['search'])) {
            $term = '%'.$validated['search'].'%';
            $query->where(fn ($q) => $q->where('code', 'ilike', $term)->orWhere('name', 'ilike', $term));
        }

        $paginator = $query->paginate(20)->withQueryString();

        return Inertia::render('App/Inventory/Locations/Index', [
            'locations' => $paginator->through(fn (InventoryLocation $l) => [
                'id' => $l->id,
                'code' => $l->code,
                'name' => $l->name,
                'campusId' => $l->campus_id,
                'status' => $l->status,
            ]),
            'filters' => ['search' => $validated['search'] ?? ''],
            'canManage' => $capabilities->canInSchool($context->actor(), 'inventory.directory.manage', $school),
        ]);
    }

    public function create(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('inventory.directory.manage', $school);

        return Inertia::render('App/Inventory/Locations/Create', [
            'campuses' => Campus::query()->orderBy('name')->get()
                ->map(fn (Campus $c) => ['id' => $c->id, 'name' => $c->name])
                ->all(),
        ]);
    }

    public function store(Request $request, TenantContext $context, InventoryLocationService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('inventory.directory.manage', $school);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', Rule::unique('inventory_locations', 'code')->where('school_id', $school->id)],
            'name' => ['required', 'string', 'max:255'],
            'campus_id' => ['nullable', 'string'],
        ]);

        $this->assertCampusBelongsToSchool($validated['campus_id'] ?? null);

        $location = $service->create($school, $validated, $context->actor());

        return redirect('/app/inventory-locations')->with('flash', "Location {$location->code} created.");
    }

    public function update(Request $request, TenantContext $context, InventoryLocationService $service, string $inventoryLocation): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('inventory.directory.manage', $school);

        InventoryLocation::query()->findOrFail($inventoryLocation);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        $service->update($school, $inventoryLocation, $validated, $context->actor());

        return redirect('/app/inventory-locations')->with('flash', 'Location updated.');
    }

    private function assertCampusBelongsToSchool(?string $campusId): void
    {
        if ($campusId === null) {
            return;
        }

        if (Campus::query()->where('id', $campusId)->doesntExist()) {
            throw ValidationException::withMessages(['campus_id' => ['This Campus does not belong to this School.']]);
        }
    }
}
