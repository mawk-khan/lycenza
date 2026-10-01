<?php

namespace App\Console\Commands;

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
 *
 * The rules are in CommunicationRetentionService (the owning module).
 * - Each School is processed in its own context, suspended ones included.
 * - A held School deletes nothing; its eligible rows count as held.
 * - Each part has no default: unset deletes nothing.
 * - `--only=content|delivery` limits the run; `--dry-run` counts only.
 * - Counts only in logs.
 */
class PruneCommunications extends Command
{
    protected $signature = 'platform:communications-prune
        {--only= : content or delivery}
        {--dry-run : Count what would be deleted without deleting anything}';

    protected $description = 'Expires Communications content and delivery telemetry past their periods (E21-D3; nothing while unset).';

    public function handle(CommunicationRetentionService $service, RetentionHolds $holds, MetricsRecorder $metrics): int
    {
        $only = $this->option('only');
        if ($only !== null && ! in_array($only, ['content', 'delivery'], true)) {
            $this->error('--only must be content or delivery.');

            return self::FAILURE;
        }

        try {
            $contentYears = $only === 'delivery' ? null : RetentionPeriod::years(config('retention.communications_content_years'));
            $deliveryYears = $only === 'content' ? null : RetentionPeriod::years(config('retention.communications_delivery_years'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($contentYears === null && $deliveryYears === null) {
            Log::info('retention.communications_prune.unconfigured');
            $this->info('Communications retention is not configured (COMMUNICATIONS_CONTENT_RETENTION_YEARS / COMMUNICATIONS_DELIVERY_RETENTION_YEARS); nothing was deleted.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $batch = max(1, (int) config('retention.batch_size'));
        $content = ['eligible' => 0, 'deleted' => 0, 'held' => 0, 'unresolved' => 0, 'skipped' => 0, 'errors' => 0];
        $delivery = ['eligible' => 0, 'deleted' => 0, 'held' => 0, 'skipped' => 0, 'errors' => 0];

        School::query()->orderBy('id')->chunk(100, function ($schools) use ($service, $holds, $contentYears, $deliveryYears, $dryRun, $batch, &$content, &$delivery): void {
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
            }
        });

        RetentionMetrics::record($metrics, RetentionMetrics::COMMUNICATION_CONTENT, $content);
        RetentionMetrics::record($metrics, RetentionMetrics::COMMUNICATION_DELIVERY, $delivery);
        Log::info($dryRun ? 'retention.communications_prune.dry_run' : 'retention.communications_prune.completed', [
            'content_years' => $contentYears, 'delivery_years' => $deliveryYears, 'content' => $content, 'delivery' => $delivery,
        ]);

        $verb = $dryRun ? 'Dry run: would delete' : 'Deleted';
        $this->info("{$verb} {$this->n($content, $dryRun)} communication(s) (unresolved year: {$content['unresolved']}, never sent: {$content['skipped']}, held: {$content['held']}) and {$this->n($delivery, $dryRun)} delivery record(s) (no terminal time: {$delivery['skipped']}, held: {$delivery['held']}); byte delete errors: ".($content['errors'] + $delivery['errors']).'.');

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
