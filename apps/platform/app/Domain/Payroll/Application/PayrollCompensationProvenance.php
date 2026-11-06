<?php

namespace App\Domain\Payroll\Application;

use App\Domain\Payroll\Infrastructure\EmployeeCompensationAssignment;
use App\Domain\Payroll\Infrastructure\PayrollPeriod;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Infrastructure\PayrollRunCalculationSource;
use App\Support\Concurrency\SchoolCompensationLock;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * HR.R1-C1 (HR.R1-0 PAY-2): the ONE answer to "which compensation does a
 * payroll calculation rest on, is it still current, and which changes does
 * the run pay first".
 *
 * - A calculation of a REGULAR run records every compensation assignment
 *   overlapping the run's period (`record()`), with the effective range it
 *   read: those are the assignments the run pays (one per employment for a
 *   whole period, or every assignment of a period that changed, which a
 *   manual override prices).
 * - A run PAYS an assignment when its approved (or, before approval, latest)
 *   calculation recorded it. It pays it FIRST when no QUALIFYING run already
 *   did: another regular run, approved or posted, whose approved calculation
 *   priced it, that has not been reversed (HR.R1-C3; the run's status never
 *   shows this, ADR 0034) and that someone other than the change's author
 *   approved. The author of a change the run pays first is one of the run's
 *   makers -- whenever the change was recorded and whenever it took effect. A
 *   run that pays a change another independent checker already approved, and
 *   whose approval still stands, does not re-attribute it.
 * - A calculation is FRESH while the compensation overlapping its period is
 *   exactly what it recorded. A compensation change of the period recorded
 *   after the calculation (or a calculation that recorded nothing, i.e. made
 *   before this provenance existed) makes the run stale: it must be
 *   recalculated before approval.
 *
 * The rules live in SQL (`payroll_calculation_compensation_fresh`,
 * `payroll_run_first_paid_compensation`), which the approval guard also
 * calls -- the review packet, the maker/checker check and the database
 * backstop cannot drift apart.
 *
 * Serialization: `lock()` takes the School's compensation lock -- SHARED by a
 * calculation or an approval, EXCLUSIVE by a compensation write or a reversal
 * (also taken by their triggers). Taken after the run row lock, before any
 * other. A reversal changes another run's first-paid set, and this one lock
 * orders it against that run's approval with no run-to-run lock, so there is
 * no lock cycle.
 */
final class PayrollCompensationProvenance
{
    public const LOCK_PREFIX = SchoolCompensationLock::PREFIX;

    public function lock(string $schoolId, bool $shared): void
    {
        SchoolCompensationLock::acquire($schoolId, $shared);
    }

    /**
     * Records the compensation a calculation of a regular run read: every
     * assignment overlapping the period of every employment it RESOLVED into a
     * result (for a complete calculation -- the only kind ever approved -- that
     * is every assignment overlapping the period; an unresolved employment has
     * no result, so its assignment is not provenance and never becomes a
     * retention reference of its own). `$overridden` names the employments
     * priced by a manual override (their period changed).
     *
     * @param  list<string>  $resolved  employment record ids with a result
     * @param  list<string>  $overridden  employment record ids
     */
    public function record(PayrollRun $run, PayrollPeriod $period, string $calculationId, array $resolved, array $overridden): void
    {
        if ($resolved === []) {
            return;
        }
        $overridden = array_flip($overridden);
        $assignments = EmployeeCompensationAssignment::query()
            ->where('school_id', $run->school_id)
            ->whereIn('employment_record_id', $resolved)
            ->where('effective_from', '<=', $period->ends_on->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $period->starts_on->toDateString()))
            ->orderBy('id')
            ->get(['id', 'employment_record_id', 'effective_from', 'effective_to']);

        foreach ($assignments as $a) {
            PayrollRunCalculationSource::query()->create([
                'school_id' => $run->school_id,
                'payroll_run_id' => $run->id,
                'payroll_run_calculation_id' => $calculationId,
                'compensation_assignment_id' => $a->id,
                'employment_record_id' => $a->employment_record_id,
                'effective_from' => $a->effective_from->toDateString(),
                'effective_to' => $a->effective_to?->toDateString(),
                'basis' => isset($overridden[$a->employment_record_id]) ? PayrollRunCalculationSource::BASIS_PERIOD_OVERRIDE : PayrollRunCalculationSource::BASIS_STRUCTURE,
            ]);
        }
    }

    public function isFresh(PayrollRun $run, string $calculationId): bool
    {
        if ($run->run_kind !== 'regular') {
            return true; // priced by its correction deltas, never by compensation
        }

        return (bool) DB::selectOne('SELECT payroll_calculation_compensation_fresh(?, ?, ?) AS fresh', [$run->school_id, $run->id, $calculationId])->fresh;
    }

    /**
     * HR.R1-C3: a deterministic version of the run's first-paid set. A reversal
     * (or another run's approval) changes that set without a new calculation,
     * so the calculation id alone cannot tell a checker their packet is out of
     * date. The review shows this value and the approval names it. `$run` is
     * regular or the set is empty.
     */
    public function attributionVersion(PayrollRun $run, string $calculationId): string
    {
        if ($run->run_kind !== 'regular') {
            return hash('sha256', '');
        }

        return (string) DB::selectOne(
            "SELECT encode(sha256(convert_to(COALESCE(string_agg(f.compensation_assignment_id::text || ':' || COALESCE(f.assigned_by_user_id::text, ''), ',' ORDER BY f.compensation_assignment_id), ''), 'UTF8')), 'hex') AS version
               FROM payroll_run_first_paid_compensation(?, ?, ?) f",
            [$run->school_id, $run->id, $calculationId],
        )->version;
    }

    /** The compensation changes the run pays first (`compensation_assignment_id`, `employment_record_id`, `assigned_by_user_id`, `basis`). */
    public function firstPaid(PayrollRun $run, string $calculationId): Builder
    {
        return DB::query()->fromRaw('payroll_run_first_paid_compensation(?, ?, ?) AS f', [$run->school_id, $run->id, $calculationId]);
    }

    /**
     * Who recorded a change the run pays first (never NULL: an unrecorded
     * author adds nobody and is reported, never guessed).
     *
     * @return list<string>
     */
    public function authors(PayrollRun $run, ?string $calculationId): array
    {
        if ($calculationId === null || $run->run_kind !== 'regular') {
            return [];
        }

        return $this->firstPaid($run, $calculationId)->whereNotNull('f.assigned_by_user_id')->distinct()
            ->pluck('f.assigned_by_user_id')->map(fn ($id) => (string) $id)->values()->all();
    }
}
