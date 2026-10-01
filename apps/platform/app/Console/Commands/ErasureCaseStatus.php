<?php

namespace App\Console\Commands;

use App\Models\ErasureCase;
use App\Support\Retention\Erasure\ErasureCaseService;
use Illuminate\Console\Command;

/**
 * E21.2F (E21-D10): READ-ONLY erasure case visibility: counts by status
 * and the approved cases past their 30-day target. Overdue is operational
 * visibility only; nothing is approved or executed automatically. Prints
 * case ids, codes and dates only.
 */
class ErasureCaseStatus extends Command
{
    protected $signature = 'platform:erasure-case-status';

    protected $description = 'Show erasure cases by status and the overdue ones (E21-D10; read-only).';

    public function handle(ErasureCaseService $cases): int
    {
        $counts = ErasureCase::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $this->line('Cases by status: '.($counts->isEmpty() ? 'none' : $counts->map(fn ($n, $s) => "{$s} {$n}")->implode(', ')));
        $this->line("Overdue (approved, past the 30-day target): {$cases->overdueCount()}");

        ErasureCase::query()->whereIn('status', ['approved', 'partially_approved', 'executing'])
            ->where('target_on', '<', now()->toDateString())->orderBy('target_on')->limit(50)
            ->get(['id', 'status', 'target_on'])
            ->each(fn (ErasureCase $c) => $this->line("  {$c->id} {$c->status} target {$c->target_on?->toDateString()}"));

        return self::SUCCESS;
    }
}
