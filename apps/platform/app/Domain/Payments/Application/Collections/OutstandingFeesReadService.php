<?php

namespace App\Domain\Payments\Application\Collections;

use App\Domain\Fees\Application\ChargeService;
use App\Domain\Payments\Application\Charges\ChargeStateReader;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
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
 */
class OutstandingFeesReadService
{
    use AuthorizesCapability;

    public const string OUTSTANDING = 'outstanding';

    public const string OVERDUE = 'overdue';

    public function __construct(
        private readonly ChargeService $charges,
        private readonly ChargeStateReader $states,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param  list<string>|null  $studentIds */
    public function list(School $school, User $actor, string $status = self::OUTSTANDING, ?string $academicYearId = null, ?array $studentIds = null): OutstandingFeesList
    {
        $this->authorizeCapabilityFor($actor, 'finance.charges.view', $school);
        $this->authorizeCapabilityFor($actor, 'finance.payments.view', $school);

        $today = CarbonImmutable::now(SchoolTimezone::resolve($school))->toDateString();
        $only = $studentIds === null ? null : array_flip($studentIds);

        $facts = array_values(array_filter(
            $this->charges->collectionFacts($school, $academicYearId),
            fn ($f) => $only === null || isset($only[$f->studentId]),
        ));
        $states = $this->states->forCharges($school, array_map(fn ($f) => $f->id, $facts));

        /** @var array<string, array{outstanding: Money, overdue: Money, open: int, earliestOverdue: ?string, nextDue: ?string}> $byStudent */
        $byStudent = [];
        foreach ($facts as $fact) {
            $owed = ($states[$fact->id] ?? null)?->outstanding();
            if ($owed === null || ! $owed->isPositive()) {
                continue;
            }
            $isOverdue = $fact->dueDate !== null && $fact->dueDate < $today;
            $row = $byStudent[$fact->studentId] ?? ['outstanding' => Money::of('0.00', $fact->currency), 'overdue' => Money::of('0.00', $fact->currency), 'open' => 0, 'earliestOverdue' => null, 'nextDue' => null];
            $row['outstanding'] = $row['outstanding']->add($owed);
            $row['open']++;
            if ($isOverdue) {
                $row['overdue'] = $row['overdue']->add($owed);
                $row['earliestOverdue'] = $row['earliestOverdue'] === null || $fact->dueDate < $row['earliestOverdue'] ? $fact->dueDate : $row['earliestOverdue'];
            } elseif ($fact->dueDate !== null) {
                $row['nextDue'] = $row['nextDue'] === null || $fact->dueDate < $row['nextDue'] ? $fact->dueDate : $row['nextDue'];
            }
            $byStudent[$fact->studentId] = $row;
        }

        $rows = [];
        foreach ($byStudent as $studentId => $r) {
            if ($status === self::OVERDUE && ! $r['overdue']->isPositive()) {
                continue;
            }
            $rows[] = new OutstandingFeeRow((string) $studentId, $r['outstanding'], $r['overdue'], $r['open'], $r['earliestOverdue'], $r['nextDue']);
        }
        usort($rows, fn (OutstandingFeeRow $a, OutstandingFeeRow $b) => bccomp($b->overdue->amount(), $a->overdue->amount(), 2)
            ?: bccomp($b->outstanding->amount(), $a->outstanding->amount(), 2)
            ?: strcmp($a->studentId, $b->studentId));

        $totalOutstanding = Money::of('0.00', 'INR');
        $totalOverdue = Money::of('0.00', 'INR');
        foreach ($rows as $row) {
            $totalOutstanding = $totalOutstanding->add($row->outstanding);
            $totalOverdue = $totalOverdue->add($row->overdue);
        }

        $this->audit->school($school, 'fee_outstanding_list.viewed', actor: $actor, metadata: [
            'status' => $status,
            'academicYearId' => $academicYearId,
            'narrowed' => $studentIds !== null,
        ]);

        return new OutstandingFeesList($rows, $totalOutstanding, $totalOverdue, $today);
    }
}
