<?php

namespace App\Console\Commands;

use App\Support\Retention\Erasure\ErasureCaseException;
use App\Support\Retention\Erasure\ErasureCaseService;
use App\Support\Retention\Erasure\ErasureCategory;
use Illuminate\Console\Command;

/**
 * E21.2F (E21-D10): plans (`--dry-run`) or executes an APPROVED erasure
 * case. Execution removes only the categories the domain adapters report
 * eligible, each rechecked under its domain lock. Everything retained is
 * reported with its reason and not-before date. It is safe to rerun.
 * Never scheduled: a timer never approves or executes a case. Prints
 * category codes only.
 */
class ExecuteErasureCase extends Command
{
    protected $signature = 'platform:erasure-case-execute
        {case : The case id}
        {--dry-run : Plan only; change nothing}
        {--force : Skip the confirmation}';

    protected $description = 'Plan or execute an approved erasure case (E21-D10; operator only, retention-aware, audited).';

    public function handle(ErasureCaseService $cases): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $this->option('force') && ! $this->confirm('Execute the eligible categories of this erasure case?')) {
            $this->error('Not confirmed; nothing changed.');

            return self::FAILURE;
        }

        try {
            $outcome = $cases->execute((string) $this->argument('case'), $dryRun);
        } catch (ErasureCaseException $e) {
            $this->error('Refused: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line($dryRun ? 'Plan (nothing changed):' : 'Executed; outcome:');
        $this->table(['Category', 'Outcome', 'Reason', 'Not before'], array_map(
            fn (ErasureCategory $c) => [$c->category, $c->outcome, $c->reason, $c->notBefore ?? '-'],
            $outcome,
        ));

        return self::SUCCESS;
    }
}
