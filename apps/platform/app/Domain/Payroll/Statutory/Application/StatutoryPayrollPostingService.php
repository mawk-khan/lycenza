<?php

namespace App\Domain\Payroll\Statutory\Application;

use App\Domain\Finance\Application\Exceptions\JournalEntryAlreadyReversedException;
use App\Domain\Finance\Application\JournalLineData;
use App\Domain\Finance\Application\LedgerService;
use App\Domain\Finance\Application\PostJournalEntryData;
use App\Domain\Finance\Domain\JournalSide;
use App\Domain\Payroll\Application\PayrollAccountingConfigurationService;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Statutory\Application\Exceptions\StatutoryAlreadyPostedException;
use App\Domain\Payroll\Statutory\Application\Exceptions\StatutoryCalculationMissingException;
use App\Domain\Payroll\Statutory\Application\Exceptions\StatutoryRunNotPostedException;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollStatutoryAccountingConfiguration;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollStatutoryCalculationResult;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollStatutoryRunPosting;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Checkpoint 9.6F (ADR 0035 correction addendum §1.11) -- posts the
 * FULL statutory GL effect of a run's already-persisted
 * `payroll_statutory_calculation_results` rows as ONE additional
 * journal entry, SEPARATE from (never merged into, never replacing)
 * `App\Domain\Payroll\Application\PayrollPostingService`'s own main
 * payroll entry. Never writes `journal_entries`/`journal_lines`
 * directly -- exclusively `LedgerService::post()`/`reverseById()`,
 * exactly like `PayrollPostingService`.
 *
 * Architecture note (documented per CLAUDE.md rule 15 -- this is a
 * genuine design decision, not an ADR deviation, but recorded here
 * since it determines the shape of every line this class posts):
 * employee-side statutory withholdings (employee PF, employee ESI,
 * TDS, Professional Tax, employee LWF) are NOT modeled as
 * `salary_components` deduction lines flowing through the existing
 * Phase 9 `payroll_run_results`/`payroll_run_result_lines` mechanism
 * -- they are computed exclusively by the Checkpoint 9.6D/9.6E
 * statutory engines and recorded exclusively in
 * `payroll_statutory_calculation_results`. Their debit leg is posted
 * here against the SAME shared salary-payable ledger account the main
 * payroll entry already uses (resolved fresh via
 * `PayrollAccountingConfigurationService::resolveValidated()`, never a
 * second/duplicate payable account) -- this is what makes take-home
 * pay actually reflect statutory withholding without reopening or
 * duplicating Phase 9's own frozen posting logic. Employer-side
 * contributions (employer PF/EPS/EDLI/admin, employer ESI, employer
 * LWF) are pure additional employer cost with no employee-side
 * counterpart at all.
 *
 * Balance proof (every one of the 12 statutory figures appears
 * exactly once as a debit and once as a credit across this entry):
 *   Debits:  salary-payable-withholding(=PF-emp+ESI-emp+TDS+PT+LWF-emp)
 *            + employer-PF-total(=employer-EPF+employer-EPS) + PF-admin
 *            + EDLI + employer-ESI + employer-LWF
 *   Credits: employee-PF-payable + employer-EPS-payable +
 *            employer-EPF-payable + PF-admin-payable + EDLI-payable +
 *            ESI-payable(emp+employer) + TDS-payable + PT-payable +
 *            LWF-payable(emp+employer)
 */
