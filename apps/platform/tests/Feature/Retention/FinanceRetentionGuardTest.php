<?php

namespace Tests\Feature\Retention;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * E21-D8 (docs/security/E21-RETENTION-DETERMINATION.md).
 *
 * History of this contract:
 * - E21.2E: no posted financial evidence could expire, because every
 *   balance was derived from all postings.
 * - E21.3A (ADR 0064): added the period close and carried-forward
 *   baselines, still with no deletion.
 * - E21.3A2: Finance evidence MAY now expire, but ONLY through the approved
 *   D8 architecture:
 *   - one command, `platform:finance-retention-prune`;
 *   - one Finance service (`FinanceRetentionService`, period-based units,
 *     legal holds, accounting checks);
 *   - one gateway call (`RetentionExpiry::financeUnit`);
 *   - one fixed-purpose database function
 *     (`retention_expire_finance_unit`: closed period, 8-calendar-year
 *     floor, settled, closed under references).
 *
 * - E21.3F (ADR 0064 §21 amended): Payroll's own D9 evidence (results,
 *   adjustments, LWF charges, postings, runs) MAY expire, but ONLY through
 *   the approved D9 payroll path (D9_PAYROLL_FILES): one command, one
 *   Payroll service, two gateway calls and two fixed-purpose database
 *   functions. That path names payroll evidence tables but never a Finance
 *   ledger table: it releases journal entries, Finance alone deletes them.
 *
 * No other retention, prune or Finance code may delete, or name, a ledger
 * table. Ordinary domain code never deletes a ledger row. Weakening any of
 * this needs a new ADR, not a test edit.
 */
class FinanceRetentionGuardTest extends TestCase
{
    /** The approved D8 files: they may name ledger tables to READ them, never delete. */
    private const D8_FILES = [
        'Domain/Finance/Application/Retention/FinanceRetentionEligibility.php',
        'Domain/Finance/Application/Retention/FinanceRetentionService.php',
        'Domain/Finance/Application/Retention/FinanceRetentionParticipant.php',
        'Domain/Finance/Application/Retention/FinanceRetentionUnit.php',
        'Domain/Payments/Application/Retention/ChargeRetentionParticipant.php',
        'Domain/Payroll/Application/Retention/PayrollLedgerRetentionParticipant.php',
    ];

    /**
     * E21.3F: the approved D9 payroll path. These may name payroll evidence
     * tables (TenantClosureReadiness only to read the latest posting date);
     * they may never name a Finance ledger table (FINANCE_BOUND).
     */
    private const D9_PAYROLL_FILES = [
        'Domain/Payroll/Application/Retention/PayrollEvidenceRetentionService.php',
        'Console/Commands/PrunePayrollEvidence.php',
        'Support/Retention/TenantClosureReadiness.php',
    ];

    private const FINANCE_BOUND = "/'(financial_period[a-z_]*|journal_[a-z_]+|ledger_accounts|charges|fee_[a-z_]+|payments|payment_[a-z_]+|late_fee_[a-z_]+|canteen_orders)'/";

    private const LEDGER_BOUND = "/'(financial_period[a-z_]*|journal_[a-z_]+|ledger_accounts|charges|fee_[a-z_]+|payments|payment_[a-z_]+|late_fee_[a-z_]+|payroll_run_[a-z_]+|payroll_runs|payroll_adjustments|payroll_lwf_annual_charges|payroll_statutory_[a-z_]+|canteen_orders)'/";

    private const DELETES = '/->delete\(|->forceDelete\(|->truncate\(|\bDELETE\s+FROM\b|\bTRUNCATE\b/i';

    private function code(string $file): string
    {
        return (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($file));
    }

    #[Test]
    public function no_other_retention_code_names_a_ledger_bound_table(): void
    {
        $files = array_merge(
            glob(app_path('Console/Commands/Prune*.php')) ?: [],
            glob(app_path('Support/Retention/*.php')) ?: [],
            glob(app_path('Domain/*/Application/Retention/*.php')) ?: [],
        );
        $this->assertNotEmpty($files);

        // E21.2F: the closure-readiness catalog names every tenant table, Finance
        // included, as DATA. It may not touch the database at all.
        $catalog = app_path('Support/Retention/TenantRetentionCatalog.php');
        $this->assertContains($catalog, $files);
        foreach (['DB::', '->delete(', '->update(', '->insert(', 'Schema::'] as $call) {
            $this->assertStringNotContainsString($call, (string) file_get_contents($catalog), "the catalog is a read-only map ({$call})");
        }

        $approved = array_map(fn (string $f) => app_path($f), self::D8_FILES);
        $payroll = array_map(fn (string $f) => app_path($f), self::D9_PAYROLL_FILES);
        foreach (array_diff($files, [$catalog], $approved, $payroll) as $file) {
            $this->assertDoesNotMatchRegularExpression(self::LEDGER_BOUND, $this->code($file), $file);
        }
        foreach ($payroll as $file) {
            $this->assertContains($file, $files);
            $this->assertDoesNotMatchRegularExpression(self::FINANCE_BOUND, $this->code($file), "{$file}: the D9 payroll path never names a Finance ledger table");
            $this->assertDoesNotMatchRegularExpression(self::DELETES, $this->code($file), "{$file}: deletes only through its database functions");
        }
    }

