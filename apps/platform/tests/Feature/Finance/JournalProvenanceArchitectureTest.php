<?php

namespace Tests\Feature\Finance;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * ORG.8-P1 (ADR 0076 §57): every production writer of journal entries is
 * declared with the provenance it stores, so the one database
 * classification (`journal_entry_subledger_owner()`) can never silently
 * miss a new source. A writer that posts for a domain must record the
 * entry on an owner column the predicate names; only the generic ledger
 * (and the local demo seeder) writes ledger-native entries; only the owning
 * domains call `reverseById()`; and the generic reversal goes through
 * `LedgerReversalPolicy`. A new caller fails here until it is classified.
 */
class JournalProvenanceArchitectureTest extends TestCase
{
    /** file => [method => provenance]; provenance is an owner column, or 'ledger-native'. */
    private const WRITERS = [
        'app/Domain/Fees/Application/ChargeService.php' => ['post' => 'charges.journal_entry_id', 'reverseById' => 'charges.cancellation_journal_entry_id'],
        'app/Domain/Fees/Application/FeeAdjustmentService.php' => ['post' => 'fee_adjustments.journal_entry_id', 'reverseById' => 'fee_adjustments.cancellation_journal_entry_id'],
        'app/Domain/Finance/Application/LedgerAdministrationService.php' => ['post' => 'ledger-native', 'reverse' => 'ledger-native'],
        'app/Domain/Payments/Application/PaymentCorrectionService.php' => ['post' => 'payment_corrections.journal_entry_id'],
        'app/Domain/Payments/Application/SettledPaymentRecorder.php' => ['post' => 'payments.journal_entry_id'],
        'app/Domain/Payroll/Application/PayrollPostingService.php' => ['post' => 'payroll_run_postings.journal_entry_id', 'reverseById' => 'payroll_run_postings.journal_entry_id'],
        'app/Domain/Payroll/Statutory/Application/StatutoryPayrollPostingService.php' => ['post' => 'payroll_statutory_run_postings.journal_entry_id', 'reverseById' => 'payroll_statutory_run_postings.journal_entry_id'],
        'database/seeders/Demo/DemoModuleData.php' => ['post' => 'ledger-native'],
    ];

    /** @return array<string, list<string>> file => ledger mutation methods it calls */
    private function ledgerCallers(): array
    {
        $found = [];
        $root = base_path();
        foreach (Finder::create()->files()->name('*.php')->in([$root.'/app', $root.'/database']) as $file) {
            $source = $file->getContents();
            if (! preg_match('/\bLedgerService\b/', $source)) {
                continue;
            }
            // Every LedgerService is held as `$ledger` (property or local), so its calls are `ledger->method(`.
            preg_match_all('/LedgerService \$(\w+)/', $source, $names);
            foreach ($names[1] as $name) {
                $this->assertSame('ledger', $name, "{$file->getRelativePathname()}: hold LedgerService as \$ledger so its writes stay inventoried");
            }
            $this->assertDoesNotMatchRegularExpression('/LedgerService::class\)\s*->/', $source, "{$file->getRelativePathname()}: resolve LedgerService into \$ledger, never call it inline");
            preg_match_all('/\bledger->(post|reverse|reverseById)\(/', $source, $calls);
            if ($calls[1] !== []) {
                $relative = substr($file->getPathname(), strlen($root) + 1);
                $found[$relative] = array_values(array_unique($calls[1]));
                sort($found[$relative]);
            }
        }
        ksort($found);

        return $found;
    }

    #[Test]
    public function every_ledger_writer_is_declared_with_its_provenance(): void
    {
        $declared = array_map(function (array $methods): array {
            $names = array_keys($methods);
            sort($names);

            return $names;
        }, self::WRITERS);
        ksort($declared);

        $this->assertSame($declared, $this->ledgerCallers(), 'a new journal-entry writer must declare its provenance here (and, for a domain, an owner column the database classifies)');
    }

    #[Test]
    public function every_declared_owner_column_is_named_by_the_database_classification(): void
    {
        $predicate = (string) DB::selectOne("select pg_get_functiondef('public.journal_entry_subledger_owner(uuid, uuid)'::regprocedure) as d")->d;

        foreach (self::WRITERS as $file => $methods) {
            foreach ($methods as $provenance) {
                if ($provenance === 'ledger-native') {
                    continue;
                }
                [$table, $column] = explode('.', $provenance);
                $this->assertStringContainsString("public.{$table} o WHERE o.school_id = p_school AND o.{$column} = p_entry", $predicate, "{$file}: {$provenance}");
            }
        }
    }

    #[Test]
    public function the_generic_reversal_is_the_only_caller_of_reverse_and_it_applies_the_policy(): void
    {
        $generic = (string) file_get_contents(app_path('Domain/Finance/Application/LedgerAdministrationService.php'));
        $this->assertMatchesRegularExpression('/reversalPolicy->assertGenericallyReversible\(\$original\).*?ledger->reverse\(\$original/s', $generic, 'the policy runs before the reversal');

        $owners = array_keys(array_filter(self::WRITERS, fn (array $m) => isset($m['reverseById'])));
        foreach ($owners as $file) {
            $this->assertStringNotContainsString('ledger->reverse(', (string) file_get_contents(base_path($file)), "{$file} reverses only its own entry, by id");
        }
    }
}
