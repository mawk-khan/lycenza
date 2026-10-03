<?php

namespace App\Domain\Leave\Application;

use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Infrastructure\LeaveLedgerEntry;
use App\Domain\Leave\Infrastructure\LeavePolicy;
use App\Domain\Leave\Infrastructure\LeavePolicyAssignment;
use App\Domain\Leave\Infrastructure\LeaveRequest;
use App\Domain\Leave\Infrastructure\LeaveYear;
use App\Domain\Leave\Infrastructure\LeaveYearClose;
use App\Domain\Leave\Infrastructure\LeaveYearCloseItem;
use App\Domain\Leave\Infrastructure\LeaveYearCloseReconciliation;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * HRX.2 (ADR 0065 §4.6, §23.8, §23.9): the explicit leave-year close and its
 * post-close reconciliation.
 *
 * Close, once per School x leave year, under the year's EXCLUSIVE advisory
 * lock (every ledger writer holds it shared). For each employment x type
 * with entries in the year:
 * - the closing balance is derived from the ledger;
 * - the policy terms are snapshotted into an item;
 * - carried units (min(balance, cap); none when carry-forward is not
 *   allowed) move by `carry_forward_out`/`carry_forward_in` into the
 *   adjacent next year;
 * - the rest lapses by `expiry`.
 *
 * Nothing is recalculated in place, and a closed year is sealed by the
 * ledger trigger.
 *
 * Reconciliation runs when an approved request whose consumption sits in a
 * closed year is cancelled, inside that cancellation's transaction. Under
 * the ORIGINAL item's policy terms, the reversed units split into extra
 * carried and extra lapsed units, written as new linked entries. The
 * original close is never rerun or rewritten.
 */
