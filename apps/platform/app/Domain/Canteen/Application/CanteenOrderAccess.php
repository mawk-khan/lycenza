<?php

namespace App\Domain\Canteen\Application;

use App\Domain\Canteen\Infrastructure\CanteenOrder;
use App\Domain\Canteen\Infrastructure\CanteenOutlet;
use App\Domain\Students\Application\StudentLookupCandidate;
use App\Domain\Students\Application\StudentPurposeLookup;
use App\Domain\Students\Application\StudentSearch;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Support\Authorization\Campus\CampusScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * ORG.7D-3 (ADR 0076 §11.7, §39): the ONE place Canteen order work reads or
 * targets orders, outlets and Student candidates under the actor's
 * CampusScope (`canteen.orders.view`/`.manage` are Campus-adopted; the
 * outlet/item directory and billing settings stay whole-School).
 *
 * - Authority source: the order's Outlet Campus (`canteen_outlets.campus_id`,
 *   immutable after creation). A School-wide (NULL-Campus) Outlet and its
 *   orders are whole-School only. The order's stock location is the Outlet's
 *   own, bound to the Outlet's Campus by composite foreign key, so a
 *   Campus-scoped fulfilment never issues stock outside that Campus.
 * - Candidates: for a Campus-scoped actor, StudentPurposeLookup with the
 *   actor's scope (current operational Students only); a whole-School actor
 *   keeps the existing picker. id/displayName/studentNumber either way.
 * - v1 rule for a Campus-scoped actor: the Student must be a current
 *   operational Student OF the Outlet's Campus, re-checked when the order is
 *   placed (CanteenOrderCampusGuard). The charge a fulfilment creates is
 *   Canteen's bounded internal side effect: no Fees authority is involved.
 * - Out of scope is NOT FOUND, like another School's record.
 */
final class CanteenOrderAccess
{
    public function __construct(private readonly StudentPurposeLookup $students) {}

    /** @return Builder<CanteenOutlet> */
    public function outlets(CampusScope $scope): Builder
    {
        return $scope->restrict(CanteenOutlet::query());
    }

    /** @return Builder<CanteenOrder> */
    public function orders(CampusScope $scope): Builder
    {
        return $scope->isWholeSchool()
            ? CanteenOrder::query()
            : CanteenOrder::query()->whereIn('outlet_id', $this->outlets($scope)->select('id'));
    }

    public function order(CampusScope $scope, string $id): CanteenOrder
    {
        $found = Str::isUuid($id) ? $this->orders($scope)->find($id) : null;

        return $found ?? throw (new ModelNotFoundException)->setModel(CanteenOrder::class, [$id]);
    }

    /** @return list<StudentLookupCandidate> */
    public function studentCandidates(School $school, CampusScope $scope, string $term): array
    {
        if (! $scope->isWholeSchool()) {
            return $this->students->search($school, $scope, $term, 10);
        }

        return Student::query()
            ->where('status', 'active')
            ->tap(fn ($q) => StudentSearch::anyField($q, $term))
            ->orderBy('first_name')
            ->limit(10)
            ->get()
            ->map(fn (Student $s) => new StudentLookupCandidate((string) $s->id, trim($s->first_name.' '.$s->last_name), $s->student_number))
            ->values()->all();
    }

    /** The write-time Campus check; none for a whole-School actor (unchanged path). */
    public function writeGuard(School $school, CampusScope $scope): ?CanteenOrderCampusGuard
    {
        return $scope->isWholeSchool() ? null : new CanteenOrderCampusGuard($school, $scope);
    }
}