    #[Test]
    public function the_approved_d8_path_is_the_only_finance_deletion(): void
    {
        // The approved files and all Finance period code read; they never delete.
        $readers = array_merge(
            array_map(fn (string $f) => app_path($f), self::D8_FILES),
            glob(app_path('Domain/Finance/Application/Periods/*.php')) ?: [],
            glob(app_path('Domain/Payments/Application/Charges/*.php')) ?: [],
            [
                app_path('Domain/Finance/Application/LedgerBalanceReader.php'),
                app_path('Domain/Finance/Infrastructure/FinancialPeriod.php'),
                app_path('Domain/Payments/Application/ChargePeriodStateParticipant.php'),
                app_path('Console/Commands/PruneFinanceRecords.php'),
                app_path('Console/Commands/BackfillFinancialPeriods.php'),
                app_path('Console/Commands/VerifyFinanceBalances.php'),
            ],
        );
        foreach ($readers as $file) {
            $this->assertFileExists($file);
            $this->assertDoesNotMatchRegularExpression(self::DELETES, $this->code($file), $file);
        }

        // One deletion call site: the gateway, called by the Finance service only.
        $callers = [];
        exec('grep -rln --include=*.php '.escapeshellarg('financeUnit(').' '.escapeshellarg(app_path()).' 2>/dev/null', $callers);
        sort($callers);
        $this->assertSame([app_path('Domain/Finance/Application/Retention/FinanceRetentionService.php'), app_path('Support/Retention/RetentionExpiry.php')], $callers);

        // One command, scheduled, holding the period/years switch.
        $finance = array_values(array_filter(array_keys(Artisan::all()), fn (string $name) => str_contains($name, 'finance') && str_contains($name, 'prune')));
        $this->assertSame(['platform:finance-retention-prune'], $finance);
        $command = $this->code(app_path('Console/Commands/PruneFinanceRecords.php'));
        $this->assertStringContainsString('FinanceRetentionService', $command);
        $this->assertStringContainsString('->enabled()', $command);

        // Holds and the period-based eligibility live in the Finance service.
        $service = $this->code(app_path('Domain/Finance/Application/Retention/FinanceRetentionService.php'));
        $this->assertStringContainsString('->isHeld(', $service);
        $this->assertStringContainsString('->horizon(', $service);
        $this->assertStringContainsString('verifySnapshot(', $service);
        $this->assertStringContainsString("'legal_hold'", $this->code(app_path('Domain/Finance/Application/Retention/FinanceRetentionEligibility.php')));
    }

    #[Test]
    public function ordinary_domain_code_never_deletes_a_ledger_row(): void
    {
        $offenders = [];
        foreach (['Finance', 'Fees', 'Payments', 'Payroll'] as $module) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path("Domain/{$module}")));
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '/Retention/')) {
                    continue;
                }
                $code = $this->code($file->getPathname());
                if (preg_match('/(JournalEntry|JournalLine|Charge|Payment|PaymentAllocation|PaymentReceipt|FeeAdjustment|LateFeeAssessment|PayrollRunPosting)::query\(\)[^;]*->delete\(/s', $code)) {
                    $offenders[] = $file->getPathname();
                }
            }
        }
        $this->assertSame([], $offenders);
    }

    #[Test]
    public function the_database_function_enforces_the_period_floor_itself(): void
    {
        $source = (string) DB::connection('pgsql_admin')->selectOne("SELECT prosrc FROM pg_proc WHERE proname = 'retention_expire_finance_unit'")->prosrc;

        foreach (["status <> 'closed'", "interval '8 years'", 'retention_assert_tenant', 'financial_period_account_balances', 'retention_finance_dependency', 'retention_finance_open_charge', 'payroll_run_postings', 'canteen_orders'] as $needle) {
            $this->assertStringContainsString($needle, $source);
        }
        $this->assertStringNotContainsString('EXECUTE ', $source, 'no dynamic SQL');
    }
}
