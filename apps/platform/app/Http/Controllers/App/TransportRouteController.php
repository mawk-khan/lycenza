<?php

namespace App\Http\Controllers\App;

use App\Domain\Transport\Infrastructure\TransportRoute;
use App\Domain\Transport\Infrastructure\TransportStop;
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
 * Phase 10B -- session-authenticated Inertia pages for Transport
 * Routes (+ ordered Stops). Mirrors LibraryCatalogueController's
 * established convention exactly: every mutation delegates through
 * the same simple create-then-audit pattern the JSON API controller
 * uses.
 */
class TransportRouteController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function index(Request $request, TenantContext $context, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('transport.routes.view', $school);

        $validated = $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
        ]);

        $query = TransportRoute::query()->where('status', 'active')->withCount('stops')->orderBy('name');

        if (isset($validated['search'])) {
            $term = '%'.$validated['search'].'%';
            $query->where(fn ($q) => $q->where('name', 'ilike', $term)->orWhere('code', 'ilike', $term));
        }

        $paginator = $query->paginate(20)->withQueryString();

        return Inertia::render('App/Transport/Routes/Index', [
            'routes' => $paginator->through(fn (TransportRoute $r) => [
                'id' => $r->id,
                'code' => $r->code,
                'name' => $r->name,
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

        return Inertia::render('App/Transport/Routes/Create');
    }

    public function store(Request $request, TenantContext $context): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('transport.routes.manage', $school);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', Rule::unique('transport_routes', 'code')->where('school_id', $school->id)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $route = DB::transaction(function () use ($school, $validated, $context) {
            $route = TransportRoute::query()->create([...$validated, 'school_id' => $school->id, 'status' => 'active']);

            app(AuditRecorder::class)->school($school, 'transport.route.created', actor: $context->actor(), subject: $route, metadata: [
                'code' => $route->code,
                'name' => $route->name,
            ]);

            return $route;
        });

        return redirect("/app/transport/routes/{$route->id}");
    }

    public function show(TenantContext $context, CapabilityResolver $capabilities, string $transportRoute): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('transport.routes.view', $school);

        $route = TransportRoute::query()->with(['stops' => fn ($q) => $q->orderBy('sequence')])->findOrFail($transportRoute);

        return Inertia::render('App/Transport/Routes/Show', [
            'route' => [
                'id' => $route->id,
                'code' => $route->code,
                'name' => $route->name,
                'description' => $route->description,
                'status' => $route->status,
            ],
            'stops' => $route->stops->map(fn (TransportStop $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'sequence' => $s->sequence,
                'address' => $s->address,
                'status' => $s->status,
            ])->all(),
            'canManage' => $capabilities->canInSchool($context->actor(), 'transport.routes.manage', $school),
        ]);
    }

    public function update(Request $request, TenantContext $context, string $transportRoute): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('transport.routes.manage', $school);

        $model = TransportRoute::query()->findOrFail($transportRoute);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $before = $model->only('status');
        $model->update($validated);

        app(AuditRecorder::class)->school($school, 'transport.route.updated', actor: $context->actor(), subject: $model, metadata: [
            'before' => $before,
            'after' => $validated,
        ]);

        return redirect("/app/transport/routes/{$model->id}")->with('flash', 'Route updated.');
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
