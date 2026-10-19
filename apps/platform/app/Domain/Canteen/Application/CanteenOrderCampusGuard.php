<?php

namespace App\Domain\Canteen\Application;

use App\Domain\Campuses\Application\ActiveCampus;
use App\Domain\Canteen\Application\Exceptions\CanteenOrderNotFoundException;
use App\Domain\Canteen\Application\Exceptions\CanteenOutletNotFoundException;
use App\Domain\Canteen\Application\Exceptions\CanteenStudentNotFoundException;
use App\Domain\Canteen\Infrastructure\CanteenOrder;
use App\Domain\Canteen\Infrastructure\CanteenOutlet;
use App\Domain\Students\Application\StudentOperationalEligibility;
use App\Models\School;
use App\Support\Authorization\Campus\CampusScope;
use Illuminate\Support\Facades\DB;

/**
 * ORG.7D-3 (ADR 0076 §39): the Campus-scoped actor's checks, run by
 * CanteenOrderService before anything about the request is revealed and
 * again inside the write transaction.
 *
 * - Place: the Outlet must be on a Campus in scope (else the same answer as
 *   an unknown Outlet), and the Student must be a current operational
 *   Student of THAT Campus -- an unknown, inactive, moved or other-Campus
 *   Student is the same "Student not found" naming only the id the caller
 *   sent. beforePlace() answers early, so nothing below discloses another
 *   Campus. atPlacement() is the proof (ORG.7D-4, ADR 0076 §40): it runs
 *   inside the placement transaction, and
 *   StudentOperationalEligibility::lockOperationalOn() holds the answer
 *   until the order commits.
 * - Fulfil / cancel: the locked order's Outlet must be on a Campus in scope
 *   that is still active, share-locked (ActiveCampus) against a concurrent
 *   deactivation (else the same answer as an unknown order).
 *
 * Built only by CanteenOrderAccess, from a resolved CampusScope.
 */
final class CanteenOrderCampusGuard
{
    public function __construct(private readonly School $school, private readonly CampusScope $scope) {}

    /** The early refusal, before the service's own lookups (its locks end with it). */
    public function beforePlace(string $studentId, string $outletId): void
    {
        DB::transaction(fn () => $this->atPlacement($studentId, $outletId));
    }

    /** The write-time proof, inside the placement transaction. */
    public function atPlacement(string $studentId, string $outletId): void
    {
        $campusId = $this->outletCampus($outletId);
        if (! is_string($campusId) || ! $this->scope->covers($campusId)) {
            throw new CanteenOutletNotFoundException($outletId);
        }
        if (! app(StudentOperationalEligibility::class)->lockOperationalOn($this->school, $studentId, $campusId)) {
            throw new CanteenStudentNotFoundException($studentId);
        }
    }

    public function beforeChange(CanteenOrder $order): void
    {
        $campusId = $this->outletCampus($order->outlet_id);
        if (! is_string($campusId) || ! $this->scope->covers($campusId) || app(ActiveCampus::class)->find($this->school, $campusId) === null) {
            throw new CanteenOrderNotFoundException($order->id);
        }
    }

    private function outletCampus(string $outletId): mixed
    {
        return CanteenOutlet::query()->where('school_id', $this->school->id)->whereKey($outletId)->value('campus_id');
    }
}
