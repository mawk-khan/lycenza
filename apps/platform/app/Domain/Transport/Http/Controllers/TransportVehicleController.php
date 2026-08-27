<?php

namespace App\Domain\Transport\Http\Controllers;

use App\Domain\Transport\Infrastructure\TransportVehicle;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Models\School;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\NormalizesCodeInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Phase 10B -- Transport Vehicle administrative API. Mirrors
 * TransportRouteController's shape exactly.
 */
class TransportVehicleController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function index(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('transport.vehicles.view', $school);

        $query = TransportVehicle::query()->orderBy('code');

        if (! $request->boolean('include_inactive')) {
            $query->where('status', 'active');
        }

        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $query->where(fn ($q) => $q->where('code', 'ilike', $term)->orWhere('registration_number', 'ilike', $term));
        }

        $paginator = $query->paginate(20)->withQueryString();

        return response()->json([
            'data' => $paginator->through(fn (TransportVehicle $v) => $this->present($v))->items(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('transport.vehicles.manage', $school);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', Rule::unique('transport_vehicles', 'code')->where('school_id', $school->id)],
            'registration_number' => ['required', 'string', 'max:64', Rule::unique('transport_vehicles', 'registration_number')->where('school_id', $school->id)],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'campus_id' => ['nullable', 'string'],
        ]);

        $this->assertCampusBelongsToSchool($school, $validated['campus_id'] ?? null);

        $vehicle = DB::transaction(function () use ($school, $validated, $request) {
            $vehicle = TransportVehicle::query()->create([
                ...$validated,
                'school_id' => $school->id,
                'status' => 'active',
            ]);

            app(AuditRecorder::class)->school($school, 'transport.vehicle.created', actor: $request->user(), subject: $vehicle, metadata: [
                'code' => $vehicle->code,
                'registrationNumber' => $vehicle->registration_number,
            ]);

            return $vehicle;
        });

        return response()->json(['data' => $this->present($vehicle)], 201);
    }

    public function show(School $school, string $transportVehicle): JsonResponse
    {
        $this->authorizeCapability('transport.vehicles.view', $school);

        $model = TransportVehicle::query()->findOrFail($transportVehicle);

        return response()->json(['data' => $this->present($model)]);
    }

    public function update(Request $request, School $school, string $transportVehicle): JsonResponse
    {
        $this->authorizeCapability('transport.vehicles.manage', $school);

        $model = TransportVehicle::query()->findOrFail($transportVehicle);

        $validated = $request->validate([
            'registration_number' => ['sometimes', 'string', 'max:64', Rule::unique('transport_vehicles', 'registration_number')->where('school_id', $school->id)->ignore($model->id)],
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'campus_id' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        if (array_key_exists('campus_id', $validated)) {
            $this->assertCampusBelongsToSchool($school, $validated['campus_id']);
        }

        $before = $model->only(array_keys($validated));
        $model->update($validated);

        app(AuditRecorder::class)->school($school, 'transport.vehicle.updated', actor: $request->user(), subject: $model, metadata: [
            'before' => $before,
            'after' => $validated,
        ]);

        return response()->json(['data' => $this->present($model->refresh())]);
    }

    private function assertCampusBelongsToSchool(School $school, ?string $campusId): void
    {
        if ($campusId === null) {
            return;
        }

        if (Campus::query()->where('id', $campusId)->doesntExist()) {
            throw ValidationException::withMessages(['campus_id' => ['This Campus does not belong to this School.']]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(TransportVehicle $vehicle): array
    {
        return [
            'id' => $vehicle->id,
            'campusId' => $vehicle->campus_id,
            'code' => $vehicle->code,
            'registrationNumber' => $vehicle->registration_number,
            'capacity' => $vehicle->capacity,
            'status' => $vehicle->status,
        ];
    }
}
