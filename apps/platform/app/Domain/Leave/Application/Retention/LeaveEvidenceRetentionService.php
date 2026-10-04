<?php

namespace App\Domain\Leave\Application\Retention;

use App\Domain\HR\Application\Retention\EmployeeRetentionEligibility;
use App\Domain\HR\Application\Retention\EmployeeSeparation;
use App\Models\School;
use App\Support\Retention\RetentionExpiry;
use App\Support\Retention\RetentionMetrics;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * HRX.6 (E21-D9, ADR 0065 §27; project-adopted, pending legal
 * ratification): Leave's own D9 participant. One Employee's Leave evidence
 * is kept 8 calendar years after the Employee's final separation
 * (EmployeeRetentionEligibility, the one canonical answer;
 * EMPLOYEE_EVIDENCE_RETENTION_YEARS, the one D9 setting), then deleted.
 * Only `platform:employee-retention-prune` (and a reviewed erasure case
 * through the same purge) calls this, never a request.
 *
 * One Employee per transaction (lock, recheck, database proof): the fixed
 * Leave retention function re-proves the tenant, the 8-year floor and the
 * separation, takes the HRX locks, refuses while any row of the unit is younger than the cutoff (a late
 * cancellation or reconciliation is new evidence with its own clock), and
 * deletes the unit in a causally complete order. Those refusals count as
 * `dependency_blocked` and keep everything.
 *
 * School configuration (settings, years, types, policies, calendar,
 * allocation-run and year-close headers) is never touched. The Employee root
 * goes later, in HR's own evidence purge, once nothing references it -- a
 * decision this Employee made as someone else's manager stays with that
 * request and keeps the root meanwhile.
 *
 * Counts only; no identifier, date or leave detail leaves this class.
 */
final class LeaveEvidenceRetentionService
{
    /** The Leave tables this purge removes per Employee (HR's dry run counts them cleared). */
    public const TABLES = [
        'leave_policy_assignments', 'leave_ledger_entries', 'leave_requests', 'leave_request_days',
        'leave_year_close_items', 'leave_year_close_reconciliations',
    ];

    /** Database refusals that mean "keep it" rather than "something failed". */
    private const KEEP = [
        RetentionExpiry::REFUSED_HRX_YOUNGER_ROW => 'leave_ledger_entries',
        RetentionExpiry::REFUSED_EMPLOYEE_NOT_SEPARATED => 'employment_records',
        RetentionExpiry::REFUSED_HELD => 'retention_holds',
    ];

    public function __construct(
        private readonly EmployeeRetentionEligibility $employees,
        private readonly RetentionExpiry $expiry,
    ) {}

    /** @return array{eligible: int, deleted: int, unresolved: int, dependency_blocked: int, errors: int} */
    public function prune(School $school, string $cutoffDate, int $batch, bool $dryRun, ?string $only = null): array
    {
        // E21-RH.2: the whole unit runs as the dedicated retention identity (the runtime role cannot execute the
        // purge function). Its recheck is a plain read: the function locks the Employee and re-proves it.
        return $this->expiry->privileged(RetentionMetrics::LEAVE_EVIDENCE, $dryRun, fn (): array => $this->employees->purgeSeparatedBefore(
            $school,
            $cutoffDate,
            $batch,
            $dryRun,
            fn (Builder $q) => $q->where(fn (Builder $any) => $any
                ->whereExists(fn (Builder $r) => $r->selectRaw('1')->from('leave_requests as r')->whereColumn('r.employee_id', 'employees.id'))
                ->orWhereExists(fn (Builder $e) => $e->selectRaw('1')->from('employment_records as er')->whereColumn('er.employee_id', 'employees.id')
                    ->where(fn (Builder $own) => $own
                        ->whereExists(fn (Builder $a) => $a->selectRaw('1')->from('leave_policy_assignments as a')->whereColumn('a.employment_record_id', 'er.id'))
                        ->orWhereExists(fn (Builder $l) => $l->selectRaw('1')->from('leave_ledger_entries as l')->whereColumn('l.employment_record_id', 'er.id'))
                        ->orWhereExists(fn (Builder $i) => $i->selectRaw('1')->from('leave_year_close_items as i')->whereColumn('i.employment_record_id', 'er.id'))))),
            fn (string $employeeId): array => $this->kept(fn () => $this->expiry->hrxEmployeeEvidence('leave', $school, $employeeId, $cutoffDate, true)),
            fn (string $employeeId): ?array => $this->expiry->hrxEmployeeEvidence('leave', $school, $employeeId, $cutoffDate, false) > 0 ? [] : null,
            $only,
            fn (string $employeeId): ?EmployeeSeparation => $this->employees->readSeparation($employeeId),
        ));
    }

    /**
     * The database's own verdict, in a savepoint: [] when it would expire the
     * unit, the keeping reason when it refuses on a younger row or the
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
