<?php

namespace Tests\Feature\Finance;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * E21.3A2 (ADR 0064 §15): production Finance reads go through the
 * carry-forward readers, never a reconstruction from the whole history:
 * - account balances: `LedgerBalanceReader` (latest closed baseline +
 *   later lines);
 * - charge state (outstanding, allocated, adjusted, cancelled), and so
 *   Student dues, statements, payment capacity and late fees:
 *   `ChargeStateReader`.
 *
 * The all-history computations exist only as the comparison side of the
 * balance verifier: `LedgerPeriodBalances::mismatches` and
 * `AllHistoryChargeReader`.
 */
class FinanceReadCutoverGuardTest extends TestCase
{
    private function code(string $file): string
    {
        return (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($file));
    }

    /** @return list<string> */
    private function appFiles(): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    #[Test]
    public function the_all_history_charge_reader_is_verification_only(): void
    {
        $users = array_values(array_filter($this->appFiles(), fn (string $f) => str_contains($this->code($f), 'AllHistoryChargeReader')
            && ! str_ends_with($f, 'AllHistoryChargeReader.php')));

        $this->assertSame([app_path('Domain/Payments/Application/ChargePeriodStateParticipant.php')], $users);
    }

    #[Test]
    public function only_the_approved_readers_aggregate_allocations_or_journal_lines(): void
    {
        $allocationSums = [];
        $lineSums = [];
        foreach ($this->appFiles() as $file) {
            $code = $this->code($file);
            if (preg_match('/PaymentAllocation::query\(\)[^;]*(->sum\(|sum\(amount\))/s', $code) || preg_match('/payment_allocations[^;]*sum\(/is', $code)) {
                $allocationSums[] = $file;
            }
            if (preg_match('/sum\(\s*(coalesce\()?\s*(l\.)?(debit_amount|credit_amount)/i', $code)) {
                $lineSums[] = $file;
            }
        }
        sort($lineSums);

        $this->assertSame([app_path('Domain/Payments/Application/Charges/AllHistoryChargeReader.php')], $allocationSums);
        $this->assertSame([], array_values(array_diff($lineSums, [
            app_path('Domain/Finance/Application/LedgerBalanceReader.php'),
            app_path('Domain/Finance/Application/Periods/LedgerPeriodBalances.php'),
        ])));
    }

    #[Test]
    public function every_outstanding_consumer_reads_through_the_carry_forward_reader(): void
    {
        foreach ([
            'Domain/Payments/Application/ChargeOutstandingReader.php',
            'Domain/Payments/Application/SettledPaymentRecorder.php',
            'Domain/Payments/Application/ManualPaymentRecordingService.php',
            'Domain/Payments/Application/StudentFeeStatementReadService.php',
            'Domain/Payments/Application/Retention/ChargeRetentionParticipant.php',
        ] as $file) {
            $this->assertStringContainsString('ChargeStateReader', $this->code(app_path($file)), $file);
        }
        foreach (['Domain/Payments/Application/LateFeeRunService.php', 'Domain/Payments/Application/LateFeeItemExecutor.php'] as $file) {
            $this->assertStringContainsString('ChargeOutstandingReader', $this->code(app_path($file)), $file);
        }
    }
}
