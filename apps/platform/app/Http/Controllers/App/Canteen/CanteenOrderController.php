<?php

namespace App\Http\Controllers\App\Canteen;

use App\Domain\Canteen\Application\CanteenOrderLineData;
use App\Domain\Canteen\Application\CanteenOrderService;
use App\Domain\Canteen\Application\Exceptions\CanteenException;
use App\Domain\Canteen\Application\PlaceCanteenOrderData;
use App\Domain\Canteen\Infrastructure\CanteenItem;
use App\Domain\Canteen\Infrastructure\CanteenOrder;
use App\Domain\Canteen\Infrastructure\CanteenOutlet;
use App\Domain\Students\Application\StudentSearch;
use App\Domain\Students\Infrastructure\Student;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Phase 10F -- session-authenticated Inertia pages for the Canteen
 * Order lifecycle: placement, pending queue, fulfillment, cancellation,
 * history/detail. Mirrors
 * App\Http\Controllers\App\Finance\ChargeController's shape (thin,
 * delegates every write to CanteenOrderService).
 *
 * Highly Sensitive projection discipline (same as the API layer's
 * CanteenOrderController): `index()`'s summary rows never include
 * money fields -- only `show()` does.
 */
class CanteenOrderController extends Controller
{
    use AuthorizesCapability;

    /** The Student and Outlet an order is shown by (LC-3). */
    private const SUMMARY_RELATIONS = ['student:id,school_id,first_name,last_name,student_number', 'outlet:id,school_id,code,name'];

    public function index(Request $request, TenantContext $context, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('canteen.orders.view', $school);

        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['pending', 'fulfilled', 'cancelled'])],
        ]);

        // LC-3: names, never ids -- one eager load per relation, not a query per row.
        $query = CanteenOrder::query()->with(self::SUMMARY_RELATIONS)->orderByDesc('placed_at');
        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $paginator = $query->paginate(20)->withQueryString();

        return Inertia::render('App/Canteen/Orders/Index', [
            'orders' => $paginator->through(fn (CanteenOrder $o) => $this->presentSummary($o)),
            'filters' => ['status' => $validated['status'] ?? ''],
            'canManage' => $capabilities->canInSchool($context->actor(), 'canteen.orders.manage', $school),
        ]);
    }

    public function create(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('canteen.orders.manage', $school);

        return Inertia::render('App/Canteen/Orders/Create', [
            'outlets' => CanteenOutlet::query()->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn (CanteenOutlet $o) => ['id' => $o->id, 'code' => $o->code, 'name' => $o->name])->all(),
        ]);
    }

    public function searchStudents(Request $request, TenantContext $context): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('canteen.orders.manage', $school);

        $validated = $request->validate(['q' => ['required', 'string', 'min:2', 'max:255']]);

        $students = Student::query()
            ->where('status', 'active')
            ->tap(fn ($q) => StudentSearch::anyField($q, $validated['q']))
            ->orderBy('first_name')
            ->limit(10)
            ->get();

        return response()->json(['data' => $students->map(fn (Student $s) => [
            'id' => $s->id, 'studentNumber' => $s->student_number, 'firstName' => $s->first_name, 'lastName' => $s->last_name,
        ])->all()]);
    }

    public function searchItems(Request $request, TenantContext $context): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('canteen.orders.manage', $school);

        $validated = $request->validate(['q' => ['sometimes', 'string', 'max:255']]);
        $query = CanteenItem::query()->where('status', 'active')->orderBy('code');

        if (isset($validated['q']) && trim($validated['q']) !== '') {
            $term = '%'.$validated['q'].'%';
            $query->where(fn ($q) => $q->where('code', 'ilike', $term)->orWhere('name', 'ilike', $term));
        }

        return response()->json(['data' => $query->limit(20)->get()->map(fn (CanteenItem $i) => [
            'id' => $i->id, 'code' => $i->code, 'name' => $i->name, 'price' => $i->price,
        ])->all()]);
    }

    public function store(Request $request, TenantContext $context, CanteenOrderService $service): RedirectResponse
    {
        $school = $context->requireSchool();
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

        try {
            $result = $service->place($school, new PlaceCanteenOrderData($validated['student_id'], $validated['outlet_id'], $lines), $context->actor());
        } catch (CanteenException $e) {
            throw ValidationException::withMessages(['lines' => [$e->getMessage()]]);
        }

        return redirect("/app/canteen-orders/{$result->orderId}")->with('flash', 'Order placed.');
    }

    public function show(TenantContext $context, CapabilityResolver $capabilities, string $canteenOrder): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('canteen.orders.view', $school);

        $order = CanteenOrder::query()->with([...self::SUMMARY_RELATIONS, 'lines.canteenItem:id,school_id,code,name'])->find($canteenOrder);
        if ($order === null) {
            throw new NotFoundHttpException;
        }

        return Inertia::render('App/Canteen/Orders/Show', [
            'order' => $this->presentDetail($order),
            'canManage' => $capabilities->canInSchool($context->actor(), 'canteen.orders.manage', $school),
        ]);
    }

    public function fulfill(TenantContext $context, CanteenOrderService $service, string $canteenOrder): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('canteen.orders.manage', $school);

        try {
            $service->fulfill($school, $canteenOrder, $context->actor());
        } catch (CanteenException $e) {
            return redirect("/app/canteen-orders/{$canteenOrder}")->withErrors(['fulfillment' => $e->getMessage()]);
        }

        return redirect("/app/canteen-orders/{$canteenOrder}")->with('flash', 'Order fulfilled.');
    }

    public function cancel(TenantContext $context, CanteenOrderService $service, string $canteenOrder): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('canteen.orders.manage', $school);

        try {
            $service->cancel($school, $canteenOrder, $context->actor());
        } catch (CanteenException $e) {
            return redirect("/app/canteen-orders/{$canteenOrder}")->withErrors(['cancellation' => $e->getMessage()]);
        }

        return redirect("/app/canteen-orders/{$canteenOrder}")->with('flash', 'Order cancelled.');
    }

    /**
     * @return array<string, mixed>
     */
    private function presentSummary(CanteenOrder $order): array
    {
        return [
            'id' => $order->id,
            'status' => $order->status,
            // LC-3 (M3): people read names and numbers. A record that can no
            // longer be resolved reads neutrally, never as its internal id.
            'studentName' => $order->student === null ? 'Student unavailable' : trim($order->student->first_name.' '.$order->student->last_name),
            'studentNumber' => $order->student?->student_number,
            'outletName' => $order->outlet === null ? 'Outlet unavailable' : $order->outlet->name,
            'outletCode' => $order->outlet?->code,
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
            'lines' => $order->lines->map(fn ($line) => [
                'id' => $line->id,
                'itemName' => $line->canteenItem === null ? 'Item unavailable' : $line->canteenItem->name,
                'itemCode' => $line->canteenItem?->code,
                'quantity' => $line->quantity,
                'unitPrice' => $line->unit_price,
                'lineTotal' => $line->line_total,
            ])->values()->all(),
        ];
    }
}
