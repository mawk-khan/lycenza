<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Campuses\Application\CampusAdministrationService;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 0D sections 9-10, 52. Campus already existed as tenant-owned
 * data since Phase 0B (App\Models\Campus) -- this is genuine
 * administration built around it, not a parallel abstraction.
 */
class CampusController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('school.campuses.view', $school);

        $query = Campus::query()->orderBy('name');

        if (! $request->boolean('include_inactive')) {
            $query->where('status', 'active');
        }

        return response()->json(['data' => $query->with('services:id,campus_id,service_code')->get()->map(fn (Campus $c) => $this->present($c))->all()]);
    }

    public function store(Request $request, School $school, CampusAdministrationService $campuses): JsonResponse
    {
        $this->authorizeCapability('school.campuses.manage', $school);

        $campus = $campuses->create($school, $request->all(), $request->user());

        return response()->json(['data' => $this->present($campus)], 201);
    }

    public function show(School $school, string $campus): JsonResponse
    {
        $this->authorizeCapability('school.campuses.view', $school);

        $model = Campus::query()->findOrFail($campus);

        return response()->json(['data' => $this->present($model)]);
    }

    public function update(Request $request, School $school, string $campus, CampusAdministrationService $campuses): JsonResponse
    {
        $this->authorizeCapability('school.campuses.manage', $school);

        $model = $campuses->update($school, $campus, $request->all(), $request->user());

        return response()->json(['data' => $this->present($model)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Campus $campus): array
    {
        return [
            'id' => $campus->id,
            'name' => $campus->name,
            'code' => $campus->code,
            'status' => $campus->status,
            'phone' => $campus->phone,
            'email' => $campus->email,
            // ORG.12C (ADR 0080 §8): the structured address, coordinates and services.
            'addressLine1' => $campus->address_line1,
            'addressLine2' => $campus->address_line2,
            'city' => $campus->city,
            'stateRegion' => $campus->state_region,
            'postalCode' => $campus->postal_code,
            'countryCode' => $campus->country_code,
            'latitude' => $campus->latitude,
            'longitude' => $campus->longitude,
            'services' => $campus->services->pluck('service_code')->sort()->values()->all(),
        ];
    }
}
