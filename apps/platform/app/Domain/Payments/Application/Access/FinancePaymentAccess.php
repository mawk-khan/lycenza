<?php

namespace App\Domain\Payments\Application\Access;

use App\Domain\Campuses\Application\ActiveCampus;
use App\Domain\Fees\Application\ChargeService;
use App\Domain\Payments\Application\ChargeAllocationInput;
use App\Domain\Payments\Application\Exceptions\CampusPaymentUnavailableException;
use App\Models\School;
use App\Support\Authorization\Campus\CampusScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * ORG.7G-2 (ADR 0076 §51, owner decisions G2-D3..G2-D8): THE write-time
 * authority for a Campus-scoped payment recorder (finance.payments.record).
 *
 * A Payment has no Campus. A Campus-scoped actor may record one only when
 * EVERY Charge it allocates exists in this School, has a NON-NULL stored
 * Campus (charges.campus_id, frozen at assessment -- never the Student's
 * current Campus, so a leaver's or a moved Student's historical debt is
 * collectable by the Campus it was charged on), ALL of them share ONE
 * Campus, that Campus is in the actor's current scope, and it is active.
 * A+B is whole-School only even for an A+B actor (G2-D5); a NULL-Campus
 * Charge is whole-School only (G2-D6). Every refusal is the one generic
 * CampusPaymentUnavailableException.
 *
 * Inside the recording transaction: every requested Charge is locked FOR
 * UPDATE in ascending id order, in one statement (the same lock and order the settlement
 * recorder then re-takes -- no upgrade, no allocation-order dependence),
 * then the one Campus FOR SHARE (a concurrent deactivation commits first and
 * is seen, or waits for this payment). The Charge Campus is immutable, so no
 * Enrollment or Student lock is involved (Finance/SIS decoupling).
 * Whole-School actors never come here (existing behaviour, mixed and NULL
 * included).
 */
class FinancePaymentAccess
{
    public function __construct(
        private readonly ChargeService $charges,
        private readonly ActiveCampus $campuses,
        private readonly PaymentCampusVisibility $visibility,
    ) {}

    /**
     * @param  list<ChargeAllocationInput>  $allocations
     * @return string the one authorized Campus
     */
    public function lockAllocationsForCampusWrite(School $school, CampusScope $scope, array $allocations): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('lockAllocationsForCampusWrite() must run inside the recording transaction.');
        }
        if ($scope->isWholeSchool() || $allocations === []) {
            throw new LogicException('lockAllocationsForCampusWrite() is the Campus-scoped path only.');
        }

        $chargeIds = array_values(array_unique(array_map(fn (ChargeAllocationInput $a) => $a->chargeId, $allocations)));
        sort($chargeIds, SORT_STRING);

        foreach ($chargeIds as $chargeId) {
            if (! Str::isUuid($chargeId)) {
                throw new CampusPaymentUnavailableException;
            }
        }
        // One locked, set-based read (id order); a Charge of another School or
        // an unknown id is simply absent -- the same generic refusal.
        $locked = $this->charges->lockChargeCampusesForAllocation($school, $chargeIds);
        if (count($locked) !== count($chargeIds)) {
            throw new CampusPaymentUnavailableException;
        }
        $campusIds = array_values($locked);

        $distinct = array_values(array_unique($campusIds, SORT_REGULAR));
        if (in_array(null, $campusIds, true) || count($distinct) !== 1 || ! $scope->covers($distinct[0])) {
            throw new CampusPaymentUnavailableException;
        }
        if ($this->campuses->find($school, $distinct[0]) === null) {
            throw new CampusPaymentUnavailableException;
        }

        return (string) $distinct[0];
    }

    /**
     * Before a stored payment is replayed to a Campus-scoped actor: the Payment
     * must STILL be visible to their CURRENT scope (one non-NULL Campus across
     * every Charge it touches, in scope -- G1's rule), or nothing is returned.
     */
    public function assertReplayable(School $school, CampusScope $scope, string $paymentId): void
    {
        if (! $scope->isWholeSchool() && $this->visibility->visibleAmong($school, $scope, [$paymentId]) === []) {
            throw new CampusPaymentUnavailableException;
        }
    }
}
