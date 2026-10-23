<?php

namespace App\Http\Controllers\App;

use App\Domain\HR\Application\DepartmentReference;
use App\Domain\Inventory\Application\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Application\Exceptions\InvalidQuantityException;
use App\Domain\Inventory\Application\Exceptions\IssueRecipientNotAvailableException;
use App\Domain\Inventory\Application\Exceptions\ItemNotAvailableException;
use App\Domain\Inventory\Application\Exceptions\LocationNotAvailableException;
use App\Domain\Inventory\Application\Exceptions\SameLocationTransferException;
use App\Domain\Inventory\Application\Exceptions\StockRequestAlreadySubmittedException;
use App\Domain\Inventory\Application\InventoryStockAccess;
use App\Domain\Inventory\Application\InventoryStockHistory;
use App\Domain\Inventory\Application\InventoryStockService;
use App\Domain\Inventory\Application\WriteOffReason;
use App\Domain\Inventory\Infrastructure\InventoryItem;
use App\Domain\Inventory\Infrastructure\InventoryLocation;
use App\Domain\Inventory\Infrastructure\InventoryStockBalance;
use App\Domain\Inventory\Infrastructure\StockMovement;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Models\School;
use App\Support\Auth\Mfa\FreshMfaRequirement;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use App\Support\Authorization\Campus\CampusScope;
use App\Support\Authorization\Campus\CampusScopeResolver;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Phase 10E -- session-authenticated Inertia pages for Inventory
 * stock: current balances, movement history, and the
 * receive/issue/transfer forms. Every mutation delegates to
 * InventoryStockService, the exact same service the JSON API
 * controller uses. The Item/Location search endpoints below explicitly
 * call authorizeCapability() BEFORE querying -- carrying forward the
 * Library/Transport/Visitor/Hostel anti-P1 precedent.
 */
class InventoryStockController extends Controller
{
    use AuthorizesCampusScope;

    /** ORG.9C-2: the form keys a session remembers per form (open tabs, retries). */
    private const FORM_KEYS_KEPT = 10;

    public function index(Request $request, TenantContext $context, CampusScopeResolver $scopes, InventoryStockAccess $access, DepartmentReference $departmentNames): Response
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('inventory.stock.view', $school);

        $balances = $access->balances($scope)
            ->with(['item', 'location'])
            ->orderBy('id')
            ->paginate(20, ['*'], 'balancesPage')
            ->withQueryString();

        $validated = $request->validate([
            'movement_type' => ['sometimes', 'nullable', Rule::in(StockMovement::TYPES)],
        ]);
        $type = $validated['movement_type'] ?? null;

        $movements = $access->movements($scope)
            ->with(['item', 'fromLocation', 'toLocation'])
            ->when($type !== null, fn ($q) => $q->where('movement_type', $type))
            ->orderByDesc('occurred_at')
            ->paginate(20, ['*'], 'movementsPage')
            ->withQueryString();
        // ORG.9C-2: an issue's Department recipient, by name, in one read (never per row).
        $departments = $departmentNames->names($school, $movements->getCollection()->pluck('recipient_department_id')->filter()->unique()->values()->all());

