<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Support\Observability\MetricsRecorder;
use App\Support\Retention\RetentionMetrics;
use App\Support\Retention\RetentionPeriod;
use App\Support\Retention\StorageOrphanReaper;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * E21-D5 (docs/security/E21-RETENTION-DETERMINATION.md, project-adopted,
 * pending legal ratification): deletes positively proven orphan objects
 * (StorageOrphanReaper) older than STORAGE_ORPHAN_RETENTION_DAYS (adopted:
 * 30).
 * - It never lists outside the managed `schools/{id}/documents` and
 *   `schools/{id}/communications` keyspaces of existing Schools.
 * - It never deletes an object any metadata row names.
 * - A held School deletes nothing.
 * - There is no default: unset deletes nothing. `--dry-run` counts only.
 *   Bounded per School by RETENTION_ORPHAN_SCAN_LIMIT objects.
 *
 * It is NOT a Documents retention command. An owned Document leaves only
 * with its owner, through the owner's domain (E21-D5).
 */
class PruneStorageOrphans extends Command
{
    protected $signature = 'platform:storage-orphans-prune
        {--dry-run : Count what would be deleted without deleting anything}';

    protected $description = 'Deletes proven orphan storage objects older than STORAGE_ORPHAN_RETENTION_DAYS (E21-D5; nothing while unset).';

    public function handle(StorageOrphanReaper $reaper, MetricsRecorder $metrics): int
    {
        try {
            $days = RetentionPeriod::days(config('retention.orphan_object_days'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($days === null) {
            Log::info('retention.storage_orphans_prune.unconfigured');
            $this->info('Orphan-object retention is not configured (STORAGE_ORPHAN_RETENTION_DAYS); nothing was deleted.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $cutoff = CarbonImmutable::now('UTC')->subDays($days);
        $limit = max(1, (int) config('retention.orphan_scan_limit'));
        $total = ['inspected' => 0, 'eligible' => 0, 'deleted' => 0, 'held' => 0, 'errors' => 0];

        School::query()->orderBy('id')->chunk(100, function ($schools) use ($reaper, $cutoff, $limit, $dryRun, &$total): void {
            foreach ($schools as $school) {
                foreach ($reaper->forSchool($school, $cutoff, $limit, $dryRun) as $k => $n) {
                    $total[$k] += $n;
                }
            }
        });

        RetentionMetrics::record($metrics, RetentionMetrics::STORAGE_ORPHAN, $total);
        Log::info($dryRun ? 'retention.storage_orphans_prune.dry_run' : 'retention.storage_orphans_prune.completed', ['retention_days' => $days] + $total);
        $count = $dryRun ? $total['eligible'] - $total['held'] : $total['deleted'];
        $this->info(($dryRun ? 'Dry run: would delete' : 'Deleted')." {$count} orphan object(s) of {$total['inspected']} inspected; held: {$total['held']}; delete errors: {$total['errors']}.");

        return self::SUCCESS;
    }
}
