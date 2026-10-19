<?php

namespace App\Http\Controllers\App;

use App\Domain\Transport\Application\TransportRiderAccess;
use App\Domain\Transport\Application\TransportRouteService;
use App\Domain\Transport\Infrastructure\TransportRoute;
use App\Domain\Transport\Infrastructure\TransportStop;
use App\Http\Controllers\App\Fees\OperationalFeePanels;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
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
 * Phase 10B -- session-authenticated Inertia pages for Transport
 * Routes (+ ordered Stops). Mirrors LibraryCatalogueController's
 * established convention exactly: every mutation delegates through
 * the same simple create-then-audit pattern the JSON API controller
 * uses.
 *
 * ORG.7D-5 (ADR 0076 §41): `transport.routes.view` is Campus-adopted -- the
 * list and the route page read through TransportRiderAccess (Campus-bound
 * routes in scope only; a NULL-Campus route is whole-School only), and the
 * route's fee head panel is shown to a whole-School viewer only. Route and
 * Stop management (`transport.routes.manage`) stays whole-School, through
 * TransportRouteService (Campus fixed once the route is used).
 */
class TransportRouteController extends Controller
{
    use AuthorizesCampusScope, AuthorizesCapability, NormalizesCodeInput;

    public function index(Request $request, TenantContext $context, CapabilityResolver $capabilities, TransportRiderAccess $access): Response
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('transport.routes.view', $school);

        $validated = $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
        ]);

        $query = $access->routes($scope)->with('campus')->where('status', 'active')->withCount('stops')->orderBy('name');

        if (isset($validated['search'])) {
            $term = '%'.addcslashes($validated['search'], '%_\\').'%';
            $query->where(fn ($q) => $q->where('name', 'ilike', $term)->orWhere('code', 'ilike', $term));
        }

        $paginator = $query->paginate(20)->withQueryString();

        return Inertia::render('App/Transport/Routes/Index', [
            'routes' => $paginator->through(fn (TransportRoute $r) => [
                'id' => $r->id,
                'code' => $r->code,
                'name' => $r->name,
                'campusName' => $r->campus?->name,
                'stopsCount' => $r->stops_count,
            ]),
            'filters' => ['search' => $validated['search'] ?? ''],
            'canManage' => $capabilities->canInSchool($context->actor(), 'transport.routes.manage', $school),
        ]);
    }

    public function create(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('transport.routes.manage', $school);

        return Inertia::render('App/Transport/Routes/Create', ['campuses' => $this->campusOptions()]);
    }

    public function store(Request $request, TenantContext $context, TransportRouteService $routes): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('transport.routes.manage', $school);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', Rule::unique('transport_routes', 'code')->where('school_id', $school->id)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'campus_id' => ['nullable', 'string'],
        ]);

        // ORG.7D-5: an active Campus of this School, or none (School-shared).
        $route = $routes->create($school, $validated, $context->actor());

        return redirect("/app/transport/routes/{$route->id}");
    }

    public function show(TenantContext $context, CapabilityResolver $capabilities, TransportRiderAccess $access, string $transportRoute): Response
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('transport.routes.view', $school);

        $route = $access->scopedRoute($scope, $transportRoute)->load(['stops' => fn ($q) => $q->orderBy('sequence'), 'campus']);
        $canManage = $capabilities->canInSchool($context->actor(), 'transport.routes.manage', $school);

        return Inertia::render('App/Transport/Routes/Show', [
            'route' => [
                'id' => $route->id,
                'code' => $route->code,
                'name' => $route->name,
                'description' => $route->description,
                'status' => $route->status,
                'campusId' => $route->campus_id,
                'campusName' => $route->campus?->name,
            ],
            'stops' => $route->stops->map(fn (TransportStop $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'sequence' => $s->sequence,
                'address' => $s->address,
                'status' => $s->status,
            ])->all(),
            'canManage' => $canManage,
            'campuses' => $canManage ? $this->campusOptions() : [],
            // SWC.3 (ADR 0073 section 19): the route's OPF fee head (ADR 0067 D7) --
            // Fees configuration, so a whole-School viewer's only (ORG.7D-5).
            'feePanel' => $scope->isWholeSchool() ? app(OperationalFeePanels::class)->transportRoute($school, $route, $canManage) : null,
        ]);
    }

    public function update(Request $request, TenantContext $context, TransportRouteService $routes, string $transportRoute): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('transport.routes.manage', $school);

        $model = TransportRoute::query()->findOrFail($transportRoute);

        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'campus_id' => ['sometimes', 'nullable', 'string'],
        ]);

        // ORG.7D-5: locked; the Campus is fixed once the route is used.
        $routes->update($school, $model->id, $validated, $context->actor());

        return redirect("/app/transport/routes/{$model->id}")->with('flash', 'Route updated.');
    }

    /** @return list<array{id: string, name: string}> */
    private function campusOptions(): array
    {
        return Campus::query()->where('status', 'active')->orderBy('name')->get(['id', 'name'])
            ->map(fn (Campus $c) => ['id' => (string) $c->id, 'name' => (string) $c->name])->values()->all();
    }

    public function storeStop(Request $request, TenantContext $context, string $transportRoute): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('transport.routes.manage', $school);

        $route = TransportRoute::query()->findOrFail($transportRoute);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sequence' => ['required', 'integer', 'min:1', Rule::unique('transport_stops', 'sequence')->where('route_id', $route->id)],
            'address' => ['nullable', 'string'],
        ]);

        $stop = DB::transaction(function () use ($school, $route, $validated, $context) {
            // ORG.7D-5: a Stop fixes the Route's Campus; share-lock the Route first.
            TransportRouteService::lockForDependent($route);
            $stop = TransportStop::query()->create([
                ...$validated,
                'school_id' => $school->id,
                'route_id' => $route->id,
                'status' => 'active',
            ]);

            app(AuditRecorder::class)->school($school, 'transport.stop.created', actor: $context->actor(), subject: $stop, metadata: [
                'transportRouteId' => $route->id,
                'name' => $stop->name,
                'sequence' => $stop->sequence,
            ]);

            return $stop;
        });

        return redirect("/app/transport/routes/{$route->id}")->with('flash', "Stop {$stop->name} added.");
    }

    public function updateStop(Request $request, TenantContext $context, string $transportStop): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('transport.routes.manage', $school);

        $model = TransportStop::query()->findOrFail($transportStop);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $before = $model->only('status');
        $model->update($validated);

        app(AuditRecorder::class)->school($school, 'transport.stop.updated', actor: $context->actor(), subject: $model, metadata: [
            'before' => $before,
            'after' => $validated,
        ]);

        return redirect("/app/transport/routes/{$model->route_id}")->with('flash', 'Stop updated.');
    }
}
