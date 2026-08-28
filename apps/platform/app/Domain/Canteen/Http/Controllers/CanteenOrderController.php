<?php

namespace App\Domain\Canteen\Http\Controllers;

use App\Domain\Canteen\Application\CanteenOrderLineData;
use App\Domain\Canteen\Application\CanteenOrderService;
use App\Domain\Canteen\Application\PlaceCanteenOrderData;
use App\Domain\Canteen\Infrastructure\CanteenItem;
use App\Domain\Canteen\Infrastructure\CanteenOrder;
use App\Domain\Students\Infrastructure\Student;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 10F -- the Canteen Order API. `canteen.orders.manage` alone
 * gates place/fulfill/cancel -- CanteenOrderService::fulfill() itself
 * calls InventoryStockService/ChargeService directly with no internal
 * re-check (see that service's own docblock).
 *
 * Highly Sensitive projection discipline (checkpoint brief, mandatory):
 * `index()` (list/summary) NEVER includes unit_price/line_total/
 * total_amount -- only id/status/student ref/outlet ref/placed_at/
 * fulfilled_at/cancelled_at. Full detail (every money field, every
 * line) is available ONLY from `show()`, under the SAME capability --
 * this is a deliberate transport-layer minimization, not an
 * authorization difference.
 */
class CanteenOrderController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('canteen.orders.view', $school);

        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['pending', 'fulfilled', 'cancelled'])],
            'student_id' => ['sometimes', 'uuid'],
            'outlet_id' => ['sometimes', 'uuid'],
        ]);

        $query = CanteenOrder::query()->orderByDesc('placed_at');

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (isset($validated['student_id'])) {
            $query->where('student_id', $validated['student_id']);
        }
        if (isset($validated['outlet_id'])) {
            $query->where('outlet_id', $validated['outlet_id']);
        }

        $paginator = $query->paginate(20)->withQueryString();

        return response()->json([
            'data' => $paginator->through(fn (CanteenOrder $o) => $this->presentSummary($o))->items(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(School $school, string $canteenOrder): JsonResponse
    {
        $this->authorizeCapability('canteen.orders.view', $school);

        $order = CanteenOrder::query()->with('lines')->findOrFail($canteenOrder);

        return response()->json(['data' => $this->presentDetail($order)]);
    }

    public function store(Request $request, School $school, CanteenOrderService $service): JsonResponse
    {
        $this->authorizeCapability('canteen.orders.manage', $school);

        $validated = $request->validate([
            'student_id' => ['required', 'uuid'],
            'outlet_id' => ['required', 'uuid'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.canteen_item_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $lines = array_map(
            fn (array $line) => new CanteenOrderLineData($line['canteen_item_id'], (int) $line['quantity']),
            $validated['lines'],
        );

        $result = $service->place($school, new PlaceCanteenOrderData(
            studentId: $validated['student_id'],
            outletId: $validated['outlet_id'],
            lines: $lines,
        ), $request->user());

        $order = CanteenOrder::query()->with('lines')->findOrFail($result->orderId);

        return response()->json(['data' => $this->presentDetail($order)], 201);
    }

    public function fulfill(Request $request, School $school, string $canteenOrder, CanteenOrderService $service): JsonResponse
    {
        $this->authorizeCapability('canteen.orders.manage', $school);

        $service->fulfill($school, $canteenOrder, $request->user());

        $order = CanteenOrder::query()->with('lines')->findOrFail($canteenOrder);

        return response()->json(['data' => $this->presentDetail($order)]);
    }

    public function cancel(Request $request, School $school, string $canteenOrder, CanteenOrderService $service): JsonResponse
    {
        $this->authorizeCapability('canteen.orders.manage', $school);

        $service->cancel($school, $canteenOrder, $request->user());

        $order = CanteenOrder::query()->with('lines')->findOrFail($canteenOrder);

        return response()->json(['data' => $this->presentDetail($order)]);
    }

    /**
     * Same-School Student name search for the placement form's Student
     * picker -- read-only, mirrors
     * App\Http\Controllers\App\Finance\ChargeController::searchStudents()
     * exactly.
     */
    public function searchStudents(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('canteen.orders.view', $school);

        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:255'],
        ]);

        $term = '%'.$validated['q'].'%';
        $students = Student::query()
            ->where('status', 'active')
            ->where(fn ($q) => $q->where('first_name', 'ilike', $term)
                ->orWhere('last_name', 'ilike', $term)
                ->orWhere('student_number', 'ilike', $term))
            ->orderBy('first_name')
            ->limit(10)
            ->get();

        return response()->json([
            'data' => $students->map(fn (Student $s) => [
                'id' => $s->id,
                'studentNumber' => $s->student_number,
                'firstName' => $s->first_name,
                'lastName' => $s->last_name,
            ])->all(),
        ]);
    }

    /**
     * Same-School active CanteenItem search for the placement form's
     * menu picker.
     */
    public function searchItems(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('canteen.orders.view', $school);

        $validated = $request->validate([
            'q' => ['sometimes', 'string', 'max:255'],
        ]);

        $query = CanteenItem::query()->where('status', 'active')->orderBy('code');

        if (isset($validated['q']) && trim($validated['q']) !== '') {
            $term = '%'.$validated['q'].'%';
            $query->where(fn ($q) => $q->where('code', 'ilike', $term)->orWhere('name', 'ilike', $term));
        }

        return response()->json([
            'data' => $query->limit(20)->get()->map(fn (CanteenItem $i) => [
                'id' => $i->id,
                'code' => $i->code,
                'name' => $i->name,
                'price' => $i->price,
                'currency' => $i->currency,
            ])->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentSummary(CanteenOrder $order): array
    {
        return [
            'id' => $order->id,
            'status' => $order->status,
            'studentId' => $order->student_id,
            'outletId' => $order->outlet_id,
            'placedAt' => $order->placed_at->toIso8601String(),
            'fulfilledAt' => $order->fulfilled_at?->toIso8601String(),
            'cancelledAt' => $order->cancelled_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDetail(CanteenOrder $order): array
    {
        return [
            ...$this->presentSummary($order),
            'totalAmount' => $order->total_amount,
            'currency' => $order->currency,
            'chargeId' => $order->charge_id,
            'inventoryLocationId' => $order->inventory_location_id,
            'lines' => $order->lines->map(fn ($line) => [
                'id' => $line->id,
                'canteenItemId' => $line->canteen_item_id,
                'quantity' => $line->quantity,
                'unitPrice' => $line->unit_price,
                'lineTotal' => $line->line_total,
                'currency' => $line->currency,
            ])->values()->all(),
        ];
    }
}
