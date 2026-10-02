<?php

namespace App\Console\Commands;

use App\Domain\Transport\Application\Retention\DriverAssignmentRetentionService;
use App\Domain\Visitor\Application\Retention\VisitorRetentionService;
use App\Models\School;
use App\Support\Observability\MetricsRecorder;
use App\Support\Retention\AutomationExecutionRetention;
use App\Support\Retention\RetentionHolds;
use App\Support\Retention\RetentionMetrics;
use App\Support\Retention\RetentionPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * E21.3E (E21.2G O2/O3/O4, docs/security/E21-RETENTION-DETERMINATION.md,
 * project-adopted, pending legal ratification): operational module
 * residuals, a closed list, each in its owner and each with its own period
 * (no default: an unset period skips its category):
 * - ended driver (route) assignments, OPERATIONS_DRIVER_ASSIGNMENT_RETENTION_YEARS
 *   (adopted 7) after `ends_on` (Transport);
 * - checked-out visits, with the visitor once no visit remains,
 *   OPERATIONS_VISIT_RETENTION_YEARS (adopted 1) after `checked_out_at`
 *   (Visitor);
 * - completed automation executions, with their attempts and review items,
 *   OPERATIONS_AUTOMATION_RETENTION_YEARS (adopted 1) after `completed_at`.
 *
 * Inventory (balances, movements) and Canteen configuration are tenant
 * lifetime; Canteen orders follow Finance (D8). Nothing here touches them.
 * Each School in its own context, suspended and closed ones included; a
 * held School is counted only. `--dry-run` counts with the same rules.
 * Counts only in logs.
 */
class PruneOperationalRecords extends Command
{
    protected $signature = 'platform:operations-retention-prune {--dry-run : Count what would be deleted without deleting anything}';

    protected $description = 'Expires ended driver assignments (7 y), checked-out visits (1 y) and completed automation executions (1 y) (E21.2G O2-O4; nothing while unset).';

    public function handle(
        DriverAssignmentRetentionService $drivers,
        VisitorRetentionService $visitors,
        AutomationExecutionRetention $automation,
        RetentionHolds $holds,
        MetricsRecorder $metrics,
    ): int {
        try {
            $years = [
                RetentionMetrics::DRIVER_ASSIGNMENT => RetentionPeriod::years(config('retention.driver_assignment_years')),
                RetentionMetrics::VISITOR_VISIT => RetentionPeriod::years(config('retention.visit_years')),
                RetentionMetrics::AUTOMATION_EXECUTION => RetentionPeriod::years(config('retention.automation_years')),
            ];
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (array_filter($years) === []) {
            Log::info('retention.operations_prune.unconfigured');
            $this->info('Operational retention is not configured (OPERATIONS_*_RETENTION_YEARS); nothing was deleted.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $batch = max(1, (int) config('retention.batch_size'));
        $zero = ['eligible' => 0, 'deleted' => 0, 'held' => 0, 'unresolved' => 0, 'dependency_blocked' => 0, 'errors' => 0];
        $totals = array_fill_keys(array_keys(array_filter($years)), $zero);
        $services = [
            RetentionMetrics::DRIVER_ASSIGNMENT => $drivers->prune(...),
            RetentionMetrics::VISITOR_VISIT => $visitors->prune(...),
            RetentionMetrics::AUTOMATION_EXECUTION => $automation->prune(...),
        ];

        School::query()->orderBy('id')->chunk(100, function ($schools) use ($services, $years, $holds, $batch, $dryRun, &$totals): void {
            foreach ($schools as $school) {
                $held = $holds->isHeld($school->id);
                foreach (array_filter($years) as $category => $period) {
                    foreach ($services[$category]($school, RetentionPeriod::yearsBeforeNow($period), $batch, $dryRun, $held) as $outcome => $count) {
                        $totals[$category][$outcome] += $count;
                    }
                }
            }
        });

        foreach ($totals as $category => $counts) {
            RetentionMetrics::record($metrics, $category, $counts);
        }
        Log::info($dryRun ? 'retention.operations_prune.dry_run' : 'retention.operations_prune.completed', ['categories' => $totals]);

        $labels = [RetentionMetrics::DRIVER_ASSIGNMENT => 'ended driver assignments', RetentionMetrics::VISITOR_VISIT => 'visitors\' checked-out visits (units: visitors)', RetentionMetrics::AUTOMATION_EXECUTION => 'completed automation executions'];
        foreach ($totals as $category => $c) {
            $n = $dryRun ? $c['eligible'] - $c['held'] - $c['dependency_blocked'] : $c['deleted'];
            $this->info(($dryRun ? 'Dry run: would delete ' : 'Deleted ')."{$n} {$labels[$category]} (unresolved: {$c['unresolved']}, dependency-blocked: {$c['dependency_blocked']}, held: {$c['held']}, errors: {$c['errors']}).");
        }

        return self::SUCCESS;
    }
}
