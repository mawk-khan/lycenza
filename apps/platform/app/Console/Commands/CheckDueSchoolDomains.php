<?php

namespace App\Console\Commands;

use App\Domain\Platform\Application\Domains\SchoolDomainCheckService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ADR 0054 section 7.1 (scheduled, every minute): expires overdue pending
 * claims, then queues at most `domains.checks_per_run` due domains
 * (CheckSchoolDomainJob) -- pending ones every ~15 minutes, live ones once a
 * day, and sooner (1 h) after a failing or indeterminate observation. The
 * scheduler never does DNS or TLS work itself. Enumeration reads platform
 * data only (no TenantContext); each job re-checks its School at execution
 * time and skips a non-active School's domains without changing them.
 */
class CheckDueSchoolDomains extends Command
{
    protected $signature = 'platform:domains-check';

    protected $description = 'Run due custom-domain ownership, routing and TLS checks (ADR 0054; bounded per run).';

    public function handle(SchoolDomainCheckService $checks): int
    {
        if (! config('domains.enabled')) {
            return self::SUCCESS;
        }

        $queued = $checks->dispatchDue(max(1, (int) config('domains.checks_per_run', 50)));
        Log::info('domains.checks_queued', ['queued' => $queued]);

        return self::SUCCESS;
    }
}
