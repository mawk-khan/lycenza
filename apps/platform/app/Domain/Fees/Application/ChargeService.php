<?php

namespace App\Domain\Fees\Application;

use App\Domain\Fees\Application\Exceptions\AcademicYearNotFoundException;
use App\Domain\Fees\Application\Exceptions\ChargeAlreadyCancelledException;
use App\Domain\Fees\Application\Exceptions\ChargeHasPaymentAllocationsException;
use App\Domain\Fees\Application\Exceptions\ChargeNotFoundException;
use App\Domain\Fees\Application\Exceptions\InvalidChargeException;
use App\Domain\Fees\Application\Exceptions\StudentNotFoundException;
use App\Domain\Fees\Events\ChargeAssessed;
use App\Domain\Fees\Events\ChargeCancelled;
use App\Domain\Fees\Infrastructure\Charge;
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

/**
 * Phase 0G.4: the only sanctioned write path for `charges` -- never
 * write the `Charge` model directly from anywhere else, mirroring
 * `App\Domain\Finance\Application\LedgerService`'s exact established
 * pattern (validate -> post through the ledger -> write receivable
 * state -> audit -> emit domain event, inside one transaction, ADR
 * 0025).
 *
 * NOT an authorization boundary, for exactly the same reason
 * `LedgerService` is not one: `$actor` is passed through purely for
 * WHO-did-this audit provenance. Whether a given actor is ALLOWED to
 * call `assess()`/`cancel()` at all is `App\Domain\Fees\Application\ChargeAdministrationService`'s
 * job (a human/staff caller always goes through THAT class, checking
 * `finance.charges.manage`) -- this split exists for the identical
 * reason `LedgerAdministrationService` is split from `LedgerService`:
 * a FUTURE trusted internal caller (e.g. a later enrollment-triggered
 * billing command -- not implemented here, FINANCE.md "Student/
 * enrollment boundary" explicitly defers it) can call this class
 * directly without impersonating a human staff capability grant.
 *
 * Calls `LedgerService::post()`/`reverseById()` directly (the TRUSTED
 * core), never `LedgerAdministrationService` -- going through the
 * administrative facade would require the acting user to ALSO hold
 * `finance.ledger.post`/`.reverse`, conflating two genuinely separate
 * administrative responsibilities (assessing a fee vs. administering
 * the raw ledger) that FINANCE.md's authorization architecture already
 * keeps distinct (`finance.charges.*` vs. `finance.ledger.*`).
 *
 * `App\Domain\Fees` never reads `App\Domain\Students`' `Student` or
 * `App\Domain\AcademicStructure`'s `AcademicYear` Eloquent models
 * directly (CLAUDE.md rule 4) -- `charges_student_fk`/
 * `charges_academic_year_fk` (composite foreign keys against
 * `students`/`academic_years`, see the migration) are the sole,
 * structural source of truth for same-School subject/period
 * membership; a constraint violation on the `Charge::create()` below
 * is caught and translated into the matching typed exception. Ledger
 * account existence/School/currency ownership is likewise never
 * re-checked here -- `LedgerService::post()` already proves it (via
 * `LedgerAccountNotFoundException`) before this method ever attempts
 * to persist a `Charge` row.
 */
