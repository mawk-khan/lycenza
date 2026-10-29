<?php

namespace App\Domain\Hostel\Application;

use App\Domain\Hostel\Infrastructure\HostelResidencyAssignment;
use App\Support\Authorization\Campus\CampusScope;
use Illuminate\Support\Str;

/**
 * SIS.EXP-1: Hostel's own read of ONE Student's current residency, for the
 * staff Student profile (presentation only; SIS never owns or copies it).
 *
 * - Through HostelResidencyAccess under the caller's `hostel.residency.view`
 *   CampusScope: a residency outside the scope is simply absent.
 * - Active residency only: Hostel name, room and bed codes and the start
 *   date; never other residents.
 * - Bounded: one query with its eager loads.
 */
final class HostelStudentSummaryReader
{
    public function __construct(private readonly HostelResidencyAccess $access) {}

    /** @return list<array{hostelName: string, roomCode: string, bedCode: string, startsOn: string}> */
    public function currentFor(CampusScope $scope, string $studentId): array
    {
        if ($scope->isNone() || ! Str::isUuid($studentId)) {
            return [];
        }

        return $this->access->residencies($scope)
            ->with(['bed.room.hostel'])
            ->where('hostel_residency_assignments.student_id', $studentId)
            ->where('hostel_residency_assignments.status', 'active')
            ->orderBy('hostel_residency_assignments.starts_on')
            ->limit(5)
            ->get()
            ->map(fn (HostelResidencyAssignment $a): array => [
                'hostelName' => $a->bed->room->hostel->name,
                'roomCode' => $a->bed->room->code,
                'bedCode' => $a->bed->code,
                'startsOn' => $a->starts_on->toDateString(),
            ])
            ->values()
            ->all();
    }
}
