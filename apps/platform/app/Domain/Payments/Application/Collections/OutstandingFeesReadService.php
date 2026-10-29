<?php

namespace App\Domain\Payments\Application\Collections;

use App\Domain\Fees\Application\ChargeCampusFilter;
use App\Domain\Fees\Application\ChargeService;
use App\Domain\Payments\Application\Charges\ChargeHistoryExpiredException;
use App\Domain\Payments\Application\Charges\ChargeStateReader;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use App\Support\Money\Money;
use App\Support\Tenancy\SchoolTimezone;
use Carbon\CarbonImmutable;

/**
 * UAT-C3 (UAT-FIN-08): the operational "who owes what" list -- computed on
 * read from authoritative rows, never a stored balance.
 *
 * - Authorization: `finance.charges.view` AND `finance.payments.view`, the
 *   same pair as a Student's fee statement; seeing Students is not enough.
 * - Every uncancelled charge (Fees' `ChargeService::collectionFacts()`) is
 *   read through `ChargeStateReader`, the one charge-state read: owed =
 *   amount - payment allocations - live concession adjustments, carried
 *   across closed financial periods.
 * - Outstanding is everything still owed. Overdue is the part of it on
 *   charges whose due date is before the School's own today
 *   (`schools.timezone`); a charge with no due date is outstanding, never
 *   overdue. Students owing nothing are not listed.
 * - Ordered for follow-up: most overdue first, then most outstanding, then
 *   Student id (deterministic). Totals are the filtered amounts.
 * - `$studentIds` narrows to Students the caller selected (search, class);
 *   null means every Student.
 * - One `fee_outstanding_list.viewed` audit per view: filters only, never a
 *   Student id or amount.
 *
 * ORG.9B (ADR 0076 §68):
 * - Each row also carries billed, concession and paid over the Student's
 *   uncancelled Charges in the population, from the SAME charge states, so
 *   `billed - concession - paid = outstanding` exactly (OutstandingFeeRow).
 * - `ALL` lists every Student with an uncancelled Charge in the population,
 *   settled ones included (balance 0); OUTSTANDING and OVERDUE are unchanged.
 * - `$asAt` (a School-local date, never after today) reads every Charge
 *   through `ChargeStateReader::forChargesAsAt()`: Charges assessed by the
 *   end of that day and not cancelled by then, with the concessions and
 *   payment effects POSTED by then. Overdue is judged against that date.
 * - Campus scope and the Campus filter are unchanged: both act on the
 *   STORED Charge Campus in SQL before anything is added up, and paid is
 *   only what reached a Charge the reader may see -- never a Payment's
 *   whole amount.
 */
class OutstandingFeesReadService
{
    use AuthorizesCampusScope;

    public const string OUTSTANDING = 'outstanding';

    public const string OVERDUE = 'overdue';

    /** ORG.9B: every Student with an uncancelled Charge in the population, balance 0 included. */
    public const string ALL = 'all';

