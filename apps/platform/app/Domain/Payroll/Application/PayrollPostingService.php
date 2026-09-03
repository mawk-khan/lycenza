<?php

namespace App\Domain\Payroll\Application;

use App\Domain\Finance\Application\Exceptions\JournalEntryAlreadyReversedException;
use App\Domain\Finance\Application\JournalLineData;
use App\Domain\Finance\Application\LedgerService;
use App\Domain\Finance\Application\PostJournalEntryData;
use App\Domain\Finance\Domain\JournalSide;
use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Domain\Payroll\Application\Exceptions\DeductionMissingLedgerMappingException;
use App\Domain\Payroll\Application\Exceptions\InvalidRunTransitionException;
use App\Domain\Payroll\Application\Exceptions\PayrollRunAlreadyReversedException;
use App\Domain\Payroll\Application\Exceptions\PayrollRunNotPostedException;
use App\Domain\Payroll\Events\PayrollRunPosted;
use App\Domain\Payroll\Events\PayrollRunReversed;
use App\Domain\Payroll\Infrastructure\PayrollAccountingConfiguration;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Infrastructure\PayrollRunPosting;
use App\Domain\Payroll\Infrastructure\PayrollRunResult;
use App\Domain\Payroll\Infrastructure\PayrollRunResultLine;
use App\Models\ApiIdempotencyKey;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Idempotency\IdempotencyGuard;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 9.5 (ADR 0032 "Run kinds, correction model, and posting" /
 * "Deduction accounting" / "Reversal model") -- the only sanctioned
 * write path for `approved -> posted` and for reversing a posted run.
 * Never writes `journal_entries`/`journal_lines` directly -- every
 * accounting effect goes through `App\Domain\Finance\Application\LedgerService::post()`/
 * `reverseById()`, mirroring
 * `App\Domain\Fees\Application\ChargeService`'s exact established
 * cross-module pattern (this class calls `LedgerService` directly,
 * never `LedgerAdministrationService` -- the same "assessing a fee vs.
 * administering the raw ledger" separation applies here as "posting
 * payroll vs. administering the raw ledger").
 *
 * NOT an authorization boundary, for the same reason `LedgerService`/
 * `ChargeService` are not one: `$actor` is passed through purely for
 * WHO-did-this audit provenance. `payroll.runs.post`/`.reverse`
 * (ADR 0032 "Separation of duties" -- deliberately two distinct
 * capabilities, mirroring `finance.ledger.post`/`.reverse`) are
 * deliberately deferred to Checkpoint 9.7, exactly as every other
 * Payroll Application service in this phase.
 *
 * Posting algorithm (fixed by `payroll_run_result_lines`' own
 * migration docblock, Checkpoint 9.1): one aggregate debit (total
 * GROSS earnings, to salary expense), one aggregate credit (total net
 * pay, to salary payable), and one aggregate credit per deduction
 * component with a nonzero total (to its liability account) --
 * balanced by construction, since for every result `gross = net +
 * deductions` holds, and therefore so does the aggregate across every
 * result in the run. `effect` (increase|decrease) flips debit/credit
 * direction per the same docblock -- a Phase 9.5 correction run
 * (`PayrollRunService::createCorrectionRun()`) is the first creatable
 * run kind that can produce a `decrease` line; this method implements
 * the already-committed general algorithm, not a regular-run special
 * case.
 *
 * Account resolution policy (Phase 9.5 accounting-integrity
 * correction): ALL THREE account categories -- salary expense, salary
 * payable, AND deduction liability -- resolve and validate the
 * CURRENT `App\Domain\Finance\Infrastructure\LedgerAccount` at posting
 * time, never a stale snapshot. Salary expense/payable already did
 * this via `PayrollAccountingConfigurationService::resolveValidated()`;
 * this class resolves a deduction's liability account the identical
 * way, from `SalaryComponent::liability_ledger_account_id` (live), not
 * from `payroll_run_result_lines.resolved_ledger_account_id` (a
 * calculation-time snapshot). That snapshot column still exists and
 * is still populated by `PayrollRunService::persistResult()` --
 * unchanged 9.1/9.3 behavior, not itself reopened -- and remains a
 * useful point-in-time record of what was configured when the run was
 * calculated, but it is deliberately NOT read here: only ONE
 * resolution policy governs what actually gets posted, applied
 * uniformly to every account category, chosen over snapshot-at-
 * calculation-time because the ADR's own rationale for freezing
 * financial FACTS at `approved` (ADR 0032 "the sole immutability
 * boundary") is about amounts and component identity, not about which
 * Finance account a component happens to be mapped to today -- a
 * School correcting a misconfigured liability account between
 * calculation and posting should have that correction take effect,
 * exactly as a salary-expense/payable reconfiguration already does.
 * See the ADR 0032 amendment note for the full reconciliation.
 */
class PayrollPostingService
{
    private const CURRENCY = 'INR';

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly PayrollAccountingConfigurationService $accountingConfig,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly IdempotencyGuard $idempotency,
    ) {}

    /**
     * `approved -> posted`. Takes a row lock on the run BEFORE
     * resolving the accounting configuration or calling
     * `LedgerService::post()` -- unlike `approve()`'s conditional-UPDATE
     * claim, posting has an external side effect (a real `JournalEntry`
     * committed in Finance's own tables) that a bare "UPDATE ... WHERE
     * status = 'approved'" race could not safely undo: if two
     * concurrent callers were both allowed to call `LedgerService::post()`
     * before either updated `payroll_runs.status`, the race's loser
     * would leave a valid, permanently-posted orphan `JournalEntry` in
     * Finance's books, attached to no `PayrollRunPosting` row
     * (`payroll_run_postings_one_original_per_run`'s partial unique
     * index would reject only the SECOND insert attempt into
     * `payroll_run_postings`, not the journal entry already posted to
     * Finance). The row lock closes that window: the losing process
     * blocks until the winner's transaction commits, then observes
     * `status = 'posted'` on the now-unlocked row and is rejected
     * before ever calling `LedgerService::post()` at all -- mirrors
     * `calculate()`'s identical `lockForUpdate()` rationale
     * (Checkpoint 9.4), extended here because posting's side effect is
     * external to this table.
     *
     * Phase 9.8 idempotency correction: `$idempotencyRecord`, when
     * supplied by `App\Http\Middleware\EnsureIdempotent` via the
     * controller (a NEW claim only -- a replay never reaches this
     * method at all), is completed via
     * `IdempotencyGuard::completeWithin()` INSIDE this same
     * transaction -- the row lock, the Finance posting, the
     * `PayrollRunPosting` insert, the status transition, the audit
     * event, AND the idempotency completion all commit or roll back
     * together. This closes the generic middleware's crash window
     * entirely for this endpoint (see `IdempotencyGuard`'s own
     * docblock: "a critical endpoint that cannot tolerate this MUST
     * call completeWithin() inside the SAME database transaction as
     * its own authoritative state change") -- financial posting cannot
     * tolerate a crash between "committed" and "marked complete"
     * leaving a retry to see a stale in-flight record. This class
     * retains transaction ownership throughout; no controller-owned
     * transaction was introduced to achieve this.
     */
    public function post(PayrollRun $run, User $actor, ?ApiIdempotencyKey $idempotencyRecord = null): PayrollRunPosting
    {
        $school = $run->school;

        return $this->context->withSchool($school, function () use ($school, $run, $actor, $idempotencyRecord) {
            return DB::transaction(function () use ($school, $run, $actor, $idempotencyRecord) {
                $locked = PayrollRun::query()->where('id', $run->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== 'approved') {
                    throw new InvalidRunTransitionException($locked->status, 'posted');
                }

                $config = $this->accountingConfig->resolveValidated($school);
                $lines = $this->buildPostingLines($run, $config);

                $posted = $this->ledger->post($school, new PostJournalEntryData(
                    currency: self::CURRENCY,
                    description: "Payroll run {$run->id} (period {$run->payroll_period_id})",
                    lines: $lines,
                ), $actor);

                $posting = PayrollRunPosting::query()->create([
                    'school_id' => $school->id,
                    'payroll_run_id' => $run->id,
                    'journal_entry_id' => $posted->journalEntryId,
                    'currency' => self::CURRENCY,
                    'posting_kind' => 'original',
                    'actor_user_id' => $actor->id,
                ]);

                DB::table('payroll_runs')->where('id', $run->id)->update([
                    'status' => 'posted',
                    'posted_by_user_id' => $actor->id,
                    'posted_at' => now(),
                ]);

                $this->audit->school($school, 'payroll.run.posted', actor: $actor, subject: $run, metadata: [
                    'journalEntryId' => $posted->journalEntryId,
                    'lineCount' => count($lines),
                ]);

                event(new PayrollRunPosted($school->id, $run->id, $posted->journalEntryId, count($lines), $actor->id));

                if ($idempotencyRecord !== null) {
                    $this->idempotency->completeWithin($idempotencyRecord, 201, [
                        'data' => PayrollRunPostingSummary::fromModel($posting)->toArray(),
                    ]);
                }

                return $posting;
            });
        });
    }

    /**
     * Reverses a posted run's ORIGINAL posting via
     * `LedgerService::reverseById()` -- never mutates `payroll_runs.status`
     * (which stays `posted` forever, ADR 0032) nor the original
     * `PayrollRunPosting` row; a reversal is always a NEW, append-only
     * row (`TenantRls::makeAppendOnly()`, Checkpoint 9.1) linked via
     * `reversal_of_payroll_run_posting_id`.
     *
     * Phase 9.8 idempotency correction: same `completeWithin()`-inside-
     * the-transaction treatment as `post()`, for the identical reason
     * (financial, cannot tolerate the generic middleware's crash
     * window). Posting and reversal are DIFFERENT HTTP routes
     * (`schools.payroll-runs.post` vs `schools.payroll-runs.reverse`),
     * so `IdempotencyGuard`'s own uniqueness scope -- which includes
     * `route_action` -- already keeps their replay namespaces
     * structurally separate even if a client reused the identical
     * literal `Idempotency-Key` string for both; no extra scoping work
     * was needed here to satisfy that.
     */
    public function reverse(PayrollRun $run, User $actor, ?string $reason = null, ?ApiIdempotencyKey $idempotencyRecord = null): PayrollRunPosting
    {
        $school = $run->school;

        return $this->context->withSchool($school, function () use ($school, $run, $actor, $reason, $idempotencyRecord) {
            return DB::transaction(function () use ($school, $run, $actor, $reason, $idempotencyRecord) {
                $locked = PayrollRun::query()->where('id', $run->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== 'posted') {
                    throw new PayrollRunNotPostedException($run->id, $locked->status);
                }

                $original = PayrollRunPosting::query()
                    ->where('school_id', $school->id)
                    ->where('payroll_run_id', $run->id)
                    ->where('posting_kind', 'original')
                    ->first();

                if ($original === null || $original->reversal()->exists()) {
                    throw new PayrollRunAlreadyReversedException($run->id);
                }

                try {
                    $reversalEntry = $this->ledger->reverseById($school, $original->journal_entry_id, $actor, $reason);
                } catch (JournalEntryAlreadyReversedException) {
                    throw new PayrollRunAlreadyReversedException($run->id);
                }

                $reversal = PayrollRunPosting::query()->create([
                    'school_id' => $school->id,
                    'payroll_run_id' => $run->id,
                    'journal_entry_id' => $reversalEntry->journalEntryId,
                    'currency' => self::CURRENCY,
                    'posting_kind' => 'reversal',
                    'reversal_of_payroll_run_posting_id' => $original->id,
                    'actor_user_id' => $actor->id,
                    'reason' => $reason,
                ]);

                $this->audit->school($school, 'payroll.run.reversed', actor: $actor, subject: $run, metadata: [
                    'originalPostingId' => $original->id,
                    'reversalJournalEntryId' => $reversalEntry->journalEntryId,
                ]);

                event(new PayrollRunReversed($school->id, $run->id, $original->id, $reversalEntry->journalEntryId, $actor->id));

                if ($idempotencyRecord !== null) {
                    $this->idempotency->completeWithin($idempotencyRecord, 201, [
                        'data' => PayrollRunPostingSummary::fromModel($reversal)->toArray(),
                    ]);
                }

                return $reversal;
            });
        });
    }

    /**
     * @return list<JournalLineData>
     */
    private function buildPostingLines(PayrollRun $run, PayrollAccountingConfiguration $config): array
    {
        $results = PayrollRunResult::query()->where('payroll_run_id', $run->id)->get();

        $payableTotal = Money::of('0.00', self::CURRENCY);
        foreach ($results as $result) {
            $payableTotal = $payableTotal->add(Money::of($result->net_amount, self::CURRENCY));
        }

        $resultLines = PayrollRunResultLine::query()
            ->whereIn('payroll_run_result_id', $results->pluck('id'))
            ->with('component')
            ->get();

        $lines = [];

        // Total GROSS earnings (never net-of-deductions) -- summed
        // separately from $payableTotal precisely so the two can
        // differ by exactly $deductions, which is what makes the
        // resulting entry balance (see this method's own docblock).
        $grossEarningTotal = Money::of('0.00', self::CURRENCY);
        foreach ($resultLines->filter(fn (PayrollRunResultLine $l) => $l->component->isEarning()) as $line) {
            $grossEarningTotal = $this->applyEffect($grossEarningTotal, $line);
        }

        if (! $grossEarningTotal->isZero()) {
            $lines[] = $grossEarningTotal->isPositive()
                ? new JournalLineData($config->salary_expense_ledger_account_id, JournalSide::Debit, $grossEarningTotal)
                : new JournalLineData($config->salary_expense_ledger_account_id, JournalSide::Credit, $grossEarningTotal->negated());
        }

        if (! $payableTotal->isZero()) {
            $lines[] = $payableTotal->isPositive()
                ? new JournalLineData($config->salary_payable_ledger_account_id, JournalSide::Credit, $payableTotal)
                : new JournalLineData($config->salary_payable_ledger_account_id, JournalSide::Debit, $payableTotal->negated());
        }

        $deductionLines = $resultLines->filter(fn (PayrollRunResultLine $l) => $l->component->isDeduction());

        foreach ($deductionLines->groupBy('salary_component_id') as $salaryComponentId => $group) {
            $total = Money::of('0.00', self::CURRENCY);
            foreach ($group as $line) {
                $total = $this->applyEffect($total, $line);
            }

            if ($total->isZero()) {
                continue;
            }

            $ledgerAccountId = $this->resolveDeductionLedgerAccountId($run, $group->first());

            $lines[] = $total->isPositive()
                ? new JournalLineData($ledgerAccountId, JournalSide::Credit, $total)
                : new JournalLineData($ledgerAccountId, JournalSide::Debit, $total->negated());
        }

        return $lines;
    }

    /**
     * Resolves and validates the deduction's CURRENT liability account
     * live, from `SalaryComponent::liability_ledger_account_id` --
     * never from `payroll_run_result_lines.resolved_ledger_account_id`
     * (a calculation-time snapshot, deliberately not authoritative for
     * posting; see this class's own docblock). Same three checks as
     * `PayrollAccountingConfigurationService::validateAccounts()`:
     * configured (not null), active, and correctly typed (`liability`)
     * -- applied here rather than delegated to that service, since it
     * validates the School-wide expense/payable pair, not a
     * per-component mapping.
     */
    private function resolveDeductionLedgerAccountId(PayrollRun $run, PayrollRunResultLine $line): string
    {
        $ledgerAccountId = $line->component->liability_ledger_account_id;

        if ($ledgerAccountId === null) {
            throw new DeductionMissingLedgerMappingException($line->salary_component_id, $run->id);
        }

        $ledgerAccount = LedgerAccount::query()->where('school_id', $run->school_id)->find($ledgerAccountId);

        if ($ledgerAccount === null || ! $ledgerAccount->isActive() || $ledgerAccount->type !== 'liability') {
            throw new DeductionMissingLedgerMappingException($line->salary_component_id, $run->id);
        }

        return $ledgerAccountId;
    }

    private function applyEffect(Money $running, PayrollRunResultLine $line): Money
    {
        $delta = Money::of($line->amount, self::CURRENCY);

        return $line->effect === 'increase' ? $running->add($delta) : $running->add($delta->negated());
    }
}
