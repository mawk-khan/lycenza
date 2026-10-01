<?php

namespace App\Domain\HR\Application\Retention;

use App\Models\School;
use App\Support\Retention\RetentionUnit;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * E21-D9 (docs/security/E21-RETENTION-DETERMINATION.md, project-adopted,
 * pending legal ratification): the ONE canonical answer to "when did this
 * Employee finally separate from the School?". Every D9 retention operation
 * asks it: HR (ancillary details, employment evidence) and Payroll (its
 * compensation and statutory rows). Employee Documents follow their
 * Employee. It is used only by maintenance commands, never by a request,
 * and is independent of HR authorization.
 *
 * The authoritative facts are EmploymentRecords. `EmploymentService::end()`
 * is the only path to a terminal status: `separated`, `terminated`,
 * `retired` or `deceased`, from a closed set, with an inclusive `ends_on`.
 * A rehire is a NEW EmploymentRecord on the same Employee (HR.md, "Rehire
 * strategy"). An Employee has SEPARATED only when all hold:
 * - it has at least one EmploymentRecord;
 * - every EmploymentRecord is terminal. A draft, pre-joining, active or
 *   notice-period record is a current or future employment, so the
 *   Employee is current;
 * - every terminal record carries `ends_on`. A terminal record without one
 *   is UNRESOLVED and kept.
 *
 * The separation date is the latest `ends_on`, so across rehires the clock
 * runs from the LAST separation. A future `ends_on` (notice served) is
 * simply not yet past any cutoff. `employees.record_status` (archive), the
 * User link and membership state are never used as a separation signal.
 *
 * `lockSeparation()` locks the Employee row FOR UPDATE. That is the lock
 * `EmploymentService::create()` takes before every hire or rehire, and an
 * EmploymentRecord insert also takes FOR KEY SHARE on its Employee. So a
 * rehire either commits first (the recheck after the lock sees it and
 * nothing is purged) or waits for the purge.
 */
final class EmployeeRetentionEligibility
{
    /** EmploymentService's closed terminal set. */
    public const TERMINAL_STATUSES = ['separated', 'terminated', 'retired', 'deceased'];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * The pure rule. The dry run and every purge use it, so they always agree.
     *
     * @param  list<array{status: string, ends_on: ?string}>  $employments  every EmploymentRecord of the Employee
     */
    public static function resolve(array $employments): EmployeeSeparation
    {
        if ($employments === []) {
            return EmployeeSeparation::unresolved();
        }

        foreach ($employments as $employment) {
            if (! in_array($employment['status'], self::TERMINAL_STATUSES, true)) {
                return EmployeeSeparation::current();
            }
        }

        foreach ($employments as $employment) {
            if ($employment['ends_on'] === null) {
                return EmployeeSeparation::unresolved();
            }
        }

        return EmployeeSeparation::separated(max(array_map(fn (array $e): string => (string) $e['ends_on'], $employments)));
    }

    /**
     * The one per-Employee purge loop every D9 operation uses (RetentionUnit
     * discipline: lock, recheck, dependencies, own rows, bytes after commit).
     * It walks one School's Employees in id order, in bounded chunks. With
     * `$withRows`, only Employees that still hold the caller's own rows are
     * walked.
     *
     * @param  (Closure(Builder): mixed)|null  $withRows
     * @param  Closure(string): list<string>  $blockers  retained dependents (read-only)
     * @param  Closure(string): (list<object{storage_disk: string, storage_path: string}>|null)  $purge
     * @param  string|null  $only  narrows the walk to one Employee (an erasure case)
     * @return array{eligible: int, deleted: int, unresolved: int, dependency_blocked: int, errors: int}
     */
    public function purgeSeparatedBefore(School $school, string $cutoffDate, int $batch, bool $dryRun, ?Closure $withRows, Closure $blockers, Closure $purge, ?string $only = null): array
    {
        $result = ['eligible' => 0, 'deleted' => 0, 'unresolved' => 0, 'dependency_blocked' => 0, 'errors' => 0];

        $this->context->withSchool($school, function () use ($school, $cutoffDate, $batch, $dryRun, $withRows, $blockers, $purge, $only, &$result): void {
            $query = DB::table('employees')->where('school_id', $school->id)->select('id');
            if ($only !== null) {
                // E21.2F: one reviewed erasure case's subject only.
                $query->where('id', $only);
            }
            if ($withRows !== null) {
                $withRows($query);
            }

            $query->orderBy('id')->chunkById($batch, function ($employees) use ($cutoffDate, $dryRun, $blockers, $purge, &$result): void {
                $employments = DB::table('employment_records')->whereIn('employee_id', $employees->pluck('id')->all())
                    ->get(['employee_id', 'status', 'ends_on'])->groupBy('employee_id');

                foreach ($employees as $employee) {
                    $separation = self::resolve($this->rows($employments->get($employee->id)?->all() ?? []));

                    if ($separation->state === EmployeeSeparation::UNRESOLVED) {
                        $result['unresolved']++;
                    } elseif ($separation->separatedBefore($cutoffDate)) {
                        RetentionUnit::purge(
                            $result,
                            $dryRun,
                            fn (): bool => $this->lockSeparation($employee->id)?->separatedBefore($cutoffDate) === true,
                            fn (): array => $blockers($employee->id),
                            fn (): ?array => $purge($employee->id),
                        );
                    }
                }
            });
        });

        return $result;
    }

    /**
     * E21.2F (closure readiness, read-only): the latest final separation
     * among a School's Employees, and how many are still current or
     * unresolved (no date is known while any is).
     *
     * @return array{latest: ?string, pending: int}
     */
    public function latestSeparation(School $school, int $batch = 500): array
    {
        return $this->context->withSchool($school, function () use ($school, $batch): array {
            $latest = null;
            $pending = 0;

            DB::table('employees')->where('school_id', $school->id)->select('id')->orderBy('id')->chunkById($batch, function ($employees) use (&$latest, &$pending): void {
                $employments = DB::table('employment_records')->whereIn('employee_id', $employees->pluck('id')->all())->get(['employee_id', 'status', 'ends_on'])->groupBy('employee_id');

                foreach ($employees as $employee) {
                    $separation = self::resolve($this->rows($employments->get($employee->id)?->all() ?? []));
                    if ($separation->state === EmployeeSeparation::SEPARATED) {
                        $latest = max($latest ?? $separation->separatedOn, $separation->separatedOn);
                    } else {
                        $pending++;
                    }
                }
            });

            return ['latest' => $latest, 'pending' => $pending];
        });
    }

    /**
     * E21.2F (erasure planning, read-only): one Employee's separation,
     * without a lock. Null means the Employee is not in this School's
     * context.
     */
    public function separationOf(School $school, string $employeeId): ?EmployeeSeparation
    {
        return $this->context->withSchool($school, function () use ($employeeId): ?EmployeeSeparation {
            if (! DB::table('employees')->where('id', $employeeId)->exists()) {
                return null;
            }

            return self::resolve($this->rows(DB::table('employment_records')->where('employee_id', $employeeId)->get(['status', 'ends_on'])->all()));
        });
    }

    /**
     * Locks the Employee row FOR UPDATE and resolves its separation from
     * committed data read after the lock. Call it only inside a transaction,
     * in the Employee's tenant context. Null means the Employee no longer
     * exists.
     */
    public function lockSeparation(string $employeeId): ?EmployeeSeparation
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('lockSeparation() must run inside the purge transaction.');
        }

        if (DB::table('employees')->where('id', $employeeId)->lockForUpdate()->first(['id']) === null) {
            return null;
        }

        return self::resolve($this->rows(DB::table('employment_records')->where('employee_id', $employeeId)->get(['status', 'ends_on'])->all()));
    }

    /**
     * @param  array<int, object>  $rows
     * @return list<array{status: string, ends_on: ?string}>
     */
    private function rows(array $rows): array
    {
        return array_values(array_map(fn (object $row): array => ['status' => (string) $row->status, 'ends_on' => $row->ends_on === null ? null : substr((string) $row->ends_on, 0, 10)], $rows));
    }
}
