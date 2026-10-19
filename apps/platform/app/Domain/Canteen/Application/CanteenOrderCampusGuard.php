<?php

namespace App\Domain\Canteen\Application;

use App\Domain\Canteen\Application\Exceptions\CanteenOrderNotFoundException;
use App\Domain\Canteen\Application\Exceptions\CanteenOutletNotFoundException;
use App\Domain\Canteen\Application\Exceptions\CanteenStudentNotFoundException;
use App\Domain\Canteen\Infrastructure\CanteenOrder;
use App\Domain\Canteen\Infrastructure\CanteenOutlet;
use App\Domain\Students\Application\StudentOperationalCampusResolver;
use App\Models\School;
use App\Support\Authorization\Campus\CampusScope;

/**
 * ORG.7D-3 (ADR 0076 §39): the Campus-scoped actor's checks, run by
 * CanteenOrderService before anything about the request is revealed and
 * again inside the write transaction.
 *
 * - Place: the Outlet must be on a Campus in scope (else the same answer as
 *   an unknown Outlet), and the Student must be a current operational
 *   Student of THAT Campus, resolved now -- an unknown, inactive, moved or
 *   other-Campus Student is the same "Student not found" naming only the id
 *   the caller sent.
 * - Fulfil / cancel: the locked order's Outlet must be on a Campus in scope
 *   (else the same answer as an unknown order).
 *
 * Built only by CanteenOrderAccess, from a resolved CampusScope.
 */
final class CanteenOrderCampusGuard
{
    public function __construct(private readonly School $school, private readonly CampusScope $scope) {}

    public function beforePlace(string $studentId, string $outletId): void
    {
        $campusId = CanteenOutlet::query()->where('school_id', $this->school->id)->whereKey($outletId)->value('campus_id');
        if (! is_string($campusId) || ! $this->scope->covers($campusId)) {
            throw new CanteenOutletNotFoundException($outletId);
        }
        if (! app(StudentOperationalCampusResolver::class)->isOperationalOn($this->school, $studentId, $campusId)) {
            throw new CanteenStudentNotFoundException($studentId);
        }
    }

    public function beforeChange(CanteenOrder $order): void
    {
        $campusId = CanteenOutlet::query()->where('school_id', $this->school->id)->whereKey($order->outlet_id)->value('campus_id');
        if (! is_string($campusId) || ! $this->scope->covers($campusId)) {
            throw new CanteenOrderNotFoundException($order->id);
        }
    }
}
