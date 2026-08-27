<?php

namespace App\Http\Controllers\App;

use App\Domain\Transport\Infrastructure\TransportVehicle;
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
 * Phase 10B -- session-authenticated Inertia pages for the Transport
 * Vehicle fleet. Mirrors TransportRouteController's shape exactly.
 */
class TransportVehicleController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function index(Request $request, TenantContext $context, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('transport.vehicles.view', $school);

        $validated = $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
        ]);

        $query = TransportVehicle::query()->where('status', 'active')->orderBy('code');

        if (isset($validated['search'])) {
            $term = '%'.$validated['search'].'%';
            $query->where(fn ($q) => $q->where('code', 'ilike', $term)->orWhere('registration_number', 'ilike', $term));
        }

        $paginator = $query->paginate(20)->withQueryString();

        return Inertia::render('App/Transport/Vehicles/Index', [
            'vehicles' => $paginator->through(fn (TransportVehicle $v) => [
                'id' => $v->id,
                'code' => $v->code,
                'registrationNumber' => $v->registration_number,
                'capacity' => $v->capacity,
                'status' => $v->status,
            ]),
            'filters' => ['search' => $validated['search'] ?? ''],
            'canManage' => $capabilities->canInSchool($context->actor(), 'transport.vehicles.manage', $school),
        ]);
    }

    public function create(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('transport.vehicles.manage', $school);

        return Inertia::render('App/Transport/Vehicles/Create');
    }

    public function store(Request $request, TenantContext $context): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('transport.vehicles.manage', $school);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', Rule::unique('transport_vehicles', 'code')->where('school_id', $school->id)],
            'registration_number' => ['required', 'string', 'max:64', Rule::unique('transport_vehicles', 'registration_number')->where('school_id', $school->id)],
            'capacity' => ['nullable', 'integer', 'min:1'],
        ]);

        $vehicle = DB::transaction(function () use ($school, $validated, $context) {
            $vehicle = TransportVehicle::query()->create([...$validated, 'school_id' => $school->id, 'status' => 'active']);

            app(AuditRecorder::class)->school($school, 'transport.vehicle.created', actor: $context->actor(), subject: $vehicle, metadata: [
                'code' => $vehicle->code,
                'registrationNumber' => $vehicle->registration_number,
            ]);

            return $vehicle;
        });

        return redirect('/app/transport/vehicles')->with('flash', "Vehicle {$vehicle->code} registered.");
    }

    public function update(Request $request, TenantContext $context, string $transportVehicle): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('transport.vehicles.manage', $school);

        $model = TransportVehicle::query()->findOrFail($transportVehicle);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $before = $model->only('status');
        $model->update($validated);

        app(AuditRecorder::class)->school($school, 'transport.vehicle.updated', actor: $context->actor(), subject: $model, metadata: [
            'before' => $before,
            'after' => $validated,
        ]);

        return redirect('/app/transport/vehicles')->with('flash', 'Vehicle updated.');
    }
}
