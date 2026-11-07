<?php

namespace Tests\Feature\Payroll\Concerns;

use App\Domain\Payroll\Application\CorrectionDeltaInput;
use App\Domain\Payroll\Application\PayrollPeriodService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Infrastructure\PayrollPeriod;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** HR.R1-C8 (PAY-17): a posted run and a correction of it, through the real services, every date in 2015. */
trait BuildsPayrollCorrections
{
    protected function postedTarget(string $month = '2015-08-01'): PayrollRun
    {
        $run = $this->calculatedRun($month);
        $this->approve($run, $this->checker);
        $this->postRun($run);

        return $this->inSchool($this->school, fn () => $run->fresh());
    }

    protected function openPeriod(string $month): PayrollPeriod
    {
        return $this->inSchool($this->school, function () use ($month): PayrollPeriod {
            $periods = app(PayrollPeriodService::class);

            return $periods->open($periods->createPeriod($this->school, Carbon::parse($month), null, $this->preparer), $this->preparer);
        });
    }

    protected function draftCorrection(PayrollRun $target, string $month = '2015-09-01'): PayrollRun
    {
        return $this->inSchool($this->school, fn () => app(PayrollRunService::class)->createCorrectionRun($target->fresh(), $this->openPeriod($month), $this->preparer)->fresh());
    }

    protected function calculatedCorrection(PayrollRun $target, string $month = '2015-09-01'): PayrollRun
    {
        $correction = $this->draftCorrection($target, $month);

        return $this->inSchool($this->school, function () use ($correction): PayrollRun {
            $runs = app(PayrollRunService::class);
            $component = DB::table('salary_structure_components')->where('id', $this->setup['line'])->value('salary_component_id');
            $runs->recordCorrectionDelta($correction, $this->record->fresh(), [new CorrectionDeltaInput($component, '500.00', 'increase')], 'underpaid', $this->preparer);
            $runs->calculate($correction, $this->preparer);

            return $correction->fresh();
        });
    }

    /** @return array<string, string> posting kind => total debit, across these runs */
    protected function ledger(PayrollRun ...$runs): array
    {
        return $this->inSchool($this->school, fn () => DB::table('payroll_run_postings as p')->join('journal_lines as l', 'l.journal_entry_id', '=', 'p.journal_entry_id')
            ->whereIn('p.payroll_run_id', array_map(fn (PayrollRun $r) => $r->id, $runs))
            ->selectRaw('p.posting_kind, sum(l.debit_amount) as total')->groupBy('p.posting_kind')->orderBy('p.posting_kind')->pluck('total', 'posting_kind')->all());
    }

    protected function postings(PayrollRun $run): int
    {
        return $this->inSchool($this->school, fn () => DB::table('payroll_run_postings')->where('payroll_run_id', $run->id)->count());
    }
}
