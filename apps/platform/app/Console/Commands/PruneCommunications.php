<?php

namespace App\Console\Commands;

use App\Domain\Communications\Application\Retention\CommunicationResidualRetentionService;
use App\Domain\Communications\Application\Retention\CommunicationRetentionService;
use App\Models\School;
use App\Support\Observability\MetricsRecorder;
use App\Support\Retention\RetentionHolds;
use App\Support\Retention\RetentionMetrics;
use App\Support\Retention\RetentionPeriod;
use App\Support\Tenancy\SchoolTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * E21-D3 (docs/security/E21-RETENTION-DETERMINATION.md, project-adopted,
 * pending legal ratification): Communications retention.
 * - Content: COMMUNICATIONS_CONTENT_RETENTION_YEARS (adopted: 3) calendar
 *   years after the end of the Academic Year in which it was sent.
 * - Delivery telemetry: COMMUNICATIONS_DELIVERY_RETENTION_YEARS (adopted: 1)
 *   calendar year after the terminal delivery time.
 * - E21.3E residuals (E21.2G C1/C2): COMMUNICATIONS_ABANDONED_RETENTION_YEARS
 *   (adopted: 1) calendar year after a never-sent announcement was
 *   cancelled or rejected, or after an empty thread's last activity
 *   (CommunicationResidualRetentionService). Sent content stays D3's.
 *
 * The rules are in CommunicationRetentionService (the owning module).
 * - Each School is processed in its own context, suspended ones included.
 * - A held School deletes nothing; its eligible rows count as held.
 * - Each part has no default: unset deletes nothing.
 * - `--only=content|delivery|residual` limits the run; `--dry-run` counts only.
 * - Counts only in logs.
 */
class PruneCommunications extends Command
{
    protected $signature = 'platform:communications-prune
        {--only= : content, delivery or residual}
        {--dry-run : Count what would be deleted without deleting anything}';

    protected $description = 'Expires Communications content and delivery telemetry past their periods (E21-D3; nothing while unset).';

