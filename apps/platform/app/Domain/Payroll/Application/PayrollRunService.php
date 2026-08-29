<?php

namespace App\Domain\Payroll\Application;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\Exceptions\DuplicateRegularRunException;
use App\Domain\Payroll\Application\Exceptions\NegativeNetPayException;
use App\Domain\Payroll\Application\Exceptions\PeriodNotOpenException;
use App\Domain\Payroll\Application\Exceptions\RunNotEditableException;
use App\Domain\Payroll\Infrastructure\CompensationAssignmentValue;
use App\Domain\Payroll\Infrastructure\EmployeeCompensationAssignment;
use App\Domain\Payroll\Infrastructure\PayrollAdjustment;
use App\Domain\Payroll\Infrastructure\PayrollPeriod;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Infrastructure\PayrollRunResult;
use App\Domain\Payroll\Infrastructure\PayrollRunResultLine;
use App\Domain\Payroll\Infrastructure\SalaryStructure;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 9.3 -- creates and calculates `payroll_runs` (ADR 0032 "Run
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

                    return $run;
                });
            } catch (UniqueConstraintViolationException) {
                throw new DuplicateRegularRunException($period->id);
            }
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

                // Whole-result-set replacement -- never a partial patch
                // (ADR 0032). Cascades to payroll_run_result_lines.
                PayrollRunResult::query()->where('payroll_run_id', $run->id)->delete();

                foreach ($employmentRecordIds as $employmentRecordId) {
                    $employmentRecord = EmploymentRecord::query()->findOrFail($employmentRecordId);

                    $result = $this->resolveEmploymentRecord($run, $period, $employmentRecord);

                    if ($result === null) {
                        $unresolved[] = $employmentRecordId;

                        continue;
                    }

                    $this->assertNonNegativeNet($employmentRecordId, $result);
                    $this->persistResult($school, $run, $employmentRecord, $result);
                    $resolved[] = $employmentRecordId;
                }

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

                return new PayrollCalculationOutcome($resolved, $unresolved, $transitioned);
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

    private function persistResult(School $school, PayrollRun $run, EmploymentRecord $employmentRecord, CalculatedResult $result): void
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
    }
}
