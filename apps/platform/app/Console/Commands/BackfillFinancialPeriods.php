<?php

namespace App\Console\Commands;

use App\Domain\Finance\Application\Periods\FinancialPeriodBackfillService;
use App\Models\School;
use Illuminate\Console\Command;

/**
 * E21.3A (ADR 0064 §3): maps journal entries posted before financial
 * periods existed to their period. Deterministic, batched and rerunnable
 * (only still-unmapped entries are touched); `--dry-run` writes nothing.
 * Ambiguous entries stay unmapped and retained. Output is counts only.
 *
 * Walks every School, suspended and closed ones included: mapping is
 * integrity maintenance of retained evidence, not a School business
 * effect, and a closed School's ledger needs its periods before any
 * retention cutover.
 */
class BackfillFinancialPeriods extends Command
{
    protected $signature = 'platform:finance-periods-backfill
        {--school= : Only this School (id)}
        {--dry-run : Report what would be mapped without writing anything}
        {--batch=500 : Entries per batch}';

    protected $description = 'Assign a financial period to journal entries posted before periods existed (ADR 0064).';

    public function handle(FinancialPeriodBackfillService $backfill): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $batch = max(1, min(5000, (int) $this->option('batch')));
        $schools = School::query()
            ->when($this->option('school'), fn ($q, $id) => $q->whereKey((string) $id))
            ->orderBy('id')
            ->get();

        if ($this->option('school') && $schools->isEmpty()) {
            $this->error('Refused: no School matches that id.');

            return self::FAILURE;
        }

        $failed = false;
        foreach ($schools as $school) {
            $result = $backfill->backfill($school, $dryRun, $batch);
            $counts = $result->counts();
            $failed = $failed || $result->errors > 0;
            $this->line(sprintf('%s school=%s mapped=%d ambiguous=%d blocked=%d error=%d remaining_unmapped=%d',
                $dryRun ? '[dry-run]' : '[applied]', $school->id, $counts['mapped'], $counts['ambiguous'], $counts['blocked'], $counts['error'], $counts['remaining_unmapped']));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