class StatutoryPayrollPostingService
{
    private const CURRENCY = 'INR';

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly StatutoryAccountingConfigurationService $statutoryAccountingConfig,
        private readonly PayrollAccountingConfigurationService $payrollAccountingConfig,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    public function post(PayrollRun $run, User $actor): PayrollStatutoryRunPosting
    {
        $school = $run->school;

        return $this->context->withSchool($school, function () use ($school, $run, $actor) {
            return DB::transaction(function () use ($school, $run, $actor) {
                $locked = PayrollRun::query()->where('id', $run->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== 'posted') {
                    throw new StatutoryRunNotPostedException($run->id, $locked->status);
                }

                if (PayrollStatutoryRunPosting::query()
                    ->where('school_id', $school->id)
                    ->where('payroll_run_id', $run->id)
                    ->where('posting_kind', 'original')
                    ->exists()) {
                    throw new StatutoryAlreadyPostedException($run->id);
                }

                $results = PayrollStatutoryCalculationResult::query()
                    ->whereHas('payrollRunResult', fn ($q) => $q->where('payroll_run_id', $run->id))
                    ->get();

                if ($results->isEmpty()) {
                    throw new StatutoryCalculationMissingException($run->id);
                }

                $statutoryConfig = $this->statutoryAccountingConfig->resolveValidated($school);
                $payrollConfig = $this->payrollAccountingConfig->resolveValidated($school);

                $lines = $this->buildLines($results, $statutoryConfig, $payrollConfig->salary_payable_ledger_account_id);

                $posted = $this->ledger->post($school, new PostJournalEntryData(
                    currency: self::CURRENCY,
                    description: "Statutory payroll for run {$run->id} (period {$run->payroll_period_id})",
                    lines: $lines,
                ), $actor);

                try {
                    $posting = PayrollStatutoryRunPosting::query()->create([
                        'school_id' => $school->id,
                        'payroll_run_id' => $run->id,
                        'journal_entry_id' => $posted->journalEntryId,
                        'currency' => self::CURRENCY,
                        'posting_kind' => 'original',
                        'actor_user_id' => $actor->id,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    throw new StatutoryAlreadyPostedException($run->id);
                }

                $this->audit->school($school, 'payroll.statutory_posting.posted', actor: $actor, subject: $run, metadata: [
                    'journalEntryId' => $posted->journalEntryId,
                    'lineCount' => count($lines),
                ]);

                return $posting;
            });
        });
    }

    public function reverse(PayrollRun $run, User $actor, ?string $reason = null): PayrollStatutoryRunPosting
    {
        $school = $run->school;

        return $this->context->withSchool($school, function () use ($school, $run, $actor, $reason) {
            return DB::transaction(function () use ($school, $run, $actor, $reason) {
                $original = PayrollStatutoryRunPosting::query()
                    ->where('school_id', $school->id)
                    ->where('payroll_run_id', $run->id)
                    ->where('posting_kind', 'original')
                    ->first();

                if ($original === null || $original->reversal()->exists()) {
                    throw new StatutoryAlreadyPostedException($run->id);
                }

                try {
                    $reversalEntry = $this->ledger->reverseById($school, $original->journal_entry_id, $actor, $reason);
                } catch (JournalEntryAlreadyReversedException) {
                    throw new StatutoryAlreadyPostedException($run->id);
                }

                $reversal = PayrollStatutoryRunPosting::query()->create([
                    'school_id' => $school->id,
                    'payroll_run_id' => $run->id,
                    'journal_entry_id' => $reversalEntry->journalEntryId,
                    'currency' => self::CURRENCY,
                    'posting_kind' => 'reversal',
                    'reversal_of_posting_id' => $original->id,
                    'actor_user_id' => $actor->id,
                    'reason' => $reason,
                ]);

                $this->audit->school($school, 'payroll.statutory_posting.reversed', actor: $actor, subject: $run, metadata: [
                    'originalPostingId' => $original->id,
                    'reversalJournalEntryId' => $reversalEntry->journalEntryId,
                ]);

                return $reversal;
            });
        });
    }

    /**
     * @param  Collection<int, PayrollStatutoryCalculationResult>  $results
     * @return list<JournalLineData>
     */
    private function buildLines(Collection $results, PayrollStatutoryAccountingConfiguration $config, string $salaryPayableLedgerAccountId): array
    {
        $zero = Money::of('0.00', self::CURRENCY);

        $employeePf = $zero;
        $employerEps = $zero;
        $employerEpf = $zero;
        $pfAdmin = $zero;
        $edli = $zero;
        $employeeEsi = $zero;
        $employerEsi = $zero;
        $tds = $zero;
        $pt = $zero;
        $employeeLwf = $zero;
        $employerLwf = $zero;

        foreach ($results as $result) {
            $employeePf = $employeePf
                ->add(Money::of($result->employee_pf_mandatory ?? '0.00', self::CURRENCY))
                ->add(Money::of($result->employee_pf_voluntary ?? '0.00', self::CURRENCY));
            $employerEps = $employerEps->add(Money::of($result->employer_eps ?? '0.00', self::CURRENCY));
            $employerEpf = $employerEpf->add(Money::of($result->employer_epf ?? '0.00', self::CURRENCY));
            $pfAdmin = $pfAdmin->add(Money::of($result->pf_admin_charge ?? '0.00', self::CURRENCY));
            $edli = $edli->add(Money::of($result->pf_edli ?? '0.00', self::CURRENCY));
            $employeeEsi = $employeeEsi->add(Money::of($result->employee_esi ?? '0.00', self::CURRENCY));
            $employerEsi = $employerEsi->add(Money::of($result->employer_esi ?? '0.00', self::CURRENCY));
            $tds = $tds->add(Money::of($result->tds_monthly_deduction ?? '0.00', self::CURRENCY));
            $pt = $pt->add(Money::of($result->professional_tax ?? '0.00', self::CURRENCY));
            $employeeLwf = $employeeLwf->add(Money::of($result->employee_lwf ?? '0.00', self::CURRENCY));
            $employerLwf = $employerLwf->add(Money::of($result->employer_lwf ?? '0.00', self::CURRENCY));
        }

        $employerPfTotal = $employerEps->add($employerEpf);
        $esiPayable = $employeeEsi->add($employerEsi);
        $lwfPayable = $employeeLwf->add($employerLwf);
        $salaryPayableWithholding = $employeePf->add($employeeEsi)->add($tds)->add($pt)->add($employeeLwf);

        $lines = [];

        $debit = function (string $accountId, Money $amount) use (&$lines): void {
            if (! $amount->isZero()) {
                $lines[] = new JournalLineData($accountId, JournalSide::Debit, $amount);
            }
        };
        $credit = function (string $accountId, Money $amount) use (&$lines): void {
            if (! $amount->isZero()) {
                $lines[] = new JournalLineData($accountId, JournalSide::Credit, $amount);
            }
        };

        $debit($salaryPayableLedgerAccountId, $salaryPayableWithholding);
        $debit($config->employer_pf_contribution_expense_ledger_account_id, $employerPfTotal);
        $debit($config->pf_admin_charge_expense_ledger_account_id, $pfAdmin);
        $debit($config->edli_expense_ledger_account_id, $edli);
        $debit($config->employer_esi_contribution_expense_ledger_account_id, $employerEsi);
        $debit($config->employer_lwf_contribution_expense_ledger_account_id, $employerLwf);

        $credit($config->employee_pf_payable_ledger_account_id, $employeePf);
        $credit($config->employer_eps_payable_ledger_account_id, $employerEps);
        $credit($config->employer_epf_payable_ledger_account_id, $employerEpf);
        $credit($config->pf_admin_charge_payable_ledger_account_id, $pfAdmin);
        $credit($config->edli_payable_ledger_account_id, $edli);
        $credit($config->esi_payable_ledger_account_id, $esiPayable);
        $credit($config->tds_payable_ledger_account_id, $tds);
        $credit($config->professional_tax_payable_ledger_account_id, $pt);
        $credit($config->lwf_payable_ledger_account_id, $lwfPayable);

        return $lines;
    }
}
