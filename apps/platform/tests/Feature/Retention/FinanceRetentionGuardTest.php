<?php

namespace Tests\Feature\Retention;

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * E21.2E (E21-D8, docs/security/E21-RETENTION-DETERMINATION.md): no posted
 * financial evidence expires yet. Every balance is derived from all postings
 * and allocations (FINANCE.md, "Balance derivation"), and there is no
 * accounting-period close or carried-forward opening balance. Deleting old
 * evidence would therefore silently change current account balances and
 * dues. Until a financial-year close design exists, no retention or prune
 * code may name a Finance, Fees, Payments or payroll-ledger table, so none
 * can delete one.
 *
 * E21.3A (ADR 0064) adds the financial-period close and carried-forward
 * baselines: the FOUNDATION for a later cutover, not the cutover. Deleting
 * historical Finance evidence is still forbidden: the period code deletes
 * nothing, the period and baseline tables are ledger-bound too, and no
 * finance retention prune exists. Relaxing this needs E21.3A2 and
 * ratification.
 */
class FinanceRetentionGuardTest extends TestCase
{
    #[Test]
    public function no_retention_code_names_a_ledger_bound_table(): void
    {
        $files = array_merge(
            glob(app_path('Console/Commands/Prune*.php')) ?: [],
            glob(app_path('Support/Retention/*.php')) ?: [],
            glob(app_path('Domain/*/Application/Retention/*.php')) ?: [],
        );
        $this->assertNotEmpty($files);

        $ledgerBound = "/'(financial_period[a-z_]*|journal_[a-z_]+|ledger_accounts|charges|fee_[a-z_]+|payments|payment_[a-z_]+|late_fee_[a-z_]+|payroll_run_[a-z_]+|payroll_runs|payroll_adjustments|payroll_lwf_annual_charges|payroll_statutory_[a-z_]+|canteen_orders)'/";

        // E21.2F: the closure-readiness catalog names every tenant table, Finance
        // included, as DATA. It is the one exception, and it may not touch the
        // database at all.
        $catalog = app_path('Support/Retention/TenantRetentionCatalog.php');
        $this->assertContains($catalog, $files);
        $catalogCode = (string) file_get_contents($catalog);
        foreach (['DB::', '->delete(', '->update(', '->insert(', 'Schema::'] as $call) {
            $this->assertStringNotContainsString($call, $catalogCode, "the catalog is a read-only map ({$call})");
        }

        foreach (array_diff($files, [$catalog]) as $file) {
            $code = (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($file));
            $this->assertDoesNotMatchRegularExpression($ledgerBound, $code, $file);
        }
    }

    #[Test]
    public function the_financial_period_foundation_deletes_nothing_and_no_finance_prune_exists(): void
    {
        $files = array_merge(
            glob(app_path('Domain/Finance/Application/Periods/*.php')) ?: [],
            [
                app_path('Domain/Finance/Infrastructure/FinancialPeriod.php'),
                app_path('Domain/Payments/Application/ChargePeriodStateParticipant.php'),
                app_path('Domain/Payments/Infrastructure/FinancialPeriodChargeState.php'),
                app_path('Console/Commands/BackfillFinancialPeriods.php'),
                app_path('Console/Commands/VerifyFinanceBalances.php'),
                app_path('Http/Controllers/App/Finance/FinancialPeriodController.php'),
            ],
        );
        foreach ($files as $file) {
            $code = (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($file));
            $this->assertDoesNotMatchRegularExpression('/->delete\(|->forceDelete\(|->truncate\(|\bDELETE\s+FROM\b|\bTRUNCATE\b|retention_expire_/i', $code, $file);
        }

        foreach (glob(app_path('Console/Commands/*.php')) ?: [] as $command) {
            $this->assertDoesNotMatchRegularExpression("/'(platform|finance):finance-retention-prune|finance-[a-z-]*prune/", (string) file_get_contents($command), $command);
        }
        $this->assertSame([], array_values(array_filter(array_keys(Artisan::all()), fn (string $name) => str_contains($name, 'finance') && str_contains($name, 'prune'))));
    }
}
