<?php

namespace App\Domain\Transport\Application;

use App\Domain\Transport\Infrastructure\TransportStudentAssignment;
use App\Support\Authorization\Campus\CampusScope;
use Illuminate\Support\Str;

/**
 * SIS.EXP-1: Transport's own read of ONE Student's current rider
 * assignments, for the staff Student profile (presentation only; SIS never
 * owns or copies them).
 *
 * - Through TransportRiderAccess under the caller's
 *   `transport.assignments.view` CampusScope: a route outside the scope (or
 *   School-shared, for a Campus actor) is simply absent.
 * - Active assignments only; route and stop names, never a vehicle,
 *   driver, location or another rider.
 * - Bounded: one query with its eager loads, whatever the data size.
 */
final class TransportStudentSummaryReader
{
    public function __construct(private readonly TransportRiderAccess $access) {}

    /** @return list<array{routeName: string, pickupStopName: ?string, dropoffStopName: ?string, startsOn: string}> */
    public function currentFor(CampusScope $scope, string $studentId): array
    {
        if ($scope->isNone() || ! Str::isUuid($studentId)) {
            return [];
        }

        return $this->access->assignments($scope)
            ->with(['route:id,name', 'pickupStop:id,name', 'dropoffStop:id,name'])
            ->where('transport_student_assignments.student_id', $studentId)
            ->where('transport_student_assignments.status', 'active')
            ->orderBy('transport_student_assignments.starts_on')
            ->limit(5)
            ->get()
            ->map(fn (TransportStudentAssignment $a): array => [
                'routeName' => $a->route->name,
                'pickupStopName' => $a->pickupStop?->name,
                'dropoffStopName' => $a->dropoffStop?->name,
                'startsOn' => $a->starts_on->toDateString(),
            ])
            ->values()
            ->all();
    }
}