    public function __construct(
        private readonly ChargeService $charges,
        private readonly ChargeStateReader $states,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * LC-4 (SB-03b): `$campus` narrows the charge LINES before anything is
     * added up (one Campus's obligations), so a Student whose debts span
     * Campuses is never counted in full under one of them. ORG.7G-1A (ADR
     * 0076 §49): by the STORED Charge Campus, in SQL -- a narrowing filter,
     * never authority (a NULL-Campus Charge never matches a Campus).
     *
     * ORG.7G-1 (§50): the reader's scope is resolved here for BOTH
     * finance.charges.view and finance.payments.view (their intersection):
     * a Campus scope sees only Charges whose STORED Campus it covers --
     * leavers and moved Students included, NULL-Campus Charges never -- with
     * each Charge's full post-correction balance (G1-D7).
     *
     * @param  list<string>|null  $studentIds
     *
     * @throws ChargeHistoryExpiredException when `$asAt` is before the retained history
     */
    public function list(School $school, User $actor, string $status = self::OUTSTANDING, ?string $academicYearId = null, ?array $studentIds = null, ?ChargeCampusFilter $campus = null, ?string $asAt = null): OutstandingFeesList
    {
        $scope = $this->authorizeCampusScopeFor($actor, 'finance.charges.view', $school)
            ->intersect($this->authorizeCampusScopeFor($actor, 'finance.payments.view', $school));

        $today = CarbonImmutable::now(SchoolTimezone::resolve($school))->toDateString();
        if ($asAt !== null && $asAt > $today) {
            throw new \InvalidArgumentException('An as-at date cannot be after the School\'s today.');
        }
        $judgedOn = $asAt ?? $today;
        $only = $studentIds === null ? null : array_flip($studentIds);

        $facts = array_values(array_filter(
            $this->charges->collectionFacts($school, $scope, $academicYearId, $campus, includeCancelled: $asAt !== null, studentIds: $studentIds),
            fn ($f) => $only === null || isset($only[$f->studentId]),
        ));
        $ids = array_map(fn ($f) => $f->id, $facts);
        $states = $asAt === null ? $this->states->forCharges($school, $ids) : $this->states->forChargesAsAt($school, $ids, $asAt);

        /** @var array<string, array{outstanding: Money, overdue: Money, open: int, earliestOverdue: ?string, nextDue: ?string, billed: Money, concession: Money, paid: Money}> $byStudent */
        $byStudent = [];
        foreach ($facts as $fact) {
            $state = $states[$fact->id] ?? null;
            // Absent: not assessed on the as-at date. Cancelled: no part of
            // the population (its amount, concessions and payments are all
            // zero by the cancellation guards; its outstanding is nothing).
            if ($state === null || $state->cancelled) {
                continue;
            }
            $zero = Money::of('0.00', $fact->currency);
            $row = $byStudent[$fact->studentId] ?? ['outstanding' => $zero, 'overdue' => $zero, 'open' => 0, 'earliestOverdue' => null, 'nextDue' => null, 'billed' => $zero, 'concession' => $zero, 'paid' => $zero];
            $row['billed'] = $row['billed']->add($state->amount);
            $row['concession'] = $row['concession']->add($state->adjusted);
            $row['paid'] = $row['paid']->add($state->allocated);
            $owed = $state->outstanding();
            if ($owed->isPositive()) {
                $row['outstanding'] = $row['outstanding']->add($owed);
                $row['open']++;
                if ($fact->dueDate !== null && $fact->dueDate < $judgedOn) {
                    $row['overdue'] = $row['overdue']->add($owed);
                    $row['earliestOverdue'] = $row['earliestOverdue'] === null || $fact->dueDate < $row['earliestOverdue'] ? $fact->dueDate : $row['earliestOverdue'];
                } elseif ($fact->dueDate !== null) {
                    $row['nextDue'] = $row['nextDue'] === null || $fact->dueDate < $row['nextDue'] ? $fact->dueDate : $row['nextDue'];
                }
            }
            $byStudent[$fact->studentId] = $row;
        }

        $rows = [];
        foreach ($byStudent as $studentId => $r) {
            if ($status === self::OVERDUE && ! $r['overdue']->isPositive()) {
                continue;
            }
            if ($status === self::OUTSTANDING && ! $r['outstanding']->isPositive()) {
                continue;
            }
            $rows[] = new OutstandingFeeRow((string) $studentId, $r['outstanding'], $r['overdue'], $r['open'], $r['earliestOverdue'], $r['nextDue'], $r['billed'], $r['concession'], $r['paid']);
        }
        usort($rows, fn (OutstandingFeeRow $a, OutstandingFeeRow $b) => bccomp($b->overdue->amount(), $a->overdue->amount(), 2)
            ?: bccomp($b->outstanding->amount(), $a->outstanding->amount(), 2)
            ?: strcmp($a->studentId, $b->studentId));

        $total = fn (string $field): Money => array_reduce($rows, fn (Money $sum, OutstandingFeeRow $row) => $sum->add($row->{$field}), Money::of('0.00', 'INR'));

        $this->audit->school($school, 'fee_outstanding_list.viewed', actor: $actor, metadata: [
            'status' => $status,
            'academicYearId' => $academicYearId,
            'narrowed' => $studentIds !== null,
            'chargeFiltered' => $campus !== null,
            'asAt' => $asAt,
        ]);

        return new OutstandingFeesList($rows, $total('outstanding'), $total('overdue'), $today, $total('billed'), $total('concession'), $total('paid'), $asAt);
    }
}
