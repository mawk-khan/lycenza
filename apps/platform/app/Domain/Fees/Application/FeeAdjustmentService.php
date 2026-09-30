<?php

namespace App\Domain\Fees\Application;

use App\Domain\Fees\Application\Exceptions\AdjustmentExceedsOutstandingException;
use App\Domain\Fees\Application\Exceptions\ChargeAlreadyCancelledException;
use App\Domain\Fees\Application\Exceptions\ChargeFullyPaidException;
use App\Domain\Fees\Application\Exceptions\ConcessionAccountNotConfiguredException;
use App\Domain\Fees\Application\Exceptions\FeeAdjustmentAlreadyCancelledException;
use App\Domain\Fees\Application\Exceptions\FeeAdjustmentNotFoundException;
use App\Domain\Fees\Events\FeeAdjustmentCancelled;
use App\Domain\Fees\Events\FeeAdjustmentPosted;
use App\Domain\Fees\Infrastructure\FeeAdjustment;
use App\Domain\Fees\Infrastructure\FeeAssessment;
use App\Domain\Fees\Infrastructure\FeeConcession;
use App\Domain\Finance\Application\Exceptions\JournalEntryAlreadyReversedException;
use App\Domain\Finance\Application\JournalLineData;
use App\Domain\Finance\Application\LedgerService;
use App\Domain\Finance\Application\PostJournalEntryData;
use App\Domain\Finance\Domain\JournalSide;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * FEE.3 (ADR 0062 §14; owner decisions F2, G1): the TRUSTED core that posts
 * and cancels fee adjustments -- the `ChargeService` precedent. It is not an
 * authorization boundary: `FeeConcessionService` (human actions) and
 * `FeeAssessmentItemExecutor` (standing application) authorize or are
 * trusted, then call in here.
 *
 * Posting, in one savepoint (so a refusal leaves no journal entry even
 * when the caller keeps its own transaction):
 * 1. the School's concession account is re-validated (F2: configured,
 *    active, `expense`; INR and same-School by its composite FK) --
 *    otherwise `ConcessionAccountNotConfiguredException`, fail closed;
 * 2. the charge row is locked FOR UPDATE (`ChargeService`), the lock the
 *    Payments allocation path takes too;
 * 3. Dr concession account / Cr the charge's receivable account through
 *    `LedgerService::post()`;
 * 4. the `fee_adjustments` insert, where the Payments-owned capacity
 *    trigger enforces allocations + live adjustments <= amount (G1). Its
 *    refusal becomes `ChargeFullyPaidException` or
 *    `AdjustmentExceedsOutstandingException` -- refused, never reduced.
 *
 * Fees never reads Payments' allocation table; the capacity knowledge lives in
 * the Payments-owned trigger (ADR 0062 §15).
 */
class FeeAdjustmentService
{
    public function __construct(
        private readonly ChargeService $charges,
        private readonly LedgerService $ledger,
        private readonly FeeSettingsService $settings,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * Posts one adjustment. `$concession` must be approved and locked by
     * the caller's transaction; `$feeAssessmentId` is set for a standing
     * application only.
     */
    public function post(School $school, FeeConcession $concession, string $chargeId, Money $amount, ?string $feeAssessmentId, ?User $actor): FeeAdjustment
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('FeeAdjustmentService::post() must run inside the caller\'s database transaction.');
        }

