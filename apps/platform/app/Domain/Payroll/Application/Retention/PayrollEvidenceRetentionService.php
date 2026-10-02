<?php

namespace App\Domain\Payroll\Application\Retention;

use App\Domain\HR\Application\Retention\EmployeeRetentionEligibility;
use App\Models\School;
use App\Support\Retention\RetentionExpiry;
use App\Support\Retention\RetentionUnit;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * E21.3F (E21-D9 x E21-D8, docs/security/E21-RETENTION-DETERMINATION.md,
 * ADR 0064 §21 amended; project-adopted, pending legal ratification):
 * Payroll's own D9 business evidence, kept 8 calendar years after the
 * Employee's final separation (EmployeeRetentionEligibility, the one
 * canonical answer; EMPLOYEE_EVIDENCE_RETENTION_YEARS, the one D9 setting),
 * then deleted. Only `platform:payroll-retention-prune` calls this, never a
 * request.
 *
 * EVIDENCE, one Employee per transaction (lock, recheck, database proof):
 * - the Employee's posted payroll results with their lines and statutory
 *   results, their adjustments (manual overrides, correction deltas) and
 *   their LWF annual charges. Results are per Employee; runs are not.
 * - The database function re-proves the separation and refuses while any
 *   run holding the evidence is not posted, was posted (or reversed, or
 *   corrected) less than the period ago, or carries statutory results
 *   without its statutory posting. Those Employees count as
 *   `dependency_blocked` and keep everything: a late correction is new
 *   evidence with its own clock (longest period wins).
 * - The run, its postings and its journal entry stay. Payroll totals are
 *   ledger-account totals, never recomputed from results.
 * - Payroll's per-employment configuration (compensation, statutory
 *   profiles) and then the Employee itself follow in the NEXT
 *   `employee-retention-prune`, which re-evaluates what this released.
 *
 * RUNS, one regular run with its corrections per transaction: once
 * retention emptied every run of the group (no result, no adjustment) and
 * every run and posting predates the cutoff, the postings (payroll and
 * statutory) and the runs are deleted. That releases their journal entries:
 * Finance's own D8 expiry may remove them once their financial period has
 * been closed long enough, never earlier. Payroll never deletes a journal
 * entry.
 *
 * Draft, calculated and approved runs are live working state: their rows
 * keep the Employee and are never aged. Payroll periods and configuration
 * (components, structures, accounting, statutory rule versions) are
 * School configuration and are untouched.
 *
 * Counts only; no identifier, amount or name leaves this class.
 */
final class PayrollEvidenceRetentionService
{
    /** Database refusals that mean "keep it" rather than "something failed". */
    private const KEEP = [
        'retention_payroll_dependency' => 'payroll_runs',
        'retention_payroll_employee' => 'employment_records',
    ];

    public function __construct(
        private readonly EmployeeRetentionEligibility $employees,
        private readonly RetentionExpiry $expiry,
        private readonly TenantContext $context,
    ) {}

    /** @return array{eligible: int, deleted: int, unresolved: int, dependency_blocked: int, errors: int} */
    public function pruneEvidence(School $school, string $cutoffDate, int $batch, bool $dryRun): array
    {
        return $this->employees->purgeSeparatedBefore(
            $school,
            $cutoffDate,
            $batch,
            $dryRun,
            fn (Builder $q) => $q->where(fn (Builder $any) => $any
                ->whereExists(fn (Builder $r) => $r->selectRaw('1')->from('payroll_run_results as r')->whereColumn('r.employee_id', 'employees.id'))
                ->orWhereExists(fn (Builder $e) => $e->selectRaw('1')->from('employment_records as er')->whereColumn('er.employee_id', 'employees.id')
                    ->where(fn (Builder $own) => $own
                        ->whereExists(fn (Builder $a) => $a->selectRaw('1')->from('payroll_adjustments as a')->whereColumn('a.employment_record_id', 'er.id'))
                        ->orWhereExists(fn (Builder $l) => $l->selectRaw('1')->from('payroll_lwf_annual_charges as l')->whereColumn('l.employment_record_id', 'er.id'))))),
            fn (string $employeeId): array => $this->kept(fn () => $this->expiry->payrollEmployeeEvidence($school, $employeeId, $cutoffDate, true)),
            fn (string $employeeId): ?array => $this->expiry->payrollEmployeeEvidence($school, $employeeId, $cutoffDate, false) > 0 ? [] : null,
        );
    }

    /**
     * @param  CarbonInterface  $cutoff  a run and every posting strictly before it are old enough
     * @return array{eligible: int, deleted: int, unresolved: int, dependency_blocked: int, errors: int}
     */
    public function pruneRuns(School $school, CarbonInterface $cutoff, int $batch, bool $dryRun): array
    {
        $result = ['eligible' => 0, 'deleted' => 0, 'unresolved' => 0, 'dependency_blocked' => 0, 'errors' => 0];
        $at = $cutoff->copy()->utc()->format('Y-m-d H:i:s');

        $this->context->withSchool($school, function () use ($school, $cutoff, $at, $batch, $dryRun, &$result): void {
            DB::table('payroll_runs')->where('school_id', $school->id)->where('run_kind', 'regular')->where('status', 'posted')
                ->whereNotNull('results_expired_at')->where('posted_at', '<', $at)
                ->whereNotExists(fn (Builder $r) => $r->selectRaw('1')->from('payroll_run_results as r')->whereColumn('r.payroll_run_id', 'payroll_runs.id'))
                ->select('id')->orderBy('id')
                ->chunkById($batch, function ($runs) use ($school, $cutoff, $dryRun, &$result): void {
                    foreach ($runs as $run) {
                        RetentionUnit::purge(
                            $result,
                            $dryRun,
                            fn (): bool => DB::table('payroll_runs')->where('id', $run->id)->lockForUpdate()->first(['id']) !== null,
                            fn (): array => $this->kept(fn () => $this->expiry->payrollRun($school, $run->id, $cutoff, true)),
                            function () use ($school, $run, $cutoff): array {
                                $this->expiry->payrollRun($school, $run->id, $cutoff, false);

                                return [];
                            },
                        );
                    }
                });
        });

        return $result;
    }

    /**
     * The database's own verdict, in a savepoint: [] when it would expire the
     * unit, the keeping reason when it refuses on a dependency or the
     * separation. Any other failure propagates (an error, never a keep).
     *
     * @param  callable(): int  $check
     * @return list<string>
     */
    private function kept(callable $check): array
    {
        try {
            DB::transaction(fn () => $check());

            return [];
        } catch (QueryException $e) {
            foreach (self::KEEP as $marker => $reason) {
                if (str_contains($e->getMessage(), $marker)) {
                    return [$reason];
                }
            }

            throw $e;
        }
    }
}