class ChargeService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * Recognition is IMMEDIATE (docs/modules/FINANCE.md 0G.4 as-built,
     * "Recognition point"): the `charges` row and its `journal_entries`
     * posting are created together, inside ONE outer transaction --
     * `LedgerService::post()`'s own `DB::transaction()` becomes a
     * PostgreSQL SAVEPOINT nested inside this method's outer one
     * (Laravel's automatic nested-transaction behavior), so a failure
     * at EITHER step (ledger posting OR the `Charge` insert) rolls
     * back both; nothing is ever left half-recognized. `TenantContext::withSchool()`
     * wraps the OUTER transaction specifically so the RLS session GUC
     * stays correctly set through the real COMMIT both this table's
     * and `journal_entries`' triggers/RLS depend on -- this method
     * must never clear/switch TenantContext before the transaction
     * commits (same invariant `LedgerService` itself documents).
     *
     * Debit Accounts Receivable ($data->receivableLedgerAccountId),
     * Credit Revenue ($data->revenueLedgerAccountId) -- the standard
     * fee-recognition entry; both lines carry the exact same `$data->amount`,
     * so the entry is balanced by construction (no separate balance
     * check needed here -- `LedgerService::post()`'s own `assertBalanced()`
     * still runs regardless, structurally redundant here but never
     * skipped).
     */
    public function assess(School $school, AssessChargeData $data, ?User $actor = null): ChargeResult
    {
        if ($data->receivableLedgerAccountId === $data->revenueLedgerAccountId) {
            throw new InvalidChargeException(
                'Receivable and revenue ledger accounts must be different.'
            );
        }

        return $this->context->withSchool($school, function () use ($school, $data, $actor) {
            return DB::transaction(function () use ($school, $data, $actor) {
                $posted = $this->ledger->post($school, new PostJournalEntryData(
                    currency: $data->amount->currency(),
                    description: $data->description,
                    lines: [
                        new JournalLineData($data->receivableLedgerAccountId, JournalSide::Debit, $data->amount),
                        new JournalLineData($data->revenueLedgerAccountId, JournalSide::Credit, $data->amount),
                    ],
                ), $actor);

                try {
                    $charge = Charge::query()->create([
                        'school_id' => $school->id,
                        'student_id' => $data->studentId,
                        'academic_year_id' => $data->academicYearId,
                        'description' => $data->description,
                        'amount' => $data->amount->amount(),
                        'currency' => $data->amount->currency(),
                        'due_date' => $data->dueDate,
                        'receivable_ledger_account_id' => $data->receivableLedgerAccountId,
                        'revenue_ledger_account_id' => $data->revenueLedgerAccountId,
                        'journal_entry_id' => $posted->journalEntryId,
                    ]);
                } catch (QueryException $e) {
                    if ($this->violatesConstraint($e, 'charges_student_fk')) {
                        throw new StudentNotFoundException($data->studentId);
                    }

                    if ($this->violatesConstraint($e, 'charges_academic_year_fk')) {
                        throw new AcademicYearNotFoundException($data->academicYearId);
                    }

                    throw $e;
                }

                $this->audit->school($school, 'charge.assessed', actor: $actor, subject: $charge, metadata: [
                    'studentId' => $charge->student_id,
                    'currency' => $charge->currency,
                ]);

                event(new ChargeAssessed($school->id, $charge->id, $charge->student_id, $charge->journal_entry_id, $charge->currency));

                return ChargeResult::fromModel($charge->refresh());
            });
        });
    }

    /**
     * Reverses the charge's accounting effect via
     * `LedgerService::reverseById()` (never a direct write to
     * `journal_entries`/`journal_lines`) and marks the charge
     * cancelled -- both inside one transaction. The ORIGINAL `charges`
     * row's `amount`/`student_id`/`academic_year_id`/`currency`/
     * account mapping/`journal_entry_id` are never rewritten; only
     * `cancelled_at`/`cancellation_journal_entry_id` transition,
     * together, exactly once.
     *
     * Concurrency: the REAL guarantee is `journal_entries_reversal_of_unique`
     * (ADR 0030) -- `LedgerService::reverseById()` itself throws
     * `JournalEntryAlreadyReversedException` if a racing caller already
     * won the reversal for this charge's `journal_entry_id` (both
     * callers can pass the sequential `isCancelled()` pre-check below
     * before either has committed). That Finance-layer exception is
     * caught here and translated to THIS class's own
     * `ChargeAlreadyCancelledException` -- a caller of `ChargeService`
     * never needs to know about `App\Domain\Finance`'s exception
     * types. The `charges` UPDATE itself is additionally written as a
     * conditional `WHERE cancelled_at IS NULL` with an affected-row
     * check (defense in depth, rule 10's "structural, not just app
     * validation" principle) -- 0 affected rows raises the SAME
     * exception too, never a distinction between "someone already knew
     * that" and "someone found out just now."
     *
     * Phase 0G.5 (rule 46): the initial lookup now takes a row-level
     * `SELECT ... FOR UPDATE` lock (`lockForUpdate()`), held for the
     * remainder of this method's outer transaction -- the SAME lock
     * `lockChargeForAllocation()` (below) takes from
     * `App\Domain\Payments\Application\PaymentProviderEventService`.
     * Whichever of a concurrent cancel-vs-allocate pair reaches this
     * charge row first genuinely blocks the other until it commits or
     * rolls back, giving the two operations one coherent ordering rather
     * than a race (rule 48). The database's own
     * `charges_payment_allocation_guard_trigger`
     * (`App\Domain\Payments`' `create_payment_allocations_table`
     * migration) is the AUTHORITATIVE rejection mechanism for "this
     * charge already has recognized allocations" -- caught below and
     * translated to `ChargeHasPaymentAllocationsException`, this class's
     * own typed error; Fees' PHP code never reads `payment_allocations`
     * directly (CLAUDE.md rule 4).
     */
    public function cancel(School $school, string $chargeId, ?User $actor = null, ?string $reason = null): ChargeResult
    {
        return $this->context->withSchool($school, function () use ($school, $chargeId, $actor, $reason) {
            return DB::transaction(function () use ($school, $chargeId, $actor, $reason) {
                $charge = Charge::query()->where('school_id', $school->id)->lockForUpdate()->find($chargeId);

                if ($charge === null) {
                    throw new ChargeNotFoundException($chargeId);
                }

                if ($charge->isCancelled()) {
                    throw new ChargeAlreadyCancelledException($chargeId);
                }

                try {
                    $reversal = $this->ledger->reverseById($school, $charge->journal_entry_id, $actor, $reason);
                } catch (JournalEntryAlreadyReversedException) {
                    throw new ChargeAlreadyCancelledException($charge->id);
                }

                try {
                    $affected = Charge::query()
                        ->where('id', $charge->id)
                        ->whereNull('cancelled_at')
                        ->update([
                            'cancelled_at' => now(),
                            'cancellation_journal_entry_id' => $reversal->journalEntryId,
                        ]);
                } catch (QueryException $e) {
                    if ($this->violatesConstraint($e, 'charges_payment_allocation_guard_trigger')
                        || str_contains($e->getMessage(), 'recognized payment allocations exist')) {
                        throw new ChargeHasPaymentAllocationsException($charge->id);
                    }

                    throw $e;
                }

                if ($affected !== 1) {
                    throw new ChargeAlreadyCancelledException($charge->id);
                }

                $charge->refresh();

                $this->audit->school($school, 'charge.cancelled', actor: $actor, subject: $charge, metadata: [
                    'cancellationJournalEntryId' => $reversal->journalEntryId,
                ]);

                event(new ChargeCancelled($school->id, $charge->id, $reversal->journalEntryId, $charge->journal_entry_id));

                return ChargeResult::fromModel($charge);
            });
        });
    }

    /**
     * Phase 0G.5: the ONE sanctioned way
     * `App\Domain\Payments\Application\PaymentProviderEventService` (a
     * DIFFERENT module, which DOES depend on Fees per DOMAIN-MAP.md) may
     * observe or lock a Charge -- never a direct read of the `Charge`
     * Eloquent model/`charges` table (CLAUDE.md rule 4). Takes the SAME
     * `SELECT ... FOR UPDATE` row lock `cancel()` takes, so a concurrent
     * allocation-vs-cancellation race genuinely serializes (rule 48);
     * the caller is responsible for locking multiple Charges in
     * ascending id order within one operation (rule 33) to avoid
     * deadlock -- this method itself only ever locks the one row named.
     * Returns a typed, safe snapshot -- never the raw model.
     */
    public function lockChargeForAllocation(School $school, string $chargeId): ChargeAllocationSnapshot
    {
        return $this->context->withSchool($school, function () use ($school, $chargeId) {
            $charge = Charge::query()->where('school_id', $school->id)->lockForUpdate()->find($chargeId);

            if ($charge === null) {
                throw new ChargeNotFoundException($chargeId);
            }

            return new ChargeAllocationSnapshot(
                chargeId: $charge->id,
                amount: Money::of($charge->amount, $charge->currency),
                receivableLedgerAccountId: $charge->receivable_ledger_account_id,
                isCancelled: $charge->isCancelled(),
            );
        });
    }

    private function violatesConstraint(QueryException $e, string $constraintName): bool
    {
        return str_contains($e->getMessage(), $constraintName);
    }
}
