<?php

namespace App\Console\Commands;

use App\Support\Operations\RendersCheckResults;
use App\Support\Operations\RestoreValidator;
use Illuminate\Console\Command;

/**
 * Phase 0O.4A (ADR 0050 section 11): validates an ALREADY RESTORED,
 * isolated environment for a restore drill -- it restores nothing and
 * writes nothing. Prints codes, counts, the observed recovery point and
 * the validation duration for the drill record
 * (docs/operations/RESTORE-DRILL-RECORD.md). Never run against production
 * as a "restore".
 */
class VerifyRestore extends Command
{
    use RendersCheckResults;

    protected $signature = 'platform:verify-restore {--sample-documents=25 : How many Document objects to check for presence and size (0 = skip)}';

    protected $description = 'Validate a restored isolated environment (read-only, codes only).';

    public function handle(RestoreValidator $validator): int
    {
        $started = microtime(true);
        $results = $validator->validate(max(0, (int) $this->option('sample-documents')));
        $status = $this->renderResults($results);
        $this->line(sprintf('validation_duration_seconds=%.1f', microtime(true) - $started));

        return $status;
    }
}
