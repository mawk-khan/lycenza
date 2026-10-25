<?php

namespace App\Domain\Payments\Application\Collections;

use App\Domain\AcademicStructure\Application\CurrentAcademicYearResolver;
use App\Domain\Communications\Application\Audience\FeeReminder\FeeReminderBalance;
use App\Domain\Communications\Application\Audience\FeeReminder\FeeReminderBalanceSource;
use App\Domain\Communications\Application\Audience\FeeReminder\FeeReminderSelection;
use App\Domain\Communications\Application\Exceptions\FeeReminderNotAllowedException;
use App\Domain\Fees\Application\ChargeCampusFilter;
use App\Domain\Payments\Application\Access\FinanceCampusAccess;
use App\Domain\Students\Application\StudentAccess;
use App\Domain\Students\Application\StudentEnrollmentReadService;
use App\Models\School;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * ORG.9D-2 (ADR 0076 §74, PV1-11): Payments' implementation of the
 * Communications fee-reminder port. The balances are the Outstanding fees
 * page's, read through the SAME path (FinanceCampusAccess::outstandingFees()
 * -> OutstandingFeesReadService -> ChargeStateReader), with the same
 * filters composed the same way as OutstandingFeesController -- so a
 * reminder's amount equals the page's balance for that Student and
 * population, at that moment, by construction:
 * - authority: the initiator's CURRENT finance.charges.view AND
 *   finance.payments.view Campus scope (their intersection), applied in SQL
 *   to each Charge's stored Campus; anything out of scope never enters a
 *   sum, a count or a total;
 * - the class filter ("in this class now") needs a whole-School
 *   enrollments.view holder, exactly like the page shows it;
 * - only strictly positive current outstanding is returned (settled and
 *   credit Students never are); never an as-at read, never history.
 * Reads only; one `fee_outstanding_list.viewed` audit per read, as the page.
 */
final class FeeReminderBalances implements FeeReminderBalanceSource
{
    public function __construct(
        private readonly FinanceCampusAccess $access,
        private readonly StudentAccess $students,
        private readonly StudentEnrollmentReadService $placements,
        private readonly CurrentAcademicYearResolver $years,
    ) {}

    public function assertCanSelect(School $school, User $initiator, FeeReminderSelection $selection): void
    {
        $scope = $this->access->outstandingScope($school, $initiator);
        // A Campus filter only narrows -- but naming a Campus (or the
        // whole-School, NULL-Campus Charges) outside the initiator's scope is
        // refused outright rather than quietly reminding nobody.
        if (($selection->campusId !== null && ! $scope->covers($selection->campusId))
            || ($selection->campusUnattributed && ! $scope->isWholeSchool())) {
            throw new AuthorizationException('That Campus is outside your Finance scope.');
        }
        if ($selection->narrowsByClass() && ! $this->students->holdsWholeSchool($initiator, 'enrollments.view', $school)) {
            throw new AuthorizationException('Choosing Students by class needs whole-School enrollment visibility.');
        }
        if ($selection->narrowsByClass() && $this->years->tryResolve($school) === null) {
            throw FeeReminderNotAllowedException::classUnavailable();
        }
    }

    public function scopeKey(School $school, User $initiator): string
    {
        try {
            $scope = $this->access->outstandingScope($school, $initiator);
        } catch (AuthorizationException) {
            return 'none';
        }
        if ($scope->isWholeSchool()) {
            return 'school';
        }
        $ids = $scope->campusIds();
        sort($ids);

        return 'campus:'.implode(',', $ids);
    }

    public function balances(School $school, User $initiator, FeeReminderSelection $selection): array
    {
        $this->assertCanSelect($school, $initiator, $selection);

        $studentIds = null;
        if ($selection->narrowsByClass()) {
            // "In this class now" has no answer without an active year; the
            // read model then means "every Student" -- for a reminder that would
            // silently widen an approved class to the whole School. Fail closed.
            $studentIds = $this->placements->currentStudentIdsIn($school, $selection->gradeLevelId, $selection->sectionId)
                ?? throw FeeReminderNotAllowedException::classUnavailable();
        }
        $campus = match (true) {
            $selection->campusUnattributed => ChargeCampusFilter::unattributed(),
            $selection->campusId !== null => ChargeCampusFilter::campus($selection->campusId),
            default => null,
        };
        $status = $selection->balanceStatus === FeeReminderSelection::OVERDUE ? OutstandingFeesReadService::OVERDUE : OutstandingFeesReadService::OUTSTANDING;

        $list = $this->access->outstandingFees($school, $initiator, $status, $selection->academicYearId, $studentIds, $campus);

        return array_values(array_map(
            fn (OutstandingFeeRow $row) => new FeeReminderBalance($row->studentId, $row->outstanding),
            array_filter($list->rows, fn (OutstandingFeeRow $row) => $row->outstanding->isPositive()),
        ));
    }
}
