<?php

namespace App\Console\Commands;

use App\Domain\HR\Application\Retention\EmployeeRecordRetentionService;
use App\Domain\Payroll\Application\Retention\PayrollEmployeeRetentionService;
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
 * E21-D9 (docs/security/E21-RETENTION-DETERMINATION.md, project-adopted,
 * pending legal ratification): HR and payroll retention. Every period runs
 * from the Employee's final separation (EmployeeRetentionEligibility); an
 * unresolved separation keeps everything.
 *
 * Two separate phases, each in the owning module:
 * - ANCILLARY, EMPLOYEE_ANCILLARY_RETENTION_YEARS (adopted: 2): addresses,
 *   emergency contacts, notes, qualifications, experience and
 *   certifications (HR);
 * - EVIDENCE, EMPLOYEE_EVIDENCE_RETENTION_YEARS (adopted: 8): Payroll's
 *   compensation and statutory rows (Payroll), then the Employee with its
 *   employment records, assignments, personal details and Documents (HR).
 *   It runs only when no other retained row references the Employee.
 *   Posted payroll results are not deleted here: they have their own D9
 *   expiry (`platform:payroll-retention-prune`, E21.3F), and this command
 *   re-evaluates an Employee once that has released it.
 *
 * Ancillary runs first.
 * - Each School is processed in its own context, suspended ones included.
 *   A held School only counts.
 * - No default: an unset period deletes nothing.
 * - `--only=ancillary|evidence` limits the run; `--dry-run` counts only.
 * - Counts only in logs: Employees, never identifiers, pay or personal
 *   details.
 */
class PruneEmployeeRecords extends Command
{
    protected $signature = 'platform:employee-retention-prune
        {--only= : ancillary or evidence}
        {--dry-run : Count what would be deleted without deleting anything}';

    protected $description = 'Expires ancillary HR details (2 y) and employment/payroll evidence (8 y) after final separation (E21-D9; nothing while unset).';

    public function handle(
        EmployeeRecordRetentionService $records,
        PayrollEmployeeRetentionService $payroll,
        RetentionHolds $holds,
        MetricsRecorder $metrics,
    ): int {
        $only = $this->option('only');
        if ($only !== null && ! in_array($only, ['ancillary', 'evidence'], true)) {
            $this->error('--only must be ancillary or evidence.');

            return self::FAILURE;
        }

        try {
            $configuredAncillary = RetentionPeriod::years(config('retention.employee_ancillary_years'));
            $configuredEvidence = RetentionPeriod::years(config('retention.employee_evidence_years'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($configuredAncillary !== null && $configuredEvidence !== null && $configuredEvidence < $configuredAncillary) {
            $this->error('EMPLOYEE_EVIDENCE_RETENTION_YEARS must not be shorter than EMPLOYEE_ANCILLARY_RETENTION_YEARS.');

            return self::FAILURE;
        }

        $ancillaryYears = $only === 'evidence' ? null : $configuredAncillary;
        $evidenceYears = $only === 'ancillary' ? null : $configuredEvidence;

        if ($ancillaryYears === null && $evidenceYears === null) {
            Log::info('retention.employee_prune.unconfigured');
            $this->info('Employee retention is not configured (EMPLOYEE_ANCILLARY_RETENTION_YEARS / EMPLOYEE_EVIDENCE_RETENTION_YEARS); nothing was deleted.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $batch = max(1, (int) config('retention.batch_size'));
        $zero = ['eligible' => 0, 'deleted' => 0, 'held' => 0, 'unresolved' => 0, 'dependency_blocked' => 0, 'errors' => 0];
        $totals = array_fill_keys([RetentionMetrics::EMPLOYEE_ANCILLARY, RetentionMetrics::PAYROLL_EMPLOYEE_RECORD, RetentionMetrics::EMPLOYEE_EVIDENCE], $zero);

        School::query()->orderBy('id')->chunk(100, function ($schools) use ($records, $payroll, $holds, $ancillaryYears, $evidenceYears, $dryRun, $batch, &$totals): void {
            foreach ($schools as $school) {
                $held = $holds->isHeld($school->id);
                $today = CarbonImmutable::now(SchoolTimezone::resolve($school));
                $count = $dryRun || $held;

                if ($ancillaryYears !== null) {
                    $cutoff = $today->subYearsNoOverflow($ancillaryYears)->toDateString();
                    $this->add($totals[RetentionMetrics::EMPLOYEE_ANCILLARY], $records->pruneAncillary($school, $cutoff, $batch, $count), $held);
                }

                if ($evidenceYears !== null) {
                    $cutoff = $today->subYearsNoOverflow($evidenceYears)->toDateString();
                    $this->add($totals[RetentionMetrics::PAYROLL_EMPLOYEE_RECORD], $payroll->prune($school, $cutoff, $batch, $count), $held);
                    $this->add($totals[RetentionMetrics::EMPLOYEE_EVIDENCE], $records->pruneEvidence($school, $cutoff, $batch, $count, PayrollEmployeeRetentionService::TABLES), $held);
                }
            }
        });

        foreach ($totals as $category => $counts) {
            RetentionMetrics::record($metrics, $category, $counts);
        }
        Log::info($dryRun ? 'retention.employee_prune.dry_run' : 'retention.employee_prune.completed', [
            'ancillary_years' => $ancillaryYears, 'evidence_years' => $evidenceYears, 'categories' => $totals,
        ]);

        $verb = $dryRun ? 'Dry run: would delete' : 'Deleted';
        if ($ancillaryYears !== null) {
            $a = $totals[RetentionMetrics::EMPLOYEE_ANCILLARY];
            $this->info("{$verb} ancillary HR details of {$this->n($a, $dryRun)} Employee(s) (unresolved separation: {$a['unresolved']}, dependency-blocked: {$a['dependency_blocked']}, held: {$a['held']}, errors: {$a['errors']}).");
        }
        if ($evidenceYears !== null) {
            $p = $totals[RetentionMetrics::PAYROLL_EMPLOYEE_RECORD];
            $e = $totals[RetentionMetrics::EMPLOYEE_EVIDENCE];
            $this->info("{$verb} payroll records of {$this->n($p, $dryRun)} Employee(s) and the employment evidence of {$this->n($e, $dryRun)} Employee(s) (unresolved separation: {$e['unresolved']}, dependency-blocked: ".($p['dependency_blocked'] + $e['dependency_blocked']).', held: '.$e['held'].', errors: '.($p['errors'] + $e['errors']).').');
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
            $total[$k] += $n;
        }

        if ($held) {
            // A held School counted only; none of its Employees is reported as blocked or deletable.
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
