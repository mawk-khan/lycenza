<?php

namespace App\Console\Commands;

use App\Domain\Finance\Application\Periods\FinanceRetentionReadiness;
use App\Models\School;
use Illuminate\Console\Command;

/**
 * E21.3A (ADR 0064 §6, §8): read-only. For each School, runs the dual-read
 * check (retained history against the latest closed baseline + later
 * detail) in one REPEATABLE READ snapshot and prints its Finance retention
 * blockers and how many periods are ready for D8 expiry (E21.3A2). Ids and codes only, never amounts. Exits non-zero on any
 * mismatch.
 *
 * Walks every School, suspended and closed ones included: it only reads.
 */
class VerifyFinanceBalances extends Command
{
    protected $signature = 'platform:finance-balances-verify
        {--school= : Only this School (id)}';

    protected $description = 'Verify carried-forward Finance balances equal the full history, and report retention blockers (read-only).';

    public function handle(FinanceRetentionReadiness $readiness): int
    {
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
            $report = $readiness->assess($school);
            $failed = $failed || $report['verification'] !== 'passed';
            $ready = count(array_filter($report['periods'], fn (array $codes) => $codes === ['ready']));
            $this->line(sprintf('school=%s verification=%s mismatches=%d closed_through=%s expired_through=%s unmapped_entries=%d ready_periods=%d blockers=%s',
                $school->id, $report['verification'], $report['mismatches'], $report['closed_through'] ?? 'none', $report['expired_through'] ?? 'none',
                $report['unmapped_entries'], $ready, implode(',', $report['blockers']) ?: 'none'));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
