<?php

namespace App\Domain\Transport\Application\Setup;

use App\Domain\Transport\Infrastructure\TransportRoute;
use App\Domain\Transport\Infrastructure\TransportRouteAssignment;
use App\Domain\Transport\Infrastructure\TransportVehicle;
use App\Models\School;
use App\Support\Setup\SetupReadiness;
use App\Support\Setup\SetupReadinessProvider;

/**
 * ORG.12A (ADR 0080 §10): Transport readiness -- an active route with an
 * active stop, an active vehicle, and a current route assignment. EXISTS
 * answers only; never a driver, a rider or a vehicle registration.
 */
class TransportSetupReadiness implements SetupReadinessProvider
{
    public function keys(): array
    {
        return ['transport.transport'];
    }

    public function evaluate(string $key, School $school): SetupReadiness
    {
        return SetupReadiness::fromChecks(
            [
                'route_with_stops' => TransportRoute::query()->where('school_id', $school->id)->where('status', 'active')
                    ->whereHas('stops', fn ($q) => $q->where('school_id', $school->id)->where('status', 'active'))->exists(),
                'vehicle' => TransportVehicle::query()->where('school_id', $school->id)->where('status', 'active')->exists(),
                'route_assignment' => TransportRouteAssignment::query()->where('school_id', $school->id)->where('status', 'active')->exists(),
            ],
            configured: 'Routes with stops, vehicles and a current route assignment are set up.',
            incomplete: 'Add a route with stops and a vehicle, then assign the vehicle to the route.',
        );
    }
}
