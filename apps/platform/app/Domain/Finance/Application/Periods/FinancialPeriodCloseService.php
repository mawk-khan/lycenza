<?php

namespace App\Domain\Finance\Application\Periods;

use App\Domain\Finance\Application\Exceptions\FinancialPeriodCloseRefusedException;
use App\Domain\Finance\Application\Exceptions\FinancialPeriodNotFoundException;
use App\Domain\Finance\Application\Exceptions\FinancialPeriodVerificationFailedException;
use App\Domain\Finance\Infrastructure\FinancialPeriod;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Support\Facades\DB;

/**
 * E21.3A (ADR 0064 §4): closes one financial period, once, for good.
 *
 * One transaction (READ COMMITTED), in this order:
 *
 * 1. Make sure the current period exists, so a posting during the close
 *    never needs to create one.
 * 2. Lock every OPEN period of the School FOR UPDATE, ordered by
 *    `starts_on` then id. This is the one lock order. Every posting holds
 *    its period FOR SHARE until it commits (the journal trigger), so
 *    postings, reversals, payments, payroll postings and the backfill wait
 *    for the close, and the close waits for them. Nothing else is locked.
 * 3. Validate (deterministic, blockers are codes):
 *    - the period is open;
 *    - it ended before the School-local today;
 *    - every earlier period is closed;
 *    - no unmapped pre-E21.3A entry is dated inside or before it;
 *    - each participant's own blockers.
 * 4. Write the immutable baselines: ledger accounts here, then each
 *    participant (Payments' charge states).
 * 5. Mark the period closed (closed_at, closed_by, fingerprint).
 * 6. Dual-read verify the whole School. Any difference throws, which rolls
 *    everything back.
 * 7. Audit `financial_period.closed`.
 *
 * There is no reopen. A correction to a closed period is a new posting in
 * the open period, linked to the original (a reversal or an adjustment).
 */
class FinancialPeriodCloseService
{
    use AuthorizesCapability;

    /** @param  iterable<FinancialPeriodCloseParticipant>  $participants */
    public function __construct(
        private readonly FinancialPeriodService $periods,
        private readonly LedgerPeriodBalances $ledger,
        private readonly FinancialBalanceVerifier $verifier,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        #[Tag(FinancialPeriodCloseParticipant::TAG)] private readonly iterable $participants,
    ) {}

    /** Read-only preview for the close screen (`finance.ledger.view`). */
    public function evaluate(School $school, string $periodId, User $actor): FinancialPeriodCloseEvaluation
    {
        $this->authorizeCapabilityFor($actor, 'finance.ledger.view', $school);

        return $this->context->withSchool($school, function () use ($school, $periodId) {
            $period = $this->periods->find($school, $periodId) ?? throw new FinancialPeriodNotFoundException($periodId);

            return $this->assess($school, $period);
        });
    }

    /**
     * Closes the period. `$confirmation` must be the period key typed by the
     * actor. Requires `finance.periods.manage`; the HTTP layer additionally
     * requires a fresh MFA verification.
     */
    public function close(School $school, string $periodId, string $confirmation, User $actor): FinancialPeriodSummary
    {
        $this->authorizeCapabilityFor($actor, 'finance.periods.manage', $school);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $periodId, $confirmation, $actor) {
            $this->periods->current($school);

            $open = FinancialPeriod::query()
                ->where('school_id', $school->id)
                ->where('status', FinancialPeriod::OPEN)
                ->orderBy('starts_on')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $model = $open->firstWhere('id', $periodId);
            if ($model === null) {
                $existing = $this->periods->find($school, $periodId) ?? throw new FinancialPeriodNotFoundException($periodId);

                throw new FinancialPeriodCloseRefusedException($existing->isClosed() ? ['period_already_closed'] : ['period_not_open']);
            }
            $period = FinancialPeriodSummary::fromModel($model);

            if (trim($confirmation) !== $period->key) {
                throw new FinancialPeriodCloseRefusedException(['confirmation_mismatch']);
            }

            $evaluation = $this->assess($school, $period);
            if (! $evaluation->canClose()) {
                throw new FinancialPeriodCloseRefusedException($evaluation->blockers);
            }

            $previous = $this->periods->latestClosed($school);
            $index = $this->periods->journalPeriodIndex($school);

            $digests = ['ledger' => $this->ledger->writeBaseline($school, $period, $previous)];
            foreach ($this->participants as $participant) {
                $digests[$participant->key()] = $participant->snapshot($school, $period, $index);
            }
            ksort($digests);
            $fingerprint = hash('sha256', $period->key."\n".json_encode($digests, JSON_THROW_ON_ERROR));

            $model->forceFill([
                'status' => FinancialPeriod::CLOSED,
                'closed_at' => now(),
                'closed_by_user_id' => $actor->id,
                'close_fingerprint' => $fingerprint,
            ])->save();

            $verification = $this->verifier->verify($school);
            if (! $verification->passed()) {
                throw new FinancialPeriodVerificationFailedException($verification->all());
            }

            $this->audit->school($school, 'financial_period.closed', actor: $actor, subject: $model, metadata: [
                'periodKey' => $period->key,
                'startsOn' => $period->startsOn,
                'endsOn' => $period->endsOn,
                'entryCount' => $evaluation->entryCount,
                'accountBaselines' => $this->ledger->baselineCount($school, $period->id),
                'notices' => $evaluation->notices,
                'fingerprint' => $fingerprint,
            ]);

            return FinancialPeriodSummary::fromModel($model->refresh());
        }));
    }

    private function assess(School $school, FinancialPeriodSummary $period): FinancialPeriodCloseEvaluation
    {
        $today = $this->periods->localToday($school);
        $blockers = [];
        $notices = [];

        if ($period->isClosed()) {
            $blockers[] = 'period_already_closed';
        }
        if ($period->endsOn >= $today) {
            $blockers[] = 'period_not_ended';
        }
        $earlierOpen = FinancialPeriod::query()
            ->where('school_id', $school->id)
            ->where('status', FinancialPeriod::OPEN)
            ->where('starts_on', '<', $period->startsOn)
            ->exists();
        if ($earlierOpen) {
            $blockers[] = 'earlier_period_open';
        }
        if ($this->periods->unmappedEntryCount($school, $period->endsOn) > 0) {
            $blockers[] = 'unmapped_journal_entries';
        }

        $entryCount = $this->periods->entryCountIn($school, $period->id);
        if ($entryCount === 0) {
            $notices[] = 'no_postings';
        }

        $index = $this->periods->journalPeriodIndex($school);
        foreach ($this->participants as $participant) {
            foreach ($participant->blockers($school, $period, $index) as $code) {
                $blockers[] = $participant->key().'.'.$code;
            }
            foreach ($participant->notices($school, $period, $index) as $code) {
                $notices[] = $participant->key().'.'.$code;
            }
        }

        return new FinancialPeriodCloseEvaluation($period, $today, $entryCount, $blockers, $notices);
    }
}
