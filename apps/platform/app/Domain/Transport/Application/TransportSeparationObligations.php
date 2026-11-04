<?php

namespace App\Domain\Transport\Application;

use App\Domain\HR\Application\Separation\SeparationObligation;
use App\Domain\HR\Application\Separation\SeparationObligationSource;
use App\Domain\HR\Application\Separation\SeparationSubject;
use App\Models\School;
use Illuminate\Support\Facades\DB;

/**
 * HR.12 (X-1): the route assignments a separating driver still holds.
 * Transport ends them through its own operations; past journeys are never
 * changed by a separation.
 */
final class TransportSeparationObligations implements SeparationObligationSource
{
    public function owner(): string
    {
        return 'Transport';
    }

    public function obligations(School $school, SeparationSubject $subject): array
    {
        $routes = DB::table('transport_route_assignments')->where('school_id', $school->id)
            ->where('driver_employee_id', $subject->employeeId)->where('status', 'active')->count();

        return [
            new SeparationObligation('transport_driver_routes', 'Transport', 'Routes assigned as driver',
                $routes === 0 ? SeparationObligation::CLEAR : ($subject->finalized ? SeparationObligation::OPEN : SeparationObligation::ATTENTION), $routes,
                $routes > 0 ? 'End or reassign these route assignments in Transport.' : null),
        ];
    }
}