        if (! $amount->isPositive()) {
            throw new AdjustmentExceedsOutstandingException($chargeId);
        }

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $concession, $chargeId, $amount, $feeAssessmentId, $actor) {
            $debitAccountId = $this->settings->validConcessionAccountId($school)
                ?? throw new ConcessionAccountNotConfiguredException;

            $snapshot = $this->charges->lockChargeForAllocation($school, $chargeId);
            if ($snapshot->isCancelled) {
                throw new ChargeAlreadyCancelledException($chargeId);
            }

            $posted = $this->ledger->post($school, new PostJournalEntryData(
                currency: $amount->currency(),
                description: 'Fee '.$concession->category,
                lines: [
                    new JournalLineData($debitAccountId, JournalSide::Debit, $amount),
                    new JournalLineData($snapshot->receivableLedgerAccountId, JournalSide::Credit, $amount),
                ],
            ), $actor);

            try {
                $adjustment = new FeeAdjustment;
                $adjustment->forceFill([
                    'school_id' => $school->id,
                    'charge_id' => $chargeId,
                    'fee_concession_id' => $concession->id,
                    'fee_assessment_id' => $feeAssessmentId,
                    'category' => $concession->category,
                    'amount' => $amount->amount(),
                    'currency' => $amount->currency(),
                    'debit_ledger_account_id' => $debitAccountId,
                    'credit_ledger_account_id' => $snapshot->receivableLedgerAccountId,
                    'journal_entry_id' => $posted->journalEntryId,
                    'posted_by_user_id' => $actor?->id,
                ])->save();
            } catch (QueryException $e) {
                throw $this->translate($e, $chargeId);
            }

            $this->audit->school($school, 'fee_adjustment.posted', actor: $actor, subject: $adjustment, metadata: [
                'feeAdjustmentId' => $adjustment->id,
                'feeConcessionId' => $concession->id,
                'chargeId' => $chargeId,
                'category' => $concession->category,
                'feeAssessmentId' => $feeAssessmentId,
            ]);

            event(new FeeAdjustmentPosted($school->id, $adjustment->id, $concession->id, $chargeId, $posted->journalEntryId, $adjustment->currency));

            return $adjustment;
        }));
    }

    /**
     * Applies every approved standing concession covering a freshly
     * assessed charge, inside the assessment item's savepoint (ADR 0062
     * §14.4). Concessions apply in decision order (`decided_at`, then id),
     * each computed exactly (N: percentage of the charge amount, scale 2,
     * half away from zero) and posted whole or refused (G1). The
     * concession rows are read FOR SHARE, so a concurrent revocation either
     * commits first (and the concession no longer applies) or waits for
     * this item to commit.
     *
     * @return list<FeeAdjustment>
     */
    public function applyStanding(School $school, FeeAssessment $assessment, string $periodStartsOn, Money $chargeAmount, ?User $actor): array
    {
        $posted = [];
        foreach ($this->standingConcessionsFor($school, $assessment->student_id, $assessment->academic_year_id, $assessment->fee_head_id, $periodStartsOn, lock: true) as $concession) {
            $amount = $this->valueOf($concession, $chargeAmount);
            if ($amount->isPositive()) {
                $posted[] = $this->post($school, $concession, $assessment->charge_id, $amount, $assessment->id, $actor);
            }
        }

        return $posted;
    }

    /**
     * The approved standing concessions covering a Student x year x head at
     * a period start, in application order.
     *
     * @return list<FeeConcession>
     */
    public function standingConcessionsFor(School $school, string $studentId, string $academicYearId, string $feeHeadId, string $periodStartsOn, bool $lock = false): array
    {
        return $this->context->withSchool($school, fn () => FeeConcession::query()
            ->where('school_id', $school->id)
            ->where('scope', FeeConcession::SCOPE_STANDING)
            ->where('status', FeeConcession::STATUS_APPROVED)
            ->where('student_id', $studentId)
            ->where('academic_year_id', $academicYearId)
            ->where(fn ($q) => $q->whereNull('fee_head_id')->orWhere('fee_head_id', $feeHeadId))
            ->whereDate('valid_from', '<=', $periodStartsOn)
            ->whereDate('valid_to', '>=', $periodStartsOn)
            ->orderBy('decided_at')
            ->orderBy('id')
            ->when($lock, fn ($q) => $q->sharedLock())
            ->get()
            ->all());
    }

    /** ADR 0062 §14.3 (N): the exact value a concession asks for on a charge amount. */
    public function valueOf(FeeConcession $concession, Money $chargeAmount): Money
    {
        if ($concession->kind === FeeConcession::KIND_FIXED) {
            return Money::of((string) $concession->fixed_amount, $chargeAmount->currency());
        }

        return $chargeAmount->multiplyByRate(bcdiv((string) $concession->percentage, '100', 4));
    }

    /**
     * Cancels one adjustment through a Finance reversal. Lock order is the
     * charge row, then the adjustment -- the posting path's order.
     */
    public function cancel(School $school, string $adjustmentId, User $actor, ?string $reason = null): FeeAdjustment
    {
        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $adjustmentId, $actor, $reason) {
            $chargeId = (Str::isUuid($adjustmentId) ? FeeAdjustment::query()->where('school_id', $school->id)->whereKey($adjustmentId)->value('charge_id') : null)
                ?? throw new FeeAdjustmentNotFoundException($adjustmentId);

            $this->charges->lockChargeForAllocation($school, (string) $chargeId);

            $adjustment = FeeAdjustment::query()->where('school_id', $school->id)->lockForUpdate()->findOrFail($adjustmentId);
            if (! $adjustment->isLive()) {
                throw new FeeAdjustmentAlreadyCancelledException($adjustment->id);
            }

            try {
                $reversal = $this->ledger->reverseById($school, $adjustment->journal_entry_id, $actor, $reason === null || trim($reason) === '' ? null : mb_substr(trim($reason), 0, 255));
            } catch (JournalEntryAlreadyReversedException) {
                throw new FeeAdjustmentAlreadyCancelledException($adjustment->id);
            }

            $affected = FeeAdjustment::query()
                ->whereKey($adjustment->id)
                ->whereNull('cancelled_at')
                ->update([
                    'cancelled_at' => now(),
                    'cancellation_journal_entry_id' => $reversal->journalEntryId,
                    'cancelled_by_user_id' => $actor->id,
                ]);

            if ($affected !== 1) {
                throw new FeeAdjustmentAlreadyCancelledException($adjustment->id);
            }

            $adjustment->refresh();

            $this->audit->school($school, 'fee_adjustment.cancelled', actor: $actor, subject: $adjustment, metadata: [
                'feeAdjustmentId' => $adjustment->id,
                'feeConcessionId' => $adjustment->fee_concession_id,
                'chargeId' => $adjustment->charge_id,
                'cancellationJournalEntryId' => $reversal->journalEntryId,
            ]);

            event(new FeeAdjustmentCancelled($school->id, $adjustment->id, $adjustment->charge_id, $reversal->journalEntryId, $adjustment->journal_entry_id, $adjustment->currency));

            return $adjustment;
        }));
    }

    private function translate(QueryException $e, string $chargeId): Throwable
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, 'is fully settled and cannot receive a fee adjustment') => new ChargeFullyPaidException($chargeId),
            str_contains($message, 'would exceed its outstanding amount') => new AdjustmentExceedsOutstandingException($chargeId),
            str_contains($message, 'is cancelled and cannot receive a fee adjustment') => new ChargeAlreadyCancelledException($chargeId),
            str_contains($message, 'must be an active expense account') => new ConcessionAccountNotConfiguredException,
            default => $e,
        };
    }
}
