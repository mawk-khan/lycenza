<?php

namespace App\Console\Commands;

use App\Support\Observability\FailedJobInspector;
use Illuminate\Console\Command;

/**
 * Phase 0C.4 section 21: an operator-facing view of `failed_jobs`
 * beyond `php artisan queue:failed`'s raw column dump -- adds queue
 * grouping and best-effort School/correlation-id recovery so an
 * operator can tell WHICH tenant/workflow a failure belongs to without
 * hand-decoding the serialized payload. Read-only; retry/delete stay
 * Laravel's own `queue:retry`/`queue:forget`.
 */
class InspectFailedJobs extends Command
{
    protected $signature = 'platform:failed-jobs {--limit=20 : Maximum recent failures to list} {--summary : Show only the per-queue summary}';

    protected $description = 'Operational visibility into failed_jobs: per-queue counts and recent failures.';

    public function handle(FailedJobInspector $inspector): int
    {
        $summary = $inspector->summaryByQueue();

        $this->table(
            ['Queue', 'Count', 'Oldest Failed At'],
            array_map(fn ($row) => [$row['queue'], $row['count'], $row['oldest_failed_at']], $summary),
        );

        if ($this->option('summary')) {
            return self::SUCCESS;
        }

        $recent = $inspector->recent((int) $this->option('limit'));

        $this->newLine();
        $this->table(
            ['UUID', 'Queue', 'Job Class', 'Failed At', 'School ID', 'Correlation ID', 'Exception'],
            array_map(fn ($row) => [
                $row['uuid'],
                $row['queue'],
                $row['job_class'],
                $row['failed_at'],
                $row['school_id'] ?? '-',
                $row['correlation_id'] ?? '-',
                $row['exception_summary'],
            ], $recent),
        );

        return self::SUCCESS;
    }
}