    public function handle(CommunicationRetentionService $service, CommunicationResidualRetentionService $residuals, RetentionHolds $holds, MetricsRecorder $metrics): int
    {
        $only = $this->option('only');
        if ($only !== null && ! in_array($only, ['content', 'delivery', 'residual'], true)) {
            $this->error('--only must be content, delivery or residual.');

            return self::FAILURE;
        }

        try {
            $contentYears = in_array($only, [null, 'content'], true) ? RetentionPeriod::years(config('retention.communications_content_years')) : null;
            $deliveryYears = in_array($only, [null, 'delivery'], true) ? RetentionPeriod::years(config('retention.communications_delivery_years')) : null;
            $residualYears = in_array($only, [null, 'residual'], true) ? RetentionPeriod::years(config('retention.communications_abandoned_years')) : null;
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($contentYears === null && $deliveryYears === null && $residualYears === null) {
            Log::info('retention.communications_prune.unconfigured');
            $this->info('Communications retention is not configured (COMMUNICATIONS_CONTENT_RETENTION_YEARS / COMMUNICATIONS_DELIVERY_RETENTION_YEARS / COMMUNICATIONS_ABANDONED_RETENTION_YEARS); nothing was deleted.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $batch = max(1, (int) config('retention.batch_size'));
        $content = ['eligible' => 0, 'deleted' => 0, 'held' => 0, 'unresolved' => 0, 'skipped' => 0, 'errors' => 0];
        $delivery = ['eligible' => 0, 'deleted' => 0, 'held' => 0, 'skipped' => 0, 'errors' => 0];
        $zero = ['eligible' => 0, 'deleted' => 0, 'held' => 0, 'unresolved' => 0, 'dependency_blocked' => 0, 'errors' => 0];
        $residual = [RetentionMetrics::COMMUNICATION_NEVER_SENT => $zero, RetentionMetrics::COMMUNICATION_EMPTY_THREAD => $zero];

        School::query()->orderBy('id')->chunk(100, function ($schools) use ($service, $residuals, $holds, $contentYears, $deliveryYears, $residualYears, $dryRun, $batch, &$content, &$delivery, &$residual): void {
            foreach ($schools as $school) {
                $held = $holds->isHeld($school->id);

                if ($deliveryYears !== null) {
                    $r = $service->pruneDeliveries($school, CarbonImmutable::now('UTC')->subYearsNoOverflow($deliveryYears), $batch, $dryRun || $held);
                    $this->add($delivery, $r, $held);
                }

                if ($contentYears !== null) {
                    $cutoff = CarbonImmutable::now(SchoolTimezone::resolve($school))->subYearsNoOverflow($contentYears)->toDateString();
                    $r = $service->pruneContent($school, $cutoff, $batch, $dryRun || $held);
                    $this->add($content, $r, $held);
                }

                if ($residualYears !== null) {
                    $cutoff = CarbonImmutable::now('UTC')->subYearsNoOverflow($residualYears);
                    foreach ([
                        RetentionMetrics::COMMUNICATION_NEVER_SENT => $residuals->pruneNeverSent($school, $cutoff, $batch, $dryRun, $held),
                        RetentionMetrics::COMMUNICATION_EMPTY_THREAD => $residuals->pruneEmptyThreads($school, $cutoff, $batch, $dryRun, $held),
                    ] as $category => $counts) {
                        foreach ($counts as $k => $n) {
                            $residual[$category][$k] += $n;
                        }
                    }
                }
            }
        });

        RetentionMetrics::record($metrics, RetentionMetrics::COMMUNICATION_CONTENT, $content);
        RetentionMetrics::record($metrics, RetentionMetrics::COMMUNICATION_DELIVERY, $delivery);
        foreach ($residual as $category => $counts) {
            RetentionMetrics::record($metrics, $category, $counts);
        }
        Log::info($dryRun ? 'retention.communications_prune.dry_run' : 'retention.communications_prune.completed', [
            'content_years' => $contentYears, 'delivery_years' => $deliveryYears, 'residual_years' => $residualYears, 'content' => $content, 'delivery' => $delivery, 'residual' => $residual,
        ]);

        $verb = $dryRun ? 'Dry run: would delete' : 'Deleted';
        $this->info("{$verb} {$this->n($content, $dryRun)} communication(s) (unresolved year: {$content['unresolved']}, never sent: {$content['skipped']}, held: {$content['held']}) and {$this->n($delivery, $dryRun)} delivery record(s) (no terminal time: {$delivery['skipped']}, held: {$delivery['held']}); byte delete errors: ".($content['errors'] + $delivery['errors']).'.');
        if ($residualYears !== null) {
            $a = $residual[RetentionMetrics::COMMUNICATION_NEVER_SENT];
            $t = $residual[RetentionMetrics::COMMUNICATION_EMPTY_THREAD];
            $na = $dryRun ? $a['eligible'] - $a['held'] - $a['dependency_blocked'] : $a['deleted'];
            $nt = $dryRun ? $t['eligible'] - $t['held'] - $t['dependency_blocked'] : $t['deleted'];
            $this->info("{$verb} {$na} never-sent announcement(s) (unresolved: {$a['unresolved']}, dependency-blocked: {$a['dependency_blocked']}, held: {$a['held']}, errors: {$a['errors']}) and {$nt} empty thread(s) (unresolved: {$t['unresolved']}, dependency-blocked: {$t['dependency_blocked']}, held: {$t['held']}, errors: {$t['errors']}).");
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $total
     * @param  array<string, int>  $r
     */
    private function add(array &$total, array $r, bool $held): void
    {
        foreach ($r as $k => $n) {
            $total[$k] = ($total[$k] ?? 0) + $n;
        }

        if ($held) {
            $total['held'] += $r['eligible'];
        }
    }

    /** @param  array<string, int>  $r */
    private function n(array $r, bool $dryRun): int
    {
        return $dryRun ? $r['eligible'] - $r['held'] : $r['deleted'];
    }
}
