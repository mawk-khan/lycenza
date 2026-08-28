<?php

namespace App\Domain\Canteen\Application\Exceptions;

/**
 * Charge.academic_year_id is required (Fees' own `AssessChargeData`
 * contract) -- fulfillment must resolve the School's currently-active
 * AcademicYear BEFORE calling InventoryStockService::issueMany(),
 * exactly like a missing billing configuration: fail cleanly, Order
 * stays pending, nothing is consumed.
 */
class CanteenNoActiveAcademicYearException extends CanteenException
{
    public function __construct()
    {
        parent::__construct(422, 'CANTEEN_NO_ACTIVE_ACADEMIC_YEAR', 'This School has no active Academic Year -- Canteen order fulfillment cannot assess a Charge.');
    }
}
