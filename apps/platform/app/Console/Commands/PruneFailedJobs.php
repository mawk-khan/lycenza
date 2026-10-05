<?php

namespace App\Console\Commands;

use App\Support\Retention\RetentionExpiry;
use App\Support\Retention\RetentionHolds;
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
 * Failed jobs carry no School column, so RETENTION_HOLD_PLATFORM holds them
 * (E21.2B). Counts only in logs; `--dry-run` only counts.
 */
class PruneFailedJobs extends Command
{
    protected $signature = 'platform:failed-jobs-prune
        {--dry-run : Count what would be pruned without deleting anything}';

    protected $description = 'Deletes failed_jobs rows older than FAILED_JOBS_RETENTION_DAYS after their failure (E21-D13; nothing while unset).';

    public function handle(FailedJobProviderInterface $failer, RetentionHolds $holds, RetentionExpiry $retention): int
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

        // Failed jobs belong to no School: RETENTION_HOLD_PLATFORM holds them (E21.2B).
        if ($holds->platformHeld()) {
            Log::info('retention.failed_jobs_prune.held');
            $this->info('Platform records are on retention hold (RETENTION_HOLD_PLATFORM); nothing was deleted.');

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

        // E21-RH.6: the delete runs as the retention identity (its delete guard re-checks the platform hold in
        // the database), in bounded batches, as the provider's own prune does.
        /** @var array{deleted: int, errors: int} $result */
        $result = $retention->retained('failed_job', false, null, ['deleted' => 0, 'errors' => 0], function () use ($cutoff): array {
            $deleted = 0;
            do {
                $batch = DB::table((string) config('queue.failed.table'))->where('failed_at', '<', $cutoff)->limit(1000)->delete();
                $deleted += $batch;
            } while ($batch > 0);

            return ['deleted' => $deleted, 'errors' => 0];
        });
        if ($result['errors'] > 0) {
            $this->error('The retention identity is not available; nothing was deleted.');

            return self::FAILURE;
        }
        $deleted = $result['deleted'];

        Log::info('retention.failed_jobs_prune.completed', ['retention_days' => $days, 'failed_jobs' => $deleted]);
        $this->info("Deleted {$deleted} failed job(s).");

        return self::SUCCESS;
    }
}
