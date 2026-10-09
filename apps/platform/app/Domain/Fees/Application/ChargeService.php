<?php

namespace App\Domain\Fees\Application;

use App\Domain\Fees\Application\Exceptions\AcademicYearNotFoundException;
use App\Domain\Fees\Application\Exceptions\ChargeAlreadyCancelledException;
use App\Domain\Fees\Application\Exceptions\ChargeHasActiveAdjustmentsException;
use App\Domain\Fees\Application\Exceptions\ChargeHasLiveLateFeeException;
use App\Domain\Fees\Application\Exceptions\ChargeHasPaymentAllocationsException;
use App\Domain\Fees\Application\Exceptions\ChargeIsFeeAssessedException;
use App\Domain\Fees\Application\Exceptions\ChargeIsLateFeeException;
use App\Domain\Fees\Application\Exceptions\ChargeIsSourceChargeException;
use App\Domain\Fees\Application\Exceptions\ChargeNotFoundException;
use App\Domain\Fees\Application\Exceptions\InvalidChargeException;
use App\Domain\Fees\Application\Exceptions\StudentNotFoundException;
use App\Domain\Fees\Events\ChargeAssessed;
use App\Domain\Fees\Events\ChargeCancelled;
use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Fees\Infrastructure\FeeAdjustment;
use App\Domain\Fees\Infrastructure\FeeAssessment;
use App\Domain\Fees\Infrastructure\FeeHead;
use App\Domain\Fees\Infrastructure\FeeStructureInstallment;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
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

                    // FEE.5 (ADR 0062 §16.3): a late fee is cancelled only by
                    // voiding it; its source waits until the late fee is voided.
                    if (str_contains($e->getMessage(), 'is a late fee; void its late-fee assessment')) {
                        throw new ChargeIsLateFeeException($charge->id);
                    }
                    if (str_contains($e->getMessage(), 'has a live late fee; void the late fee first')) {
                        throw new ChargeHasLiveLateFeeException($charge->id);
                    }

                    // OPF.4 (ADR 0067 §17, D4): a source event charge is cancelled
                    // only by voiding it at its source.
                    if (str_contains($e->getMessage(), 'is a source event charge; void it at its source')) {
                        throw new ChargeIsSourceChargeException($charge->id);
                    }

                    // FEE.3 (ADR 0062 §14.7): cancel live adjustments first.
                    if (str_contains($e->getMessage(), 'has active fee adjustments; cancel them first')) {
                        throw new ChargeHasActiveAdjustmentsException($charge->id);
                    }

                    // FEE.2 (ADR 0062 §11.3): a fee-assessed charge is
                    // cancelled only through the assessment void path.
                    if (str_contains($e->getMessage(), 'is fee-assessed; void its fee assessment')) {
                        throw new ChargeIsFeeAssessedException($charge->id);
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
                adjustedTotal: $this->liveAdjustmentTotalsFor($school, [$charge->id])[$charge->id] ?? Money::of('0.00', $charge->currency),
            );
        });
    }

    /**
     * FEE.3 (ADR 0062 §15): the live (uncancelled) fee-adjustment total per
     * charge, for Payments' "outstanding" display. Trusted, read-only, no
     * capability check (the caller authorizes, like
     * `uncancelledChargesForStudent()`); ids of another School simply
     * contribute nothing.
     *
     * @param  list<string>  $chargeIds
     * @return array<string, Money> keyed by charge id; charges without adjustments are absent
     */
    public function liveAdjustmentTotalsFor(School $school, array $chargeIds): array
    {
        if ($chargeIds === []) {
            return [];
        }

        return $this->context->withSchool($school, fn () => FeeAdjustment::query()
            ->whereIn('charge_id', $chargeIds)
            ->whereNull('cancelled_at')
            ->groupBy('charge_id', 'currency')
            ->selectRaw('charge_id, currency, sum(amount) as total')
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->getAttribute('charge_id') => Money::of((string) $row->getAttribute('total'), (string) $row->getAttribute('currency'))])
            ->all());
    }

    /**
     * FEE.4 (ADR 0062 §18): one Student's charges (optionally one
     * AcademicYear), oldest first, as statement lines. Trusted and
     * read-only -- `App\Domain\Payments`' statement service authorizes
     * (`finance.charges.view` AND `finance.payments.view`) and composes
     * allocations, Payments and receipts itself. Another School's Student
     * simply has no lines.
     *
     * @return list<ChargeStatementLine>
     */
    public function statementLinesForStudent(School $school, string $studentId, ?string $academicYearId = null): array
    {
        return $this->statementLines($school, fn ($q) => $q
            ->where('student_id', $studentId)
            ->when($academicYearId !== null, fn ($w) => $w->where('academic_year_id', $academicYearId)));
    }

    /**
     * POR.3 (ADR 0070 §26): one Student's lines, where the Student must ALSO be
     * in `$eligibleStudentIds` (a caller-supplied id subquery, e.g. Guardians'
     * GuardianStudentScope predicate) -- the authorization and the read are one
     * statement. Same lines and semantics as statementLinesForStudent().
     *
     * @return list<ChargeStatementLine>
     */
    public function statementLinesForStudentWithin(School $school, string $studentId, QueryBuilder $eligibleStudentIds): array
    {
        return $this->statementLines($school, fn ($q) => $q
            ->where('student_id', $studentId)
            ->whereIn('student_id', $eligibleStudentIds));
    }

    /**
     * FEE.4: the same lines for named charges (a receipt's allocations).
     *
     * @param  list<string>  $chargeIds
     * @return list<ChargeStatementLine>
     */
    public function statementLinesForCharges(School $school, array $chargeIds): array
    {
        return $chargeIds === [] ? [] : $this->statementLines($school, fn ($q) => $q->whereIn('id', $chargeIds));
    }

    /**
     * @param  callable(Builder<Charge>): mixed  $scope
     * @return list<ChargeStatementLine>
     */
    private function statementLines(School $school, callable $scope): array
    {
        return $this->context->withSchool($school, function () use ($school, $scope) {
            $query = Charge::query()->where('school_id', $school->id);
            $scope($query);
            $charges = $query->orderBy('created_at')->orderBy('id')->limit(1000)->get();
            if ($charges->isEmpty()) {
                return [];
            }

            $ids = $charges->pluck('id')->all();
            $assessments = FeeAssessment::query()->whereIn('charge_id', $ids)->get()->keyBy('charge_id');
            $heads = FeeHead::query()->whereIn('id', $assessments->pluck('fee_head_id')->unique()->all())->pluck('name', 'id');
            $periods = FeeStructureInstallment::query()->whereIn('id', $assessments->pluck('fee_structure_installment_id')->unique()->all())->pluck('label', 'id');
            $adjustments = FeeAdjustment::query()->whereIn('charge_id', $ids)->orderBy('created_at')->orderBy('id')->get()->groupBy('charge_id');

            return $charges->map(function (Charge $charge) use ($assessments, $heads, $periods, $adjustments) {
                $assessment = $assessments->get($charge->id);
                $rows = $adjustments->get($charge->id, collect());
                $live = Money::of('0.00', $charge->currency);
                foreach ($rows as $row) {
                    if ($row->isLive()) {
                        $live = $live->add(Money::of($row->amount, $row->currency));
                    }
                }

                return new ChargeStatementLine(
                    chargeId: $charge->id,
                    studentId: $charge->student_id,
                    academicYearId: $charge->academic_year_id,
                    description: $charge->description,
                    amount: $charge->amount,
                    currency: $charge->currency,
                    dueDate: $charge->due_date?->toDateString(),
                    assessedAt: $charge->created_at,
                    cancelledAt: $charge->cancelled_at,
                    feeHeadId: $assessment?->fee_head_id,
                    feeHeadName: $assessment ? ($heads[$assessment->fee_head_id] ?? null) : null,
                    billingPeriodKey: $assessment?->billing_period_key,
                    billingPeriodLabel: $assessment ? ($periods[$assessment->fee_structure_installment_id] ?? null) : null,
                    adjustments: $rows->map(fn (FeeAdjustment $a) => [
                        'id' => $a->id,
                        'feeConcessionId' => $a->fee_concession_id,
                        'category' => $a->category,
                        'amount' => $a->amount,
                        'postedAt' => $a->created_at->toIso8601String(),
                        'cancelledAt' => $a->cancelled_at?->toIso8601String(),
                    ])->values()->all(),
                    liveAdjustedTotal: $live->amount(),
                );
            })->values()->all();
        });
    }

    /**
     * FEE.5 (ADR 0062 §16.2): the late-fee candidates of one rule scope --
     * uncancelled charges with a LIVE fee assessment of the structure (and
     * head, when set), oldest due first. Trusted and read-only;
     * `App\Domain\Payments`' late-fee run authorizes and computes the
     * outstanding itself. Late-fee charges have no fee assessment, so they
     * are never candidates.
     *
     * @return list<LateFeeSourceFacts>
     */
    public function lateFeeCandidates(School $school, string $feeStructureId, ?string $feeHeadId): array
    {
        return $this->context->withSchool($school, fn () => $this->lateFeeSources($school, fn ($q) => $q
            ->where('fa.fee_structure_id', $feeStructureId)
            ->when($feeHeadId !== null, fn ($w) => $w->where('fa.fee_head_id', $feeHeadId))));
    }

    /** FEE.5: the same facts for one charge at execution (null when it is no longer a live, uncancelled structure charge). */
    public function lateFeeSource(School $school, string $chargeId): ?LateFeeSourceFacts
    {
        return $this->context->withSchool($school, fn () => $this->lateFeeSources($school, fn ($q) => $q->where('c.id', $chargeId))[0] ?? null);
    }

    /**
     * @param  callable(QueryBuilder): mixed  $scope
     * @return list<LateFeeSourceFacts>
     */
    private function lateFeeSources(School $school, callable $scope): array
    {
        $query = DB::table('charges as c')
            ->join('fee_assessments as fa', fn ($j) => $j->on('fa.charge_id', '=', 'c.id')->whereNull('fa.voided_at'))
            ->where('c.school_id', $school->id)
            ->whereNull('c.cancelled_at')
            ->select('c.id', 'c.student_id', 'c.academic_year_id', 'fa.fee_structure_id', 'fa.fee_head_id', 'fa.billing_period_key', 'c.due_date', 'c.amount', 'c.currency', 'c.description');
        $scope($query);

        return $query->orderBy('c.due_date')->orderBy('c.id')->get()
            ->map(fn ($r) => new LateFeeSourceFacts(
                chargeId: (string) $r->id,
                studentId: (string) $r->student_id,
                academicYearId: (string) $r->academic_year_id,
                feeStructureId: (string) $r->fee_structure_id,
                feeHeadId: (string) $r->fee_head_id,
                billingPeriodKey: (string) $r->billing_period_key,
                dueDate: $r->due_date === null ? null : substr((string) $r->due_date, 0, 10),
                amount: (string) $r->amount,
                currency: (string) $r->currency,
                description: (string) $r->description,
            ))->values()->all();
    }

    /**
     * Phase 0O.11A: a trusted, read-only lookup (no capability check --
     * the caller authorizes, exactly like `lockChargeForAllocation()`) of
     * one Student's uncancelled Charges in this School, oldest first,
     * bounded by `$limit` -- the candidates a manually recorded payment
     * may be allocated to. A cross-School Student id simply returns no
     * rows (School-scoped query under the School's TenantContext).
     *
     * @return Collection<int, ChargeSummary>
     */
    public function uncancelledChargesForStudent(School $school, string $studentId, int $limit = 100): Collection
    {
        return $this->context->withSchool($school, fn () => Charge::query()
            ->where('school_id', $school->id)
            ->where('student_id', $studentId)
            ->whereNull('cancelled_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (Charge $charge) => ChargeSummary::fromModel($charge))
            ->values());
    }

    /**
     * E21.3A (ADR 0064 §5): every charge of the School with the journal
     * entries that assessed and cancelled it, for Payments' charge-state
     * baseline and dual-read check. Trusted, read-only, no capability check
     * (the financial-period close authorizes). Ordered by id. E21.3A2:
     * `$chargeIds` limits it to those charges (the carry-forward reads).
     *
     * @param  list<string>|null  $chargeIds
     * @return list<ChargeLedgerFact>
     */
    public function ledgerFacts(School $school, ?array $chargeIds = null): array
    {
        if ($chargeIds === []) {
            return [];
        }

        return $this->context->withSchool($school, function () use ($school, $chargeIds) {
            $facts = [];
            foreach (Charge::query()->where('school_id', $school->id)->when($chargeIds !== null, fn ($q) => $q->whereIn('id', $chargeIds))->orderBy('id')
                ->toBase()->select(['id', 'student_id', 'amount', 'currency', 'journal_entry_id', 'cancelled_at', 'cancellation_journal_entry_id'])
                ->cursor() as $row) {
                $facts[] = new ChargeLedgerFact(
                    id: (string) $row->id,
                    studentId: (string) $row->student_id,
                    amount: (string) $row->amount,
                    currency: (string) $row->currency,
                    journalEntryId: (string) $row->journal_entry_id,
                    cancelled: $row->cancelled_at !== null,
                    cancellationJournalEntryId: $row->cancellation_journal_entry_id === null ? null : (string) $row->cancellation_journal_entry_id,
                );
            }

            return $facts;
        });
    }

    /**
     * E21.3A: every fee adjustment of the School (live or voided) with its
     * posting and void journal entries. Same contract as `ledgerFacts()`.
     *
     * @param  list<string>|null  $chargeIds
     * @return list<FeeAdjustmentLedgerFact>
     */
    public function adjustmentLedgerFacts(School $school, ?array $chargeIds = null): array
    {
        if ($chargeIds === []) {
            return [];
        }

        return $this->context->withSchool($school, function () use ($school, $chargeIds) {
            $facts = [];
            foreach (FeeAdjustment::query()->where('school_id', $school->id)->when($chargeIds !== null, fn ($q) => $q->whereIn('charge_id', $chargeIds))->orderBy('id')
                ->toBase()->select(['id', 'charge_id', 'amount', 'journal_entry_id', 'cancelled_at', 'cancellation_journal_entry_id'])
                ->cursor() as $row) {
                $facts[] = new FeeAdjustmentLedgerFact(
                    id: (string) $row->id,
                    chargeId: (string) $row->charge_id,
                    amount: (string) $row->amount,
                    journalEntryId: (string) $row->journal_entry_id,
                    cancelled: $row->cancelled_at !== null,
                    cancellationJournalEntryId: $row->cancellation_journal_entry_id === null ? null : (string) $row->cancellation_journal_entry_id,
                );
            }

            return $facts;
        });
    }

    private function violatesConstraint(QueryException $e, string $constraintName): bool
    {
        return str_contains($e->getMessage(), $constraintName);
    }
}