class LeaveYearCloseService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
        private readonly SchoolOperationalGuard $guard,
        private readonly LeaveLedgerService $ledger,
        private readonly CarryForwardCalculator $calculator,
    ) {}

    /**
     * What a close would do now, with the reasons it would be refused.
     *
     * @return array{blockers: list<string>, nextLeaveYearId: ?string, items: list<array<string, mixed>>}
     */
    public function preview(School $school, string $leaveYearId, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::MANAGE, $school);

        return $this->context->withSchool($school, function () use ($school, $leaveYearId) {
            $year = LeaveYear::query()->where('school_id', $school->id)->findOrFail($leaveYearId);
            [$blockers, $next] = $this->blockers($school, $year);

            return ['blockers' => $blockers, 'nextLeaveYearId' => $next?->id, 'items' => $next === null ? [] : $this->plan($school, $year, $next)];
        });
    }

    public function execute(School $school, string $leaveYearId, User $actor): LeaveYearClose
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::MANAGE, $school);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $leaveYearId, $actor) {
            $this->guard->requireOperational($school->id);
            LeaveLocks::schedule($school, shared: true);
            $year = LeaveYear::query()->where('school_id', $school->id)->findOrFail($leaveYearId);
            // Exclusive: no approval, cancellation, adjustment or allocation of this year runs while it closes.
            LeaveLocks::year($school, $year->id, shared: false);

            [$blockers, $next] = $this->blockers($school, $year);
            if ($blockers !== [] || $next === null) {
                throw $this->blockerException($blockers[0] ?? 'LEAVE_NEXT_YEAR_NOT_OPEN');
            }
            $plan = $this->plan($school, $year, $next);

            try {
                $close = LeaveYearClose::query()->create([
                    'school_id' => $school->id, 'leave_year_id' => $year->id, 'next_leave_year_id' => $next->id,
                    'item_count' => count($plan), 'executed_by_user_id' => $actor->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw LeaveException::conflict('LEAVE_YEAR_ALREADY_CLOSED', 'That leave year is already closed.');
            }
            $close->refresh(); // executed_at is the database's clock

            foreach ($plan as $row) {
                LeaveLocks::year($school, $next->id);
                LeaveLocks::balance($school, $row['employmentRecordId'], $row['leaveTypeId'], $year->id);
                LeaveLocks::balance($school, $row['employmentRecordId'], $row['leaveTypeId'], $next->id);
                LeaveYearCloseItem::query()->create([
                    'school_id' => $school->id, 'year_close_id' => $close->id, 'employment_record_id' => $row['employmentRecordId'],
                    'leave_type_id' => $row['leaveTypeId'], 'leave_policy_id' => $row['leavePolicyId'], 'carry_forward_allowed' => $row['carryForwardAllowed'],
                    'carry_forward_cap_units' => $row['carryForwardCapUnits'], 'closing_units' => $row['closingUnits'],
                    'carried_units' => $row['carriedUnits'], 'lapsed_units' => $row['lapsedUnits'], 'carried_expires_on' => $row['carriedExpiresOn'],
                ]);
                if ($row['carriedUnits'] > 0) {
                    $this->ledger->recordCloseMovement($school, 'carry_forward_out', $row['employmentRecordId'], $row['leaveTypeId'], $year->id, $row['carriedUnits'], $close->id, null, $actor);
                    $this->ledger->recordCloseMovement($school, 'carry_forward_in', $row['employmentRecordId'], $row['leaveTypeId'], $next->id, $row['carriedUnits'], $close->id, null, $actor);
                }
                if ($row['lapsedUnits'] > 0) {
                    $this->ledger->recordCloseMovement($school, 'expiry', $row['employmentRecordId'], $row['leaveTypeId'], $year->id, $row['lapsedUnits'], $close->id, null, $actor);
                }
            }

            $this->audit->school($school, 'leave.year_close.executed', actor: $actor, subject: $close, metadata: [
                'leaveYearId' => $year->id, 'nextLeaveYearId' => $next->id, 'itemCount' => count($plan),
                'carriedUnits' => array_sum(array_column($plan, 'carriedUnits')), 'lapsedUnits' => array_sum(array_column($plan, 'lapsedUnits')),
            ]);

            return $close;
        }));
    }

    /**
     * The close of a leave year, if any. Read after the caller holds the
     * year's shared lock, so a concurrent close has either committed or
     * not started.
     */
    public function closeOf(School $school, string $leaveYearId): ?LeaveYearClose
    {
        return LeaveYearClose::query()->where('school_id', $school->id)->where('leave_year_id', $leaveYearId)->first();
    }

    /**
     * ADR 0065 §23.9: correct a closed year after one of its consumptions was
     * reversed. Runs inside the cancellation's transaction, which holds the
     * closed and next years (shared) and both balance keys.
     */
    public function reconcile(School $school, LeaveYearClose $close, LeaveLedgerEntry $reversal, LeaveRequest $request, User $actor): LeaveYearCloseReconciliation
    {
        $item = LeaveYearCloseItem::query()->where('school_id', $school->id)->where('year_close_id', $close->id)
            ->where('employment_record_id', $reversal->employment_record_id)->where('leave_type_id', $reversal->leave_type_id)->first()
            ?? throw new \LogicException('A consumption in a closed year always has its close item.');

        $prior = DB::table('leave_year_close_reconciliations')->where('school_id', $school->id)->where('year_close_item_id', $item->id)
            ->selectRaw('coalesce(sum(units), 0) as units, coalesce(sum(carried_delta), 0) as carried')->first();
        $balance = $item->closing_units + (int) ($prior->units ?? 0);
        $carried = $item->carried_units + (int) ($prior->carried ?? 0);
        $cap = $item->carry_forward_allowed ? (int) $item->carry_forward_cap_units : 0;

        $units = $reversal->units;
        $carriedDelta = max(0, min($balance + $units, $cap) - $carried);
        $lapsedDelta = $units - $carriedDelta;

        try {
            $reconciliation = LeaveYearCloseReconciliation::query()->create([
                'school_id' => $school->id, 'year_close_item_id' => $item->id, 'leave_request_id' => $request->id, 'reversal_entry_id' => $reversal->id,
                'units' => $units, 'carried_delta' => $carriedDelta, 'lapsed_delta' => $lapsedDelta, 'created_by_user_id' => $actor->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw LeaveException::conflict('LEAVE_REQUEST_ALREADY_DECIDED', 'This cancellation has already been reconciled.');
        }

        if ($carriedDelta > 0) {
            $this->ledger->recordCloseMovement($school, 'carry_forward_out', $item->employment_record_id, $item->leave_type_id, $close->leave_year_id, $carriedDelta, $close->id, $reconciliation->id, $actor);
            $this->ledger->recordCloseMovement($school, 'carry_forward_in', $item->employment_record_id, $item->leave_type_id, $close->next_leave_year_id, $carriedDelta, $close->id, $reconciliation->id, $actor);
        }
        if ($lapsedDelta > 0) {
            $this->ledger->recordCloseMovement($school, 'expiry', $item->employment_record_id, $item->leave_type_id, $close->leave_year_id, $lapsedDelta, $close->id, $reconciliation->id, $actor);
        }

        $this->audit->school($school, 'leave.year_close.reconciled', actor: $actor, subject: $reconciliation, metadata: [
            'yearCloseId' => $close->id, 'leaveRequestId' => $request->id, 'reversalEntryId' => $reversal->id,
            'units' => $units, 'carriedDelta' => $carriedDelta, 'lapsedDelta' => $lapsedDelta,
        ]);

        return $reconciliation;
    }

    /** @return array{0: list<string>, 1: ?LeaveYear} */
    private function blockers(School $school, LeaveYear $year): array
    {
        $blockers = [];
        if ($this->closeOf($school, $year->id) !== null) {
            $blockers[] = 'LEAVE_YEAR_ALREADY_CLOSED';
        }
        if ($year->ends_on->toDateString() >= CarbonImmutable::now(SchoolTimezone::resolve($school))->toDateString()) {
            $blockers[] = 'LEAVE_YEAR_NOT_ENDED';
        }
        $previous = LeaveYear::query()->where('school_id', $school->id)->where('ends_on', $year->starts_on->copy()->subDay()->toDateString())->first();
        if ($previous !== null && $this->closeOf($school, $previous->id) === null) {
            $blockers[] = 'LEAVE_YEAR_CLOSE_ORDER';
        }
        $next = LeaveYear::query()->where('school_id', $school->id)->where('starts_on', $year->ends_on->copy()->addDay()->toDateString())->first();
        if ($next === null) {
            $blockers[] = 'LEAVE_NEXT_YEAR_NOT_OPEN';
        }
        $pending = LeaveRequest::query()->where('school_id', $school->id)->where('status', 'submitted')
            ->where('starts_on', '<=', $year->ends_on->toDateString())->where('ends_on', '>=', $year->starts_on->toDateString())->exists();
        if ($pending) {
            $blockers[] = 'LEAVE_YEAR_CLOSE_PENDING_REQUESTS';
        }

        return [$blockers, $next];
    }

    /** @return list<array{employmentRecordId: string, leaveTypeId: string, leavePolicyId: ?string, carryForwardAllowed: bool, carryForwardCapUnits: ?int, closingUnits: int, carriedUnits: int, lapsedUnits: int, carriedExpiresOn: ?string}> */
    private function plan(School $school, LeaveYear $year, LeaveYear $next): array
    {
        $balances = DB::table('leave_ledger_entries')->where('school_id', $school->id)->where('leave_year_id', $year->id)
            ->groupBy('employment_record_id', 'leave_type_id')->orderBy('employment_record_id')->orderBy('leave_type_id')
            ->selectRaw("employment_record_id, leave_type_id, coalesce(sum(CASE WHEN kind IN ('allocation', 'reversal', 'carry_forward_in') OR (kind = 'adjustment' AND direction = 'credit') THEN units ELSE -units END), 0) as balance")
            ->get();

        $plan = [];
        foreach ($balances as $row) {
            $policy = $this->policyAtClose($school, (string) $row->employment_record_id, (string) $row->leave_type_id, $year);
            $closing = (int) $row->balance;
            $result = $policy === null
                ? ['carried' => 0, 'lapsed' => $closing, 'carried_expire_on' => null]
                : $this->calculator->plan($policy, $closing, $next->starts_on->toDateString());
            $plan[] = [
                'employmentRecordId' => (string) $row->employment_record_id, 'leaveTypeId' => (string) $row->leave_type_id,
                'leavePolicyId' => $policy?->id, 'carryForwardAllowed' => (bool) $policy?->carry_forward_allowed,
                'carryForwardCapUnits' => $policy?->carry_forward_cap_units, 'closingUnits' => $closing,
                'carriedUnits' => $result['carried'], 'lapsedUnits' => $result['lapsed'], 'carriedExpiresOn' => $result['carried_expire_on'],
            ];
        }

        return $plan;
    }

    /** The policy in force on the year's last day, else the latest one assigned within the year; null when none. */
    private function policyAtClose(School $school, string $employmentRecordId, string $leaveTypeId, LeaveYear $year): ?LeavePolicy
    {
        $assignment = LeavePolicyAssignment::query()->where('school_id', $school->id)->where('employment_record_id', $employmentRecordId)
            ->where('leave_type_id', $leaveTypeId)->where('effective_from', '<=', $year->ends_on->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $year->starts_on->toDateString()))
            ->orderByDesc('effective_from')->first();

        return $assignment === null ? null : LeavePolicy::query()->where('school_id', $school->id)->find($assignment->leave_policy_id);
    }

    private function blockerException(string $code): LeaveException
    {
        return LeaveException::conflict($code, match ($code) {
            'LEAVE_YEAR_ALREADY_CLOSED' => 'That leave year is already closed.',
            'LEAVE_YEAR_NOT_ENDED' => 'A leave year can be closed only after it has ended.',
            'LEAVE_YEAR_CLOSE_ORDER' => 'Close the previous leave year first.',
            'LEAVE_NEXT_YEAR_NOT_OPEN' => 'Open the next leave year first; carried leave moves into it.',
            'LEAVE_YEAR_CLOSE_PENDING_REQUESTS' => 'Decide or withdraw every submitted request in this leave year first.',
            default => 'The leave year cannot be closed.',
        });
    }
}
