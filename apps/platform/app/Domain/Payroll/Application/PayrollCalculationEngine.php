<?php

namespace App\Domain\Payroll\Application;

use App\Support\Money\Money;

/**
 * Phase 9.3 -- the pure, deterministic calculation kernel (ADR 0034
 * "Calculation semantics"). Deliberately holds no database connection
 * and performs no I/O -- every input arrives as a plain DTO, so this
 * class is golden-fixture testable in complete isolation. `PayrollRunService`
 * is the only caller, and owns everything this class does not: fetching
 * compensation/adjustment rows, persisting results, and rejecting a
 * negative net pay (Mandatory Decision #13 -- this engine returns
 * whatever the math produces, including a negative net, and lets the
 * caller decide what a negative result means for the run kind at hand).
 *
 * Vocabulary is deliberately fixed to exactly two calculation types
 * (`fixed_amount`, `percentage_of_base`) -- no formulas, scripts, or
 * expression languages (rule 2). Rounding is round-half-up at 2
 * decimal places, applied once per component line via
 * `Money::multiplyByRate()`, never re-rounded when lines are later
 * summed (ADR 0034 "Calculation semantics").
 *
 * Phase 9.5 correction: `summarize()` is effect-aware (`increase`
 * adds, `decrease` subtracts) for BOTH gross and deductions -- this is
 * a pure generalization, not a behavior change for `calculateFromStructure()`/
 * `calculateFromManualOverrides()`, which only ever produce `increase`
 * lines (unchanged: `signed amount == amount` when effect is always
 * `increase`). Only `calculateFromCorrectionDeltas()` can produce a
 * `decrease` line, and therefore only a correction run's aggregate
 * gross/deductions/net can be negative -- exactly what
 * `payroll_run_results_validate_sign` (Checkpoint 9.1) already
 * anticipated and permits for `run_kind = 'correction'` alone.
 */
final class PayrollCalculationEngine
{
    private const CURRENCY = 'INR';

    private const SCALE = 2;

    /**
     * @param  list<StructureComponentInput>  $components  in display_order
     * @param  array<string, string>  $fixedValuesByStructureComponentId
     */
    public function calculateFromStructure(array $components, array $fixedValuesByStructureComponentId): CalculatedResult
    {
        $resolved = [];
        $lines = [];

        foreach ($components as $component) {
            $amount = $component->calculationType === 'fixed_amount'
                ? Money::of($fixedValuesByStructureComponentId[$component->structureComponentId], self::CURRENCY)
                : $resolved[$component->baseComponentId]->multiplyByRate($component->rate, self::SCALE);

            $resolved[$component->structureComponentId] = $amount;

            $lines[] = new CalculatedResultLine(
                $component->salaryComponentId,
                $amount->amount(),
                'increase',
                $component->isEarning,
                $component->isEarning ? null : $component->resolvedLedgerAccountId,
            );
        }

        return $this->summarize($lines);
    }

    /**
     * @param  list<ManualOverrideLineInput>  $overrides
     */
    public function calculateFromManualOverrides(array $overrides): CalculatedResult
    {
        $lines = array_map(
            fn (ManualOverrideLineInput $o) => new CalculatedResultLine(
                $o->salaryComponentId,
                $o->amount,
                'increase',
                $o->isEarning,
                $o->isEarning ? null : $o->resolvedLedgerAccountId,
            ),
            $overrides,
        );

        return $this->summarize($lines);
    }

    /**
     * @param  list<CorrectionDeltaLineInput>  $deltas
     */
    public function calculateFromCorrectionDeltas(array $deltas): CalculatedResult
    {
        $lines = array_map(
            fn (CorrectionDeltaLineInput $d) => new CalculatedResultLine(
                $d->salaryComponentId,
                $d->amount,
                $d->effect,
                $d->isEarning,
                $d->isEarning ? null : $d->resolvedLedgerAccountId,
            ),
            $deltas,
        );

        return $this->summarize($lines);
    }

    /**
     * @param  list<CalculatedResultLine>  $lines
     */
    private function summarize(array $lines): CalculatedResult
    {
        // "0.00", not "0" -- every line amount already carries the
        // canonical 2dp scale (Money::of()/multiplyByRate() both
        // enforce it), and add() takes the LARGER of both operands'
        // scales, so starting from an unscaled "0" would leave the
        // total unscaled whenever a side has zero lines to add.
        $gross = Money::of('0.00', self::CURRENCY);
        $deductions = Money::of('0.00', self::CURRENCY);

        foreach ($lines as $line) {
            $amount = Money::of($line->amount, self::CURRENCY);
            $signed = $line->effect === 'increase' ? $amount : $amount->negated();

            if ($line->isEarning) {
                $gross = $gross->add($signed);
            } else {
                $deductions = $deductions->add($signed);
            }
        }

        $net = $gross->add($deductions->negated());

        return new CalculatedResult($lines, $gross->amount(), $deductions->amount(), $net->amount());
    }
}
