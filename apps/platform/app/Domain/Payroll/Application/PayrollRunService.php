<?php

namespace App\Domain\Payroll\Application;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\Exceptions\ConcurrentRunApprovalConflictException;
use App\Domain\Payroll\Application\Exceptions\CorrectionTargetAlreadyReversedException;
use App\Domain\Payroll\Application\Exceptions\DuplicateRegularRunException;
use App\Domain\Payroll\Application\Exceptions\InvalidCorrectionTargetException;
use App\Domain\Payroll\Application\Exceptions\InvalidRunTransitionException;
use App\Domain\Payroll\Application\Exceptions\NegativeNetPayException;
use App\Domain\Payroll\Application\Exceptions\PeriodNotOpenException;
use App\Domain\Payroll\Application\Exceptions\RunNotEditableException;
use App\Domain\Payroll\Application\Exceptions\SelfApprovalNotAllowedException;
use App\Domain\Payroll\Application\Exceptions\ZeroEffectCorrectionException;
use App\Domain\Payroll\Events\PayrollRunApproved;
use App\Domain\Payroll\Events\PayrollRunCalculated;
use App\Domain\Payroll\Events\PayrollRunCreated;
use App\Domain\Payroll\Infrastructure\CompensationAssignmentValue;
use App\Domain\Payroll\Infrastructure\EmployeeCompensationAssignment;
use App\Domain\Payroll\Infrastructure\PayrollAdjustment;
use App\Domain\Payroll\Infrastructure\PayrollPeriod;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Infrastructure\PayrollRunHrxInput;
use App\Domain\Payroll\Infrastructure\PayrollRunPosting;
use App\Domain\Payroll\Infrastructure\PayrollRunResult;
use App\Domain\Payroll\Infrastructure\PayrollRunResultLine;
use App\Domain\Payroll\Infrastructure\SalaryStructure;
use App\Domain\StaffAttendance\Application\Payroll\PayrollAbsenceEvidence;
use App\Domain\StaffAttendance\Application\Payroll\PayrollAbsenceEvidenceReader;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 9.3 -- creates and calculates `payroll_runs` (ADR 0034 "Run
 * kinds, correction model, and posting" / "Partial-period policy").
 * `approve()`/`post()` do not exist here -- Checkpoints 9.4/9.5 own
 * those transitions; this class only ever moves a run `draft ->
 * calculated` (or keeps it at `draft` with partial results when some
 * EmploymentRecords remain unresolved).
 *
 * `calculate()` always replaces the ENTIRE result set atomically
 * (delete then re-insert, one transaction) -- never a partial patch --
 * and is safe to call repeatedly while the run is `draft`/`calculated`;
 * the database triggers (`trg_payroll_run_results_freeze`,
 * `trg_payroll_run_result_lines_freeze`) make it structurally
 * impossible once the run reaches `approved`.
 *
 * Capability gating (`payroll.runs.prepare`) is deliberately deferred
 * to Checkpoint 9.7 -- see `SalaryComponentService`'s identical note.
 */
class PayrollRunService
{
    private const CURRENCY = 'INR';

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly CompensationService $compensation,
        private readonly PayrollCalculationEngine $engine,
        private readonly PayrollAbsenceEvidenceReader $hrx,
    ) {}

    public function createRun(PayrollPeriod $period, User $actor): PayrollRun
    {
        $school = $period->school;

        if ($period->status !== 'open') {
            throw new PeriodNotOpenException($period->id, $period->status);
        }

        return $this->context->withSchool($school, function () use ($school, $period, $actor) {
            try {
                return DB::transaction(function () use ($school, $period, $actor) {
                    $run = PayrollRun::query()->create([
                        'school_id' => $school->id,
                        'payroll_period_id' => $period->id,
                        'run_kind' => 'regular',
                        'status' => 'draft',
                        'prepared_by_user_id' => $actor->id,
                    ]);

                    $this->audit->school($school, 'payroll.run.created', actor: $actor, subject: $run, metadata: [
                        'payrollPeriodId' => $period->id,
                        'runKind' => 'regular',
                    ]);

                    event(new PayrollRunCreated($school->id, $run->id, $period->id, 'regular', null));

                    return $run;
                });
            } catch (UniqueConstraintViolationException) {
                throw new DuplicateRegularRunException($period->id);
            }
        });
    }

    /**
     * Phase 9.5 correction (ADR 0034 "Run kinds, correction model, and
     * posting") -- creates a `correction` run against an original
     * `regular`, already-`posted` run. Application-layer pre-checks
     * (regular + posted + not-yet-reversed) give a clean domain error
     * ahead of the database trigger `trg_payroll_runs_validate_correction_target`
     * (Checkpoint 9.1), which remains the authoritative guarantee. A
     * `posted` run always has exactly one `original` `PayrollRunPosting`
     * row (`PayrollPostingService::post()` creates both atomically) --
     * `$originalPosting === null` is structurally unreachable given the
     * status check above it, but is treated identically to "already
     * reversed" anyway (fail closed, no oracle for a state that should
     * never occur).
     */
    public function createCorrectionRun(PayrollRun $correctsRun, PayrollPeriod $period, User $actor): PayrollRun
    {
        $school = $period->school;

        if ($period->status !== 'open') {
            throw new PeriodNotOpenException($period->id, $period->status);
        }

        if (! $correctsRun->isRegular() || $correctsRun->status !== 'posted') {
            throw new InvalidCorrectionTargetException("payroll run '{$correctsRun->id}' must be a posted regular run, got run_kind={$correctsRun->run_kind}, status={$correctsRun->status}.");
        }

        return $this->context->withSchool($school, function () use ($school, $correctsRun, $period, $actor) {
            $originalPosting = PayrollRunPosting::query()
                ->where('school_id', $school->id)
                ->where('payroll_run_id', $correctsRun->id)
                ->where('posting_kind', 'original')
                ->first();

            if ($originalPosting === null || $originalPosting->reversal()->exists()) {
                throw new CorrectionTargetAlreadyReversedException($correctsRun->id);
            }

            return DB::transaction(function () use ($school, $correctsRun, $period, $actor) {
                $run = PayrollRun::query()->create([
                    'school_id' => $school->id,
                    'payroll_period_id' => $period->id,
                    'run_kind' => 'correction',
                    'corrects_payroll_run_id' => $correctsRun->id,
                    'status' => 'draft',
                    'prepared_by_user_id' => $actor->id,
                ]);

                $this->audit->school($school, 'payroll.run.correction_created', actor: $actor, subject: $run, metadata: [
                    'correctsPayrollRunId' => $correctsRun->id,
                    'payrollPeriodId' => $period->id,
                ]);

                event(new PayrollRunCreated($school->id, $run->id, $period->id, 'correction', $correctsRun->id));

                return $run;
            });
        });
    }

    /**
     * Records one signed delta line against a correction run --
     * mirrors `recordManualOverride()`'s append-only shape exactly
     * (same `payroll_adjustments` table, `mode = 'correction_delta'`
     * instead of `manual_override`). No `run_kind` pre-check here, by
     * the SAME precedent `recordManualOverride()` already establishes:
     * the database trigger `trg_payroll_adjustments_validate_mode`
     * (Checkpoint 9.1) is the sole guard against a mode/run_kind
     * mismatch for either method.
     *
     * @param  list<CorrectionDeltaInput>  $lines
     */
    public function recordCorrectionDelta(PayrollRun $run, EmploymentRecord $employmentRecord, array $lines, string $reason, User $actor): void
    {
        $school = $run->school;

        if (! in_array($run->status, ['draft', 'calculated'], true)) {
            throw new RunNotEditableException($run->id, $run->status);
        }

        $this->context->withSchool($school, function () use ($school, $run, $employmentRecord, $lines, $reason, $actor) {
            DB::transaction(function () use ($school, $run, $employmentRecord, $lines, $reason, $actor) {
                foreach ($lines as $line) {
                    PayrollAdjustment::query()->create([
                        'school_id' => $school->id,
                        'payroll_run_id' => $run->id,
                        'employment_record_id' => $employmentRecord->id,
                        'salary_component_id' => $line->salaryComponentId,
                        'mode' => 'correction_delta',
                        'amount' => $line->amount,
                        'effect' => $line->effect,
                        'reason' => $reason,
                        'actor_user_id' => $actor->id,
                    ]);
                }

                $this->audit->school($school, 'payroll.run.correction_delta_recorded', actor: $actor, subject: $run, metadata: [
                    'employmentRecordId' => $employmentRecord->id,
                    'lineCount' => count($lines),
                ]);
            });
        });
    }

    /**
     * Records the authoritative, complete result for a partial-period
     * EmploymentRecord -- one row per component amount. `$componentAmounts`
     * is `salary_component_id => amount` (a decimal string); every
     * component named must belong to the EmploymentRecord's own
     * resolved structure for `calculate()` to build a coherent result,
     * but this method does not itself require that -- it is a plain,
     * audited record of what the human decided.
     *
     * `payroll_adjustments` is append-only (`TenantRls::makeAppendOnly()`,
     * Checkpoint 9.1) -- discovered while building this method: a
     * resubmission never deletes the prior rows (the runtime role has
     * no DELETE/UPDATE privilege on this table at all, by design, for
     * audit-trail integrity). Every call is a new, permanent, additive
     * record; `calculate()`'s own read side resolves "the current
     * value per component" as the LATEST row per `salary_component_id`
     * (Postgres `DISTINCT ON`), never by deleting anything.
     *
     * @param  array<string, string>  $componentAmounts
     */
    public function recordManualOverride(PayrollRun $run, EmploymentRecord $employmentRecord, array $componentAmounts, string $reason, User $actor): void
    {
        $school = $run->school;

        if (! in_array($run->status, ['draft', 'calculated'], true)) {
            throw new RunNotEditableException($run->id, $run->status);
        }

        $this->context->withSchool($school, function () use ($school, $run, $employmentRecord, $componentAmounts, $reason, $actor) {
            DB::transaction(function () use ($school, $run, $employmentRecord, $componentAmounts, $reason, $actor) {
                foreach ($componentAmounts as $componentId => $amount) {
                    PayrollAdjustment::query()->create([
                        'school_id' => $school->id,
                        'payroll_run_id' => $run->id,
                        'employment_record_id' => $employmentRecord->id,
                        'salary_component_id' => $componentId,
                        'mode' => 'manual_override',
                        'amount' => $amount,
                        'effect' => null,
                        'reason' => $reason,
                        'actor_user_id' => $actor->id,
                    ]);
                }

                $this->audit->school($school, 'payroll.run.manual_override_recorded', actor: $actor, subject: $run, metadata: [
                    'employmentRecordId' => $employmentRecord->id,
                    'componentCount' => count($componentAmounts),
                ]);
            });
        });
    }

    public function calculate(PayrollRun $run, User $actor): PayrollCalculationOutcome
    {
        $school = $run->school;

        if (! in_array($run->status, ['draft', 'calculated'], true)) {
            throw new RunNotEditableException($run->id, $run->status);
        }

        return $this->context->withSchool($school, function () use ($school, $run, $actor) {
            return DB::transaction(function () use ($school, $run, $actor) {
                // Serializes concurrent calculate() attempts on the SAME
                // run (Checkpoint 9.4 real-concurrency requirement) --
                // a different run takes a different lock. Re-check status
                // against the locked row: a concurrent approve() that
                // committed between this method's entry check and here
                // must be caught before this transaction ever attempts
                // to touch payroll_run_results (which the freeze trigger
                // would reject anyway, but this gives a clean domain
                // error first).
                $locked = PayrollRun::query()->where('id', $run->id)->lockForUpdate()->firstOrFail();
                if (! in_array($locked->status, ['draft', 'calculated'], true)) {
                    throw new RunNotEditableException($run->id, $locked->status);
                }

                // Whole-result-set replacement -- never a partial patch
                // (ADR 0034). Cascades to payroll_run_result_lines.
                PayrollRunResult::query()->where('payroll_run_id', $run->id)->delete();

                [$resolved, $unresolved] = $locked->isCorrection()
                    ? $this->calculateCorrectionRun($school, $run)
                    : $this->calculateRegularRun($school, $run, $actor);

                $transitioned = false;
                if (empty($unresolved) && ! empty($resolved)) {
                    DB::table('payroll_runs')->where('id', $run->id)->update(['status' => 'calculated']);
                    $transitioned = true;
                }

                $this->audit->school($school, 'payroll.run.calculated', actor: $actor, subject: $run, metadata: [
                    'resolvedCount' => count($resolved),
                    'unresolvedCount' => count($unresolved),
                    'transitionedToCalculated' => $transitioned,
                ]);

                event(new PayrollRunCalculated($school->id, $run->id, count($resolved), count($unresolved), $transitioned));

                return new PayrollCalculationOutcome($resolved, $unresolved, $transitioned);
            });
        });
    }

    /**
     * @return array{0: list<string>, 1: list<string>} [resolved employment record ids, unresolved]
     */
    private function calculateRegularRun(School $school, PayrollRun $run, User $actor): array
    {
        $period = $run->period;

        $employmentRecordIds = EmployeeCompensationAssignment::query()
            ->where('school_id', $school->id)
            ->where('effective_from', '<=', $period->ends_on->toDateString())
            ->where(function ($query) use ($period) {
                $query->whereNull('effective_to')->orWhere('effective_to', '>=', $period->starts_on->toDateString());
            })
            ->distinct()
            ->pluck('employment_record_id');

        $resolved = [];
        $unresolved = [];
        $persisted = [];

        foreach ($employmentRecordIds as $employmentRecordId) {
            $employmentRecord = EmploymentRecord::query()->findOrFail($employmentRecordId);

            $result = $this->resolveEmploymentRecord($run, $period, $employmentRecord);

            if ($result === null) {
                $unresolved[] = $employmentRecordId;

                continue;
            }

            $this->assertNonNegativeNet($employmentRecordId, $result);
            $persisted[$employmentRecordId] = $this->persistResult($school, $run, $employmentRecord, $result);
            $resolved[] = $employmentRecordId;
        }

        $this->captureHrxInputs($school, $run, $period, $persisted, $actor);

        return [$resolved, $unresolved];
    }

    /**
     * HRX.5 (ADR 0065 §26.8, §26.11): snapshots the HRX absence EVIDENCE beside
     * each result just persisted -- AFTER every amount is final, and never read
     * back by any calculation. HRX-L4 is open: no unit here reduces gross,
     * basic, net, EPF or EPS wages, and nothing becomes NCP. The evidence is
     * read under the HRX employment locks (captureForPayroll()), so each
     * employment is seen wholly before or after a concurrent HRX write.
     *
     * @param  array<string, PayrollRunResult>  $results  employment record id => result
     */
    private function captureHrxInputs(School $school, PayrollRun $run, PayrollPeriod $period, array $results, User $actor): void
    {
        if ($results === []) {
            return;
        }
        $evidence = $this->hrx->captureForPayroll($school, array_keys($results), $period->starts_on->toDateString(), $period->ends_on->toDateString());

        $captured = [];
        foreach ($evidence as $employmentRecordId => $e) {
            PayrollRunHrxInput::query()->create([
                'school_id' => $school->id, 'payroll_run_result_id' => $results[$employmentRecordId]->id, 'payroll_run_id' => $run->id,
                'employment_record_id' => $employmentRecordId, 'contract_version' => $e->contractVersion, 'fingerprint' => $e->fingerprint,
                'completeness' => $e->completeness, 'incomplete_reasons' => $e->incompleteReasons,
                'period_starts_on' => $e->periodStartsOn, 'period_ends_on' => $e->periodEndsOn, 'covered_from' => $e->coveredFrom, 'covered_to' => $e->coveredTo,
                'required_working_half_units' => $e->requiredWorkingHalfUnits, 'approved_paid_leave_half_units' => $e->approvedPaidLeaveHalfUnits,
                'approved_unpaid_leave_half_units' => $e->approvedUnpaidLeaveHalfUnits, 'recorded_absence_half_units' => $e->recordedAbsenceHalfUnits,
                'recorded_presence_half_units' => $e->recordedPresenceHalfUnits, 'unresolved_working_half_units' => $e->unresolvedWorkingHalfUnits,
                'evidence' => $e->halves, 'captured_by_user_id' => $actor->id, 'captured_at' => now(),
            ]);
            $captured[] = ['employmentRecordId' => $employmentRecordId, 'fingerprint' => $e->fingerprint, 'completeness' => $e->completeness] + $e->units();
        }

        $this->audit->school($school, 'payroll.hrx_input.captured', actor: $actor, subject: $run, metadata: [
            'contractVersion' => PayrollAbsenceEvidenceReader::CONTRACT_VERSION,
            'count' => count($captured),
            'incompleteCount' => count(array_filter($captured, fn (array $c) => $c['completeness'] !== PayrollAbsenceEvidence::COMPLETE)),
            'inputs' => $captured,
        ]);
    }

    /**
     * Phase 9.5 correction -- a correction run's employment records are
     * never derived from the period/assignment scan `calculateRegularRun()`
     * uses (that scan answers "who was employed this period," a
     * question a surgical, employee-by-employee correction does not
     * ask); instead, they are exactly whichever EmploymentRecords have
     * at least one `correction_delta` `payroll_adjustments` row against
     * this run (via `recordCorrectionDelta()`). A correction run
     * therefore has no "unresolved" concept the way a regular run's
     * partial-period gate does -- every named EmploymentRecord always
     * has a resolvable delta (or it would not appear here at all); an
     * empty result set simply means no deltas have been recorded yet,
     * which `calculate()`'s own `! empty($resolved)` check already
     * correctly leaves at `draft` without transitioning.
     *
     * `assertNonNegativeNet()` deliberately does NOT apply here --
     * `payroll_run_results_validate_sign` (Checkpoint 9.1) permits a
     * negative gross/deductions/net specifically for `run_kind =
     * 'correction'`; a decreased-earning or increased-deduction
     * correction genuinely produces a negative net delta by design.
     * `assertNonZeroEffect()` is this path's own equivalent fail-closed
     * gate instead.
     *
     * @return array{0: list<string>, 1: list<string>} [resolved employment record ids, unresolved]
     */
    private function calculateCorrectionRun(School $school, PayrollRun $run): array
    {
        $employmentRecordIds = PayrollAdjustment::query()
            ->where('payroll_run_id', $run->id)
            ->where('mode', 'correction_delta')
            ->distinct()
            ->pluck('employment_record_id');

        $resolved = [];

        foreach ($employmentRecordIds as $employmentRecordId) {
            $employmentRecord = EmploymentRecord::query()->findOrFail($employmentRecordId);

            $result = $this->calculateFromCorrectionDeltas($run, $employmentRecord);

            $this->assertNonZeroEffect($employmentRecordId, $run, $result);
            $this->persistResult($school, $run, $employmentRecord, $result);
            $resolved[] = $employmentRecordId;
        }

        return [$resolved, []];
    }

    /**
     * Phase 9.4 (ADR 0034 "Separation of duties") -- the sole
     * `calculated -> approved` transition, the immutability boundary
     * (no separate `finalized` state). Mirrors
     * `App\Domain\Communications\Application\Approval\CommunicationApprovalService::decide()`'s
     * exact shape: a conditional UPDATE `WHERE status = 'calculated'`
     * is the real concurrency guarantee (two concurrent approve() calls
     * race on this one row; Postgres serializes them, and exactly one
     * UPDATE affects a row) -- no separate lockForUpdate() step needed
     * first. The preparer-cannot-approve-own-run rule is checked here
     * at the Application layer (this is the first place after
     * `prepared_by_user_id` is genuinely fixed at creation) and is
     * additionally backed by the database CHECK `payroll_runs_sod_check`
     * (Checkpoint 9.1) -- both layers reject self-approval
     * independently. An approver may also post (Checkpoint 9.5) -- no
     * repository precedent requires further restriction, and no
     * emergency override exists.
     */
    public function approve(PayrollRun $run, User $approver): PayrollRun
    {
        $school = $run->school;

        if ($run->status !== 'calculated') {
            throw new InvalidRunTransitionException($run->status, 'approved');
        }

        if ($run->prepared_by_user_id === $approver->id) {
            throw new SelfApprovalNotAllowedException($run->id);
        }

        return $this->context->withSchool($school, function () use ($school, $run, $approver) {
            return DB::transaction(function () use ($school, $run, $approver) {
                $affected = PayrollRun::query()
                    ->where('id', $run->id)
                    ->where('status', 'calculated')
                    ->update([
                        'status' => 'approved',
                        'approved_by_user_id' => $approver->id,
                        'approved_at' => now(),
                    ]);

                if ($affected === 0) {
                    throw new ConcurrentRunApprovalConflictException($run->id);
                }

                $this->audit->school($school, 'payroll.run.approved', actor: $approver, subject: $run, metadata: [
                    'preparedByUserId' => $run->prepared_by_user_id,
                ]);

                event(new PayrollRunApproved($school->id, $run->id, $approver->id, $run->prepared_by_user_id));

                return $run->fresh();
            });
        });
    }

    private function resolveEmploymentRecord(PayrollRun $run, PayrollPeriod $period, EmploymentRecord $employmentRecord): ?CalculatedResult
    {
        $referenceDate = ($employmentRecord->ends_on !== null && $employmentRecord->ends_on->lt($period->ends_on))
            ? $employmentRecord->ends_on
            : $period->ends_on;

        $partialStart = $employmentRecord->starts_on->gt($period->starts_on);
        $partialEnd = $employmentRecord->ends_on !== null && $employmentRecord->ends_on->lt($period->ends_on);

        $assignmentSummary = $this->compensation->resolveEffective($employmentRecord, $referenceDate);

        $compensationChangedInPeriod = $assignmentSummary === null
            || $assignmentSummary->effectiveFrom->gt($period->starts_on)
            || ($assignmentSummary->effectiveTo !== null && $assignmentSummary->effectiveTo->lt($period->ends_on));

        $needsManualOverride = $partialStart || $partialEnd || $compensationChangedInPeriod;

        if ($needsManualOverride) {
            return $this->calculateFromManualOverride($run, $employmentRecord);
        }

        return $this->calculateFromCompensationAssignment($assignmentSummary);
    }

    /**
     * `payroll_adjustments` is append-only -- a resubmitted override
     * never deletes the prior row (see `recordManualOverride()`'s own
     * docblock). "The current value per component" is therefore
     * resolved here as the LATEST row per `salary_component_id` via
     * Postgres `DISTINCT ON`, ordered by `created_at` then `id`
     * descending (UUIDv7 ids are themselves time-ordered, the final
     * tiebreak for two rows created within the same clock tick).
     */
    private function calculateFromManualOverride(PayrollRun $run, EmploymentRecord $employmentRecord): ?CalculatedResult
    {
        $latestIds = PayrollAdjustment::query()
            ->where('payroll_run_id', $run->id)
            ->where('employment_record_id', $employmentRecord->id)
            ->where('mode', 'manual_override')
            ->selectRaw('DISTINCT ON (salary_component_id) id')
            ->orderBy('salary_component_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->pluck('id');

        if ($latestIds->isEmpty()) {
            return null;
        }

        $overrides = PayrollAdjustment::query()->whereIn('id', $latestIds)->with('component')->get();

        $lines = $overrides->map(fn (PayrollAdjustment $o) => new ManualOverrideLineInput(
            $o->salary_component_id,
            $o->amount,
            $o->component->isEarning(),
            $o->component->isEarning() ? null : $o->component->liability_ledger_account_id,
        ))->all();

        return $this->engine->calculateFromManualOverrides($lines);
    }

    /**
     * Mirrors `calculateFromManualOverride()`'s exact `DISTINCT ON`
     * latest-per-component resolution (same append-only table, same
     * reasoning) -- a correction delta may be resubmitted while the
     * run is still `draft`/`calculated`, and only the latest row per
     * component counts.
     */
    private function calculateFromCorrectionDeltas(PayrollRun $run, EmploymentRecord $employmentRecord): CalculatedResult
    {
        $latestIds = PayrollAdjustment::query()
            ->where('payroll_run_id', $run->id)
            ->where('employment_record_id', $employmentRecord->id)
            ->where('mode', 'correction_delta')
            ->selectRaw('DISTINCT ON (salary_component_id) id')
            ->orderBy('salary_component_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->pluck('id');

        $deltas = PayrollAdjustment::query()->whereIn('id', $latestIds)->with('component')->get();

        $lines = $deltas->map(fn (PayrollAdjustment $d) => new CorrectionDeltaLineInput(
            $d->salary_component_id,
            $d->amount,
            $d->effect,
            $d->component->isEarning(),
            $d->component->isEarning() ? null : $d->component->liability_ledger_account_id,
        ))->all();

        return $this->engine->calculateFromCorrectionDeltas($lines);
    }

    /**
     * Rejects a correction whose NET effect is exactly zero, even when
     * its gross/deductions are individually nonzero (e.g. an equal
     * increase to an earning and a deduction that happen to offset) --
     * a deliberate, conservative Phase 9 policy: this module has no
     * concept yet of an intentional net-neutral compensation
     * restatement, and a net-zero correction is far more likely to be
     * a data-entry mistake (a component resubmitted with the wrong
     * effect, or the same delta entered twice with opposite signs)
     * than a genuine business need. Fail closed rather than silently
     * post a correction with no take-home effect.
     */
    private function assertNonZeroEffect(string $employmentRecordId, PayrollRun $run, CalculatedResult $result): void
    {
        if (Money::of($result->netAmount, self::CURRENCY)->isZero()) {
            throw new ZeroEffectCorrectionException($employmentRecordId, $run->id);
        }
    }

    private function calculateFromCompensationAssignment(CompensationAssignmentSummary $summary): CalculatedResult
    {
        $structure = SalaryStructure::query()->with('components.component')->findOrFail($summary->salaryStructureId);

        $componentInputs = $structure->components->map(fn ($c) => new StructureComponentInput(
            $c->id,
            $c->salary_component_id,
            $c->calculation_type,
            $c->base_component_id,
            $c->rate,
            $c->component->isEarning(),
            $c->component->isEarning() ? null : $c->component->liability_ledger_account_id,
        ))->all();

        $fixedValues = CompensationAssignmentValue::query()
            ->where('assignment_id', $summary->id)
            ->pluck('amount', 'salary_structure_component_id')
            ->all();

        return $this->engine->calculateFromStructure($componentInputs, $fixedValues);
    }

    private function assertNonNegativeNet(string $employmentRecordId, CalculatedResult $result): void
    {
        if (Money::of($result->netAmount, self::CURRENCY)->isNegative()) {
            throw new NegativeNetPayException($employmentRecordId, $result->netAmount);
        }
    }

    private function persistResult(School $school, PayrollRun $run, EmploymentRecord $employmentRecord, CalculatedResult $result): PayrollRunResult
    {
        $payrollResult = PayrollRunResult::query()->create([
            'school_id' => $school->id,
            'payroll_run_id' => $run->id,
            'employment_record_id' => $employmentRecord->id,
            'employee_id' => $employmentRecord->employee_id,
            'gross_amount' => $result->grossAmount,
            'total_deductions' => $result->totalDeductions,
            'net_amount' => $result->netAmount,
        ]);

        foreach ($result->lines as $line) {
            PayrollRunResultLine::query()->create([
                'school_id' => $school->id,
                'payroll_run_result_id' => $payrollResult->id,
                'salary_component_id' => $line->salaryComponentId,
                'amount' => $line->amount,
                'effect' => $line->effect,
                'resolved_ledger_account_id' => $line->resolvedLedgerAccountId,
                'currency' => self::CURRENCY,
            ]);
        }

        return $payrollResult;
    }
}
