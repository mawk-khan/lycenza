<?php

namespace Tests\Feature\Retention;

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

        $ledgerBound = "/'(journal_[a-z_]+|ledger_accounts|charges|fee_[a-z_]+|payments|payment_[a-z_]+|late_fee_[a-z_]+|payroll_run_[a-z_]+|payroll_runs|payroll_adjustments|payroll_lwf_annual_charges|payroll_statutory_[a-z_]+|canteen_orders)'/";

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
}
