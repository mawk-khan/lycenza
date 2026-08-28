<?php

namespace App\Domain\Hostel\Application\Exceptions;

/**
 * A residency that is already `ended` cannot be ended again -- mirrors
 * App\Domain\Visitor\Application\Exceptions\VisitAlreadyCheckedOutException
 * exactly. `HostelResidencyService::end()`'s conditional
 * `UPDATE ... WHERE status = 'active'` is what makes this safe under
 * concurrent/retried requests.
 */
class ResidencyAlreadyEndedException extends HostelException
{
    public function __construct()
    {
        parent::__construct(422, 'HOSTEL_RESIDENCY_ALREADY_ENDED', 'This Hostel residency has already ended.');
    }
}
