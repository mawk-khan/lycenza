<?php

namespace App\Http\Controllers\App;

use App\Domain\Inventory\Infrastructure\InventoryItem;
use App\Http\Controllers\Controller;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\NormalizesCodeInput;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 10E -- session-authenticated Inertia pages for the Inventory
 * Item catalogue. Mirrors App\Http\Controllers\App\VisitorController's
 * shape exactly (a simple directory with no nested children).
 */
class InventoryItemController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function index(Request $request, TenantContext $context, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('inventory.directory.view', $school);

        $validated = $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
        ]);

        $query = InventoryItem::query()->where('status', 'active')->orderBy('code');

        if (isset($validated['search'])) {
            $term = '%'.$validated['search'].'%';
            $query->where(fn ($q) => $q->where('code', 'ilike', $term)->orWhere('name', 'ilike', $term));
        }

        $paginator = $query->paginate(20)->withQueryString();

        return Inertia::render('App/Inventory/Items/Index', [
            'items' => $paginator->through(fn (InventoryItem $i) => [
                'id' => $i->id,
                'code' => $i->code,
                'name' => $i->name,
                'unitOfMeasure' => $i->unit_of_measure,
                'status' => $i->status,
            ]),
            'filters' => ['search' => $validated['search'] ?? ''],
            'canManage' => $capabilities->canInSchool($context->actor(), 'inventory.directory.manage', $school),
        ]);
    }

    public function create(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('inventory.directory.manage', $school);

        return Inertia::render('App/Inventory/Items/Create');
    }

    public function store(Request $request, TenantContext $context): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('inventory.directory.manage', $school);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', Rule::unique('inventory_items', 'code')->where('school_id', $school->id)],
            'name' => ['required', 'string', 'max:255'],
            'unit_of_measure' => ['required', Rule::in(['each', 'box', 'packet', 'kg', 'litre'])],
        ]);

        $item = DB::transaction(function () use ($school, $validated, $context) {
            $item = InventoryItem::query()->create([...$validated, 'school_id' => $school->id, 'status' => 'active']);

            app(AuditRecorder::class)->school($school, 'inventory.item.created', actor: $context->actor(), subject: $item, metadata: [
                'itemId' => $item->id,
                'code' => $item->code,
                'unitOfMeasure' => $item->unit_of_measure,
            ]);

            return $item;
        });

        return redirect('/app/inventory-items')->with('flash', "Item {$item->code} created.");
    }

    public function update(Request $request, TenantContext $context, string $inventoryItem): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('inventory.directory.manage', $school);

        $model = InventoryItem::query()->findOrFail($inventoryItem);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        $before = $model->only(array_keys($validated));
        $model->update($validated);

        app(AuditRecorder::class)->school($school, 'inventory.item.updated', actor: $context->actor(), subject: $model, metadata: [
            'itemId' => $model->id,
            'statusBefore' => $before['status'] ?? null,
            'statusAfter' => $validated['status'] ?? null,
        ]);

        return redirect('/app/inventory-items')->with('flash', 'Item updated.');
    }
}