        return Inertia::render('App/Inventory/Stock/Index', [
            'balances' => $balances->through(fn (InventoryStockBalance $b) => $this->presentBalance($b)),
            'movements' => $movements->through(fn (StockMovement $m) => $this->presentMovement($m, $departments)),
            'movementType' => $type,
            'writeOffReasons' => WriteOffReason::LABELS,
            'canManage' => ! $scopes->campusScope($context->actor(), 'inventory.stock.manage', $school)->isNone(),
        ]);
    }

    /** ORG.9C-3 (ADR 0076 §71): the stock register -- INV-016 (by Campus) and INV-023 (by Location). */
    public function register(Request $request, TenantContext $context, InventoryStockAccess $access, InventoryStockHistory $history): Response
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('inventory.stock.view', $school);

        $filters = $request->validate([
            'campus_id' => ['nullable', 'uuid'],
            'location_id' => ['nullable', 'uuid'],
            'item' => ['nullable', 'string', 'max:100'],
            'movement_type' => ['nullable', Rule::in(StockMovement::TYPES)],
            // ORG.9C-3 review LOW-1: bounded, so a School-local date always converts to a storable UTC timestamp.
            'from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2999-12-31'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from', 'after_or_equal:2000-01-01', 'before_or_equal:2999-12-31'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        return Inertia::render('App/Inventory/Stock/Register', [
            'rows' => $history->register($school, $scope, $filters, (int) ($filters['page'] ?? 1))->withQueryString(),
            'filters' => array_merge(['campus_id' => null, 'location_id' => null, 'item' => null, 'movement_type' => null, 'from' => null, 'to' => null], array_diff_key($filters, ['page' => 1])),
            'options' => $this->scopeOptions($school, $scope, $access),
            'writeOffReasons' => WriteOffReason::LABELS,
        ]);
    }

    /** ORG.9C-3 (ADR 0076 §71): INV-020 -- opening, in, out and closing per Item for one School-local month. */
    public function monthly(Request $request, TenantContext $context, InventoryStockAccess $access, InventoryStockHistory $history): Response
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('inventory.stock.view', $school);

        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m', 'after_or_equal:2000-01'],
            'campus_id' => ['nullable', 'uuid'],
            'location_id' => ['nullable', 'uuid'],
            'item' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);
        $month = $filters['month'] ?? substr(SchoolTimezone::today($school), 0, 7);

        try {
            $summary = $history->month($school, $scope, $month, $filters, (int) ($filters['page'] ?? 1));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['month' => [$e->getMessage()]]);
        }
        $summary['rows'] = $summary['rows']->withPath('/app/inventory-stock/monthly')->appends(array_diff_key($filters, ['page' => 1]));

        return Inertia::render('App/Inventory/Stock/Monthly', [
            'summary' => $summary,
            'filters' => array_merge(['campus_id' => null, 'location_id' => null, 'item' => null], array_diff_key($filters, ['page' => 1, 'month' => 1]), ['month' => $month]),
            'options' => $this->scopeOptions($school, $scope, $access),
        ]);
    }

    /**
     * The Campus and Location filter options -- only the reader's own scope
     * (never a School-wide store for a Campus reader). A filter is never authority.
     *
     * @return array{campuses: list<array{id: string, name: string}>, locations: list<array{id: string, code: string, name: string}>}
     */
    private function scopeOptions(School $school, CampusScope $scope, InventoryStockAccess $access): array
    {
        $locations = $access->locations($scope)->orderBy('code')->get(['id', 'code', 'name', 'campus_id']);

        return [
            'campuses' => Campus::query()->where('school_id', $school->id)->whereIn('id', $locations->pluck('campus_id')->filter()->unique()->values())
                ->orderBy('name')->get(['id', 'name'])->map(fn (Campus $c) => ['id' => $c->id, 'name' => $c->name])->values()->all(),
            'locations' => $locations->map(fn (InventoryLocation $l) => ['id' => $l->id, 'code' => $l->code, 'name' => $l->name])->values()->all(),
        ];
    }

    public function createReceive(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCampusScope('inventory.stock.manage', $school);

        return Inertia::render('App/Inventory/Stock/Receive');
    }

    public function createIssue(Request $request, TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCampusScope('inventory.stock.manage', $school);

        // ORG.9C-2: a server-issued key the submission is claimed on (a retry replays, never re-issues).
        return Inertia::render('App/Inventory/Stock/Issue', ['idempotencyKey' => $this->issueFormKey($request, 'issue')]);
    }

    /** ORG.9C-2 (ADR 0076 §70): the write-off form -- reasons, the MFA step-up and its own key. */
    public function createWriteOff(Request $request, TenantContext $context, FreshMfaRequirement $mfa): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCampusScope('inventory.stock.manage', $school);

        return Inertia::render('App/Inventory/Stock/WriteOff', [
            'reasons' => WriteOffReason::LABELS,
            'hasMfaFactor' => $mfa->hasActiveFactor($context->actor()),
            'idempotencyKey' => $this->issueFormKey($request, 'write_off'),
        ]);
    }

    public function createTransfer(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCampusScope('inventory.stock.manage', $school);

        return Inertia::render('App/Inventory/Stock/Transfer');
    }

    public function searchItems(Request $request, TenantContext $context): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCampusScope('inventory.stock.manage', $school);

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

    public function searchLocations(Request $request, TenantContext $context, InventoryStockAccess $access): JsonResponse
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('inventory.stock.manage', $school);

        $term = (string) $request->query('q', '');

        $locations = $access->searchLocations($scope, $term)->limit(10)->get();

        return response()->json(['data' => $locations->map(fn (InventoryLocation $l) => [
            'id' => $l->id,
            'code' => $l->code,
            'name' => $l->name,
        ])->all()]);
    }

    /** ORG.9C-2: active HR Departments an issue may name -- School-wide ones and those on the actor's in-scope Campuses. */
    public function searchDepartments(Request $request, TenantContext $context, DepartmentReference $departments): JsonResponse
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('inventory.stock.manage', $school);

        return response()->json(['data' => $departments->pickable($school, $scope->isWholeSchool() ? null : $scope->campusIds(), (string) $request->query('q', ''))]);
    }

    public function storeReceive(Request $request, TenantContext $context, InventoryStockService $service, InventoryStockAccess $access): RedirectResponse
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('inventory.stock.manage', $school);

        $validated = $this->validateMutation($request);

        $item = InventoryItem::query()->findOrFail($validated['inventory_item_id']);
        $location = $access->location($scope, $validated['inventory_location_id']);

        try {
            $service->receive($item, $location, $validated['quantity'], $context->actor());
        } catch (ItemNotAvailableException|InvalidQuantityException $e) {
            throw ValidationException::withMessages(['inventory_item_id' => [$e->getMessage()]]);
        } catch (LocationNotAvailableException $e) {
            throw ValidationException::withMessages(['inventory_location_id' => [$e->getMessage()]]);
        }

        return redirect('/app/inventory-stock')->with('flash', 'Stock received.');
    }

    public function storeIssue(Request $request, TenantContext $context, InventoryStockService $service, InventoryStockAccess $access): RedirectResponse
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('inventory.stock.manage', $school);

        $validated = $request->validate([
            ...$this->mutationRules(),
            'recipient_department_id' => ['nullable', 'uuid'],
            'idempotency_key' => ['required', 'uuid'],
        ]);

        $item = InventoryItem::query()->findOrFail($validated['inventory_item_id']);
        $location = $access->location($scope, $validated['inventory_location_id']);
        $this->requireIssuedFormKey($request, 'issue', $validated['idempotency_key']);

        try {
            $service->issue($item, $location, $validated['quantity'], $context->actor(), $validated['recipient_department_id'] ?? null, $validated['idempotency_key']);
        } catch (ItemNotAvailableException|InvalidQuantityException|InsufficientStockException|StockRequestAlreadySubmittedException $e) {
            throw ValidationException::withMessages(['inventory_item_id' => [$e->getMessage()]]);
        } catch (LocationNotAvailableException $e) {
            throw ValidationException::withMessages(['inventory_location_id' => [$e->getMessage()]]);
        } catch (IssueRecipientNotAvailableException $e) {
            throw ValidationException::withMessages(['recipient_department_id' => [$e->getMessage()]]);
        }

        return redirect('/app/inventory-stock')->with('flash', 'Stock issued.');
    }

    /**
     * ORG.9C-2 (ADR 0076 §70): DIRECT_WITH_ASSURANCE. Order: the Campus
     * scope (route floor + this check), the Location within it, input, the
     * explicit confirmation (UX assurance only), then the fresh MFA step-up
     * OUTSIDE the business transaction (rule 95). The service re-reads the
     * actor's authority under its locks.
     */
    public function storeWriteOff(Request $request, TenantContext $context, InventoryStockService $service, InventoryStockAccess $access, FreshMfaRequirement $mfa): RedirectResponse
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('inventory.stock.manage', $school);

        $validated = $request->validate([
            ...$this->mutationRules(),
            'reason_code' => ['required', 'string', Rule::in(WriteOffReason::CODES)],
            'confirm' => ['accepted'],
            'idempotency_key' => ['required', 'uuid'],
        ]);

        $item = InventoryItem::query()->findOrFail($validated['inventory_item_id']);
        $location = $access->location($scope, $validated['inventory_location_id']);
        $this->requireIssuedFormKey($request, 'write_off', $validated['idempotency_key']);
        $proof = $mfa->requireForAction($request, $context->actor());

        try {
            $service->writeOff($school, $item, $location, $validated['quantity'], $validated['reason_code'], $context->actor(), $proof, $validated['idempotency_key']);
        } catch (ItemNotAvailableException|InvalidQuantityException|InsufficientStockException|StockRequestAlreadySubmittedException $e) {
            throw ValidationException::withMessages(['inventory_item_id' => [$e->getMessage()]]);
        } catch (LocationNotAvailableException $e) {
            throw ValidationException::withMessages(['inventory_location_id' => [$e->getMessage()]]);
        }

        return redirect('/app/inventory-stock')->with('flash', 'Stock written off.');
    }

    public function storeTransfer(Request $request, TenantContext $context, InventoryStockService $service, InventoryStockAccess $access): RedirectResponse
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('inventory.stock.manage', $school);

        $validated = $request->validate([
            'inventory_item_id' => ['required', 'string'],
            'from_location_id' => ['required', 'string'],
            'to_location_id' => ['required', 'string'],
            'quantity' => ['required', 'regex:/^\d+(\.\d{1,3})?$/'],
        ]);

        $item = InventoryItem::query()->findOrFail($validated['inventory_item_id']);
        $from = $access->location($scope, $validated['from_location_id']);
        $to = $access->location($scope, $validated['to_location_id']);

        try {
            $service->transfer($item, $from, $to, $validated['quantity'], $context->actor());
        } catch (SameLocationTransferException $e) {
            throw ValidationException::withMessages(['to_location_id' => [$e->getMessage()]]);
        } catch (ItemNotAvailableException|InvalidQuantityException|InsufficientStockException $e) {
            throw ValidationException::withMessages(['inventory_item_id' => [$e->getMessage()]]);
        } catch (LocationNotAvailableException $e) {
            throw ValidationException::withMessages(['from_location_id' => [$e->getMessage()]]);
        }

        return redirect('/app/inventory-stock')->with('flash', 'Stock transferred.');
    }

    /**
     * ORG.9C-2 security review L1: a form key is minted server-side and
     * remembered in THIS session (the last few per form), so a submission
     * can only claim a key its own session was given -- another User cannot
     * replay someone else's recorded movement as their own success. A key
     * stays valid for retries of the same form.
     */
    private function issueFormKey(Request $request, string $form): string
    {
        $key = (string) Str::uuid();
        $keys = (array) $request->session()->get("inventory.form_keys.{$form}", []);
        $request->session()->put("inventory.form_keys.{$form}", array_slice([...$keys, $key], -self::FORM_KEYS_KEPT));

        return $key;
    }

    private function requireIssuedFormKey(Request $request, string $form, string $key): void
    {
        if (! in_array($key, (array) $request->session()->get("inventory.form_keys.{$form}", []), true)) {
            throw ValidationException::withMessages(['idempotency_key' => ['This form has expired. Reload the page and try again.']]);
        }
    }

    /**
     * @return array{inventory_item_id: string, inventory_location_id: string, quantity: string}
     */
    private function validateMutation(Request $request): array
    {
        return $request->validate($this->mutationRules());
    }

    /** @return array<string, list<string>> */
    private function mutationRules(): array
    {
        return [
            'inventory_item_id' => ['required', 'string'],
            'inventory_location_id' => ['required', 'string'],
            'quantity' => ['required', 'regex:/^\d+(\.\d{1,3})?$/'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentBalance(InventoryStockBalance $balance): array
    {
        return [
            'id' => $balance->id,
            'quantityOnHand' => $balance->quantity_on_hand,
            'itemCode' => $balance->item->code,
            'itemName' => $balance->item->name,
            'unitOfMeasure' => $balance->item->unit_of_measure,
            'locationCode' => $balance->location->code,
            'locationName' => $balance->location->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, string>  $departments  id => name
     * @return array<string, mixed>
     */
    private function presentMovement(StockMovement $movement, array $departments): array
    {
        return [
            'id' => $movement->id,
            'movementType' => $movement->movement_type,
            'quantity' => $movement->quantity,
            'occurredAt' => $movement->occurred_at->toIso8601String(),
            'itemCode' => $movement->item->code,
            'fromLocationCode' => $movement->fromLocation?->code,
            'toLocationCode' => $movement->toLocation?->code,
            'writeOffReason' => $movement->write_off_reason,
            'recipientDepartment' => $movement->recipient_department_id === null ? null : ($departments[$movement->recipient_department_id] ?? null),
        ];
    }
}
