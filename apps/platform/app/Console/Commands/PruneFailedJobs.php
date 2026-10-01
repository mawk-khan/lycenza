<?php

namespace App\Console\Commands;

use App\Support\Retention\RetentionPeriod;
use Illuminate\Console\Command;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Failed\PrunableFailedJobProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * E21-D13 (docs/security/E21-RETENTION-DETERMINATION.md, project-adopted,
 * pending legal ratification): `failed_jobs` rows carry the job's full
 * payload and exception, which may include personal data. They are deleted
 * FAILED_JOBS_RETENTION_DAYS (adopted: 30) after their terminal failure
 * (`failed_at`).
 *
 * There is no default: unset deletes nothing. Retrying, forgetting and
 * inspecting a failed job stay the operator's `platform:failed-jobs` / queue
 * commands. This only bounds how long an unhandled failure is kept.
 * Failed jobs carry no School column, so the School hold seam does not
 * apply. Counts only in logs; `--dry-run` only counts.
 */
class PruneFailedJobs extends Command
{
    protected $signature = 'platform:failed-jobs-prune
        {--dry-run : Count what would be pruned without deleting anything}';

    protected $description = 'Deletes failed_jobs rows older than FAILED_JOBS_RETENTION_DAYS after their failure (E21-D13; nothing while unset).';

    public function handle(FailedJobProviderInterface $failer): int
    {
        try {
            $days = RetentionPeriod::days(config('retention.failed_jobs_days'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($days === null) {
            Log::info('retention.failed_jobs_prune.unconfigured');
            $this->info('Failed-job retention is not configured (FAILED_JOBS_RETENTION_DAYS); nothing was deleted.');

            return self::SUCCESS;
        }

        if (! $failer instanceof PrunableFailedJobProvider) {
            $this->error('The configured failed-job store cannot be pruned.');

            return self::FAILURE;
        }

        $cutoff = Carbon::now()->subDays($days);

        if ($this->option('dry-run')) {
            $count = DB::connection(config('queue.failed.database'))->table(config('queue.failed.table'))->where('failed_at', '<', $cutoff)->count();
            Log::info('retention.failed_jobs_prune.dry_run', ['retention_days' => $days, 'failed_jobs' => $count]);
            $this->info("Dry run: would delete {$count} failed job(s).");

            return self::SUCCESS;
        }

        $deleted = $failer->prune($cutoff);

        Log::info('retention.failed_jobs_prune.completed', ['retention_days' => $days, 'failed_jobs' => $deleted]);
        $this->info("Deleted {$deleted} failed job(s).");

        return self::SUCCESS;
    }
}
