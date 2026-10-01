<?php

namespace App\Console\Commands;

use App\Support\Retention\Erasure\ErasureCaseException;
use App\Support\Retention\Erasure\ErasureCaseService;
use Illuminate\Console\Command;

/**
 * E21.2F (E21-D10): records the review decision of a requested erasure case
 * (approve, partially approve or deny) with a closed reason code. Approval
 * starts the 30-day internal target. It never overrides retention or a
 * legal hold: execution still removes only what is eligible. Operator
 * console only, confirmed, audited.
 */
class DecideErasureCase extends Command
{
    protected $signature = 'platform:erasure-case-decide
        {case : The case id}
        {--decision= : approve|partially_approve|deny}
        {--reason= : request_valid|request_valid_with_retained_categories|identity_not_verified|retention_obligation|legal_hold|not_applicable}
        {--force : Skip the confirmation}';

    protected $description = 'Record the review decision of an erasure case (E21-D10; operator only, audited).';

    public function handle(ErasureCaseService $cases): int
    {
        if (! $this->option('force') && ! $this->confirm('Record this decision on the erasure case?')) {
            $this->error('Not confirmed; nothing changed.');

            return self::FAILURE;
        }

        try {
            $case = $cases->decide((string) $this->argument('case'), (string) $this->option('decision'), (string) $this->option('reason'));
        } catch (ErasureCaseException $e) {
            $this->error('Refused: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Erasure case {$case->id}: {$case->status}".($case->target_on !== null ? "; target {$case->target_on->toDateString()}." : '.'));

        return self::SUCCESS;
    }
}
