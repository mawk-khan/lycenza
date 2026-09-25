<?php

namespace App\Console\Commands;

use App\Support\Operations\RendersCheckResults;
use App\Support\Operations\StorageInspector;
use Illuminate\Console\Command;

/**
 * Phase 0O.4A (ADR 0050 section 8): read-only operator inspection of the
 * object store (configuration, reachability, versioning, default
 * encryption, public-access block where the provider supports it). Never
 * run at start-up, never mutates a bucket. What cannot be proved here is
 * reported OPERATOR_EVIDENCE_REQUIRED for the runbook.
 */
class VerifyStorage extends Command
{
    use RendersCheckResults;

    protected $signature = 'platform:verify-storage';

    protected $description = 'Inspect the production object store (read-only, codes only).';

    public function handle(StorageInspector $inspector): int
    {
        return $this->renderResults($inspector->inspect());
    }
}
