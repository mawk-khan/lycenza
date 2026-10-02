<?php

namespace App\Console\Commands;

use App\Domain\Payroll\Application\Retention\PayrollEvidenceRetentionService;
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
 * E21.3F (E21-D9 x E21-D8, docs/security/E21-RETENTION-DETERMINATION.md,
 * project-adopted, pending legal ratification): posted payroll evidence,
 * EMPLOYEE_EVIDENCE_RETENTION_YEARS (the one D9 setting, adopted 8; at
 * least 8, or nothing is deleted) after the Employee's final separation.
 *
 * Two phases per School, in Payroll (PayrollEvidenceRetentionService):
 * 1. EVIDENCE, one Employee per transaction: results with their lines and
 *    statutory results, adjustments, LWF charges;
 * 2. RUNS, one regular run with its corrections per transaction: a group
 *    the evidence phase has emptied loses its postings and runs, which
 *    releases its journal entries to Finance's own D8 expiry.
 * It never deletes a journal entry, a Payroll configuration row or an
 * Employee: `employee-retention-prune` and `finance-retention-prune`
 * re-evaluate what this released on their next run, so correctness never
 * depends on the schedule order.
 *
 * - Each School in its own context, suspended and closed ones included; a
 *   held School is counted only.
 * - No default: an unset period deletes nothing; a period under 8 years
 *   fails.
 * - `--dry-run` counts with the database's own verdict, phase by phase
 *   against the current state (a run the evidence phase would empty is
 *   counted once its evidence is gone).
 * - Counts only in logs: never identifiers, pay or personal details.
 */
class PrunePayrollEvidence extends Command
{
    /** The adopted D9 payroll evidence period, also the database floor. */
    public const MINIMUM_YEARS = 8;

    protected $signature = 'platform:payroll-retention-prune {--dry-run : Count what would be deleted without deleting anything}';

    protected $description = 'Expires posted payroll evidence 8 y after final separation and releases emptied payroll runs to Finance (E21-D9; nothing while unset).';

    public function handle(PayrollEvidenceRetentionService $payroll, RetentionHolds $holds, MetricsRecorder $metrics): int
    {
        try {
            $years = RetentionPeriod::years(config('retention.employee_evidence_years'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($years === null) {
            Log::info('retention.payroll_prune.unconfigured');
            $this->info('Payroll evidence retention is not configured (EMPLOYEE_EVIDENCE_RETENTION_YEARS); nothing was deleted.');

            return self::SUCCESS;
        }
        if ($years < self::MINIMUM_YEARS) {
            $this->error('EMPLOYEE_EVIDENCE_RETENTION_YEARS must be at least '.self::MINIMUM_YEARS.' for payroll evidence; nothing was deleted.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $batch = max(1, (int) config('retention.batch_size'));
        $zero = ['eligible' => 0, 'deleted' => 0, 'held' => 0, 'unresolved' => 0, 'dependency_blocked' => 0, 'errors' => 0];
        $totals = array_fill_keys([RetentionMetrics::PAYROLL_EVIDENCE, RetentionMetrics::PAYROLL_RUN], $zero);

        School::query()->orderBy('id')->chunk(100, function ($schools) use ($payroll, $holds, $years, $dryRun, $batch, &$totals): void {
            foreach ($schools as $school) {
                $held = $holds->isHeld($school->id);
                $count = $dryRun || $held;
                // One School-local calendar cutoff for both phases; runs and postings are
                // timestamps, compared against the start of that local day.
                $cutoff = CarbonImmutable::now(SchoolTimezone::resolve($school))->subYearsNoOverflow($years)->startOfDay();

                $this->add($totals[RetentionMetrics::PAYROLL_EVIDENCE], $payroll->pruneEvidence($school, $cutoff->toDateString(), $batch, $count), $held);
                $this->add($totals[RetentionMetrics::PAYROLL_RUN], $payroll->pruneRuns($school, $cutoff, $batch, $count), $held);
            }
        });

        foreach ($totals as $category => $counts) {
            RetentionMetrics::record($metrics, $category, $counts);
        }
        Log::info($dryRun ? 'retention.payroll_prune.dry_run' : 'retention.payroll_prune.completed', ['years' => $years, 'categories' => $totals]);

        $e = $totals[RetentionMetrics::PAYROLL_EVIDENCE];
        $r = $totals[RetentionMetrics::PAYROLL_RUN];
        $verb = $dryRun ? 'Dry run: would delete' : 'Deleted';
        $this->info("{$verb} the payroll evidence of {$this->n($e, $dryRun)} Employee(s) (unresolved separation: {$e['unresolved']}, dependency-blocked: {$e['dependency_blocked']}, held: {$e['held']}, errors: {$e['errors']}).");
        $this->info("{$verb} {$this->n($r, $dryRun)} emptied payroll run(s), releasing their journal entries to Finance (dependency-blocked: {$r['dependency_blocked']}, held: {$r['held']}, errors: {$r['errors']}).");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $total
     * @param  array<string, int>  $r
     */
    private function add(array &$total, array $r, bool $held): void
    {
        foreach ($r as $k => $n) {
            $total[$k] += $n;
        }

        if ($held) {
            // A held School is counted only; none of its units is reported as blocked or deletable.
            $total['held'] += $r['eligible'];
            $total['dependency_blocked'] -= $r['dependency_blocked'];
        }
    }

    /** @param  array<string, int>  $r */
    private function n(array $r, bool $dryRun): int
    {
        return $dryRun ? $r['eligible'] - $r['held'] - $r['dependency_blocked'] : $r['deleted'];
    }
}
