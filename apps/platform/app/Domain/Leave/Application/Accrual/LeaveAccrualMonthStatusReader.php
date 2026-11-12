<?php

namespace App\Domain\Leave\Application\Accrual;

use App\Domain\HR\Application\EmploymentRoster;
use App\Domain\Leave\Application\LeaveCapabilities;
use App\Domain\Leave\Infrastructure\LeaveType;
use App\Domain\Leave\Infrastructure\LeaveYear;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * HR.R1-C32 (WO-9, D-3 option 3, HR owner 2026-11-12; LVA-1, TRR-2): is a month's monthly accrual done? Read-only:
 * accrual stays a manual run (LeaveAccrualService::execute()), and this never posts, adjusts, re-runs, retries,
 * locks a run or claims a run happened. It answers ONE closed status per leave type, year and month from
 * canonical evidence only: the recorded runs (`leave_accrual_runs`) and the existing calculator's read-only
 * evaluation (`LeaveAccrualCalculator::evaluate(lock: false)`, what `preview()` runs). No second formula, no
 * stored status, no table.
 *
 * Precedence, first match wins:
 *   1. the month starts after the School-local today          -> not_due (no evaluation needed)
 *   2. its open run is `processing`                            -> running (started_at shown, no stall threshold)
 *   3. its open run is `failed`                                -> failed (failure_code shown; resume it)
 *   4. the evaluation could not be made                        -> unknown (never complete)
 *   5. the evaluation would credit someone: a completed run    -> incomplete, otherwise missed
 *   6. credits exist with no completed run (contradiction)     -> unknown
 *   7. someone's version is not yet postable this month        -> not_due
 *   8. a completed run exists                                  -> complete
 *   9. otherwise                                               -> nothing_to_credit
 *
 * `blockedBy` says when the month can no longer be run (`year_closed`, `type_inactive`, `type_untracked`): the
 * status stays what the evidence says, and the workspace says why a run would be refused.
 *
 * Consistency: the runs are read once (per year, or per month), then the month is evaluated. `complete` needs the
 * evaluation itself to find nobody left to credit, and a completed run is final (trigger), so no interleaving
 * yields a false `complete`: a run that commits in between reads `missed`, `incomplete` or `unknown` (credits
 * without a completed run), or the truth (`not_due`, `nothing_to_credit`). The evaluation's HR coverage check takes the same momentary FOR SHARE as
 * preview(), in a transaction of its own; nothing here locks a run.
 */
final class LeaveAccrualMonthStatusReader
{
    public const NOT_DUE = 'not_due';

    public const RUNNING = 'running';

    public const FAILED = 'failed';

    public const MISSED = 'missed';

    public const INCOMPLETE = 'incomplete';

    public const COMPLETE = 'complete';

    public const NOTHING_TO_CREDIT = 'nothing_to_credit';

    public const UNKNOWN = 'unknown';

    public const STATUSES = [self::NOT_DUE, self::RUNNING, self::FAILED, self::MISSED, self::INCOMPLETE, self::COMPLETE, self::NOTHING_TO_CREDIT, self::UNKNOWN];

    private const BATCH_SIZE = LeaveAccrualService::BATCH_SIZE;

    public function __construct(
        private readonly TenantContext $context,
        private readonly CapabilityResolver $capabilities,
        private readonly EmploymentRoster $roster,
        private readonly LeaveAccrualCalculator $calculator,
    ) {}

    /**
     * Every month of the year, for a holder of `hr.leave.view` or `hr.leave.manage` in this School.
     *
     * @return list<array<string, mixed>>
     */
    public function year(School $school, string $leaveTypeId, string $leaveYearId, User $actor): array
    {
        $this->authorize($school, $actor);

        return $this->yearFor($school, $leaveTypeId, $leaveYearId);
    }

    /** @return array<string, mixed> */
    public function month(School $school, string $leaveTypeId, string $leaveYearId, string $periodStart, User $actor): array
    {
        $this->authorize($school, $actor);

        return $this->context->withSchool($school, function () use ($school, $leaveTypeId, $leaveYearId, $periodStart) {
            [$type, $year] = $this->typeAndYear($school, $leaveTypeId, $leaveYearId);
            if (! in_array($periodStart, $this->months($year), true)) {
                throw new AccrualMonthNotInYearException('The month is not a month of this Leave Year.');
            }
            try {
                $runs = $this->runs($school, $type, $year, $periodStart);
            } catch (Throwable $e) {
                $runs = $e;
            }

            return $this->evaluate($school, $type, $year, $periodStart, SchoolTimezone::today($school), $runs, $this->blockedBy($school, $type, $year));
        });
    }

    /**
     * The operator report's entry (`leave:accrual-status`): no User, so no capability; it runs in the School's
     * context and returns ids, codes and counts only.
     *
     * @return list<array<string, mixed>>
     */
    public function yearFor(School $school, string $leaveTypeId, string $leaveYearId): array
    {
        return $this->context->withSchool($school, function () use ($school, $leaveTypeId, $leaveYearId) {
            [$type, $year] = $this->typeAndYear($school, $leaveTypeId, $leaveYearId);
            $today = SchoolTimezone::today($school);
            $blockedBy = $this->blockedBy($school, $type, $year);
            // One query for the year's runs (WO-9 "bounded cost"); a failure reads every month as unknown.
            try {
                $runs = $this->runs($school, $type, $year, null)->groupBy('period_start');
            } catch (Throwable $e) {
                $runs = $e;
            }

            return array_map(fn (string $month) => $this->evaluate($school, $type, $year, $month, $today,
                $runs instanceof Throwable ? $runs : ($runs->get($month) ?? collect()), $blockedBy), $this->months($year));
        });
    }

    /**
     * @param  Collection<int, \stdClass>|Throwable  $runs  the month's runs, newest first, or why they could not be read
     * @return array<string, mixed>
     */
    private function evaluate(School $school, LeaveType $type, LeaveYear $year, string $periodStart, string $today, Collection|Throwable $runs, ?string $blockedBy): array
    {
        $status = ['periodStart' => $periodStart, 'status' => self::NOT_DUE, 'reason' => 'month_not_started', 'counts' => null,
            'runId' => null, 'runStatus' => null, 'failureCode' => null, 'startedAt' => null, 'completedAt' => null, 'blockedBy' => $blockedBy];

        // 1. A month that has not started cannot be due under either timing (advance: its first day; arrears: after it).
        if ($periodStart > $today) {
            return $status;
        }

        // The canonical evaluation (see the class docblock for why the order cannot produce a false `complete`).
        $counts = null;
        $failure = null;
        try {
            $counts = $this->counts($school, $type, $year, $periodStart, $today);
        } catch (Throwable $e) {
            $failure = $e;
        }

        if ($runs instanceof Throwable) {
            // The run evidence could not be read: never guessed (review LOW-1).
            $this->logFailure($school, $type, $periodStart, $runs, 'leave.accrual_status.runs_unreadable');

            return array_merge($status, ['counts' => $counts, 'status' => self::UNKNOWN, 'reason' => 'runs_unreadable']);
        }
        $open = $runs->first(fn ($r) => in_array($r->status, ['processing', 'failed'], true));
        $completed = $runs->first(fn ($r) => $r->status === 'completed');
        $shown = $open ?? $completed;
        if ($shown !== null) {
            $status = ['runId' => $shown->id, 'runStatus' => $shown->status, 'failureCode' => $shown->failure_code,
                'startedAt' => self::iso($shown->started_at), 'completedAt' => self::iso($shown->completed_at)] + $status;
        }
        $status['counts'] = $counts;

        if ($counts === null) {
            $this->logFailure($school, $type, $periodStart, $failure, 'leave.accrual_status.evaluation_failed');
        }

        return array_merge($status, self::classify($open?->status, $completed !== null, $counts));
    }

    /**
     * Steps 2 to 9 of the precedence (step 1 needs no evidence). Pure, so the whole matrix is pinned on its own.
     *
     * @param  'processing'|'failed'|null  $openRun  the month's open run, if any
     * @param  array<string, int>|null  $counts  the evaluation's outcome counts, or null when it could not be made
     * @return array{status: string, reason: string}
     */
    public static function classify(?string $openRun, bool $hasCompletedRun, ?array $counts): array
    {
        return match (true) {
            $openRun === 'processing' => ['status' => self::RUNNING, 'reason' => 'run_processing'],
            $openRun !== null => ['status' => self::FAILED, 'reason' => 'run_failed'],
            $counts === null => ['status' => self::UNKNOWN, 'reason' => 'evaluation_failed'],
            ($counts[LeaveAccrualOutcome::CREDITED->value] ?? 0) > 0 => $hasCompletedRun
                ? ['status' => self::INCOMPLETE, 'reason' => 'would_still_credit']
                : ['status' => self::MISSED, 'reason' => 'never_run'],
            ! $hasCompletedRun && ($counts[LeaveAccrualOutcome::ALREADY_CREDITED->value] ?? 0) > 0 => ['status' => self::UNKNOWN, 'reason' => 'credits_without_completed_run'],
            ($counts[LeaveAccrualOutcome::NOT_DUE->value] ?? 0) > 0 => ['status' => self::NOT_DUE, 'reason' => 'version_not_yet_postable'],
            $hasCompletedRun => ['status' => self::COMPLETE, 'reason' => 'run_completed'],
            default => ['status' => self::NOTHING_TO_CREDIT, 'reason' => 'no_credit_due'],
        };
    }

    /**
     * The canonical evaluation's complete outcome counts for the month, page by page over the whole roster, exactly
     * as LeaveAccrualService::preview() counts them.
     *
     * @return array<string, int>
     */
    private function counts(School $school, LeaveType $type, LeaveYear $year, string $periodStart, string $today): array
    {
        $periodEnd = LeaveAccrualCalculator::periodEnd($periodStart);
        $counts = array_fill_keys(array_map(fn (LeaveAccrualOutcome $o) => $o->value, LeaveAccrualOutcome::cases()), 0);
        $cursor = null;
        do {
            $page = $this->roster->pageOverlapping($school, $periodStart, $periodEnd, $cursor, self::BATCH_SIZE);
            foreach ($this->calculator->evaluate($school, $type->id, $year->id, $periodStart, $page, $today, lock: false) as $evaluation) {
                $counts[$evaluation->outcome->value]++;
            }
            $cursor = $page === [] ? null : $page[array_key_last($page)]['employmentRecordId'];
        } while (count($page) === self::BATCH_SIZE);

        return $counts;
    }

    /** @return Collection<int, \stdClass> newest first */
    private function runs(School $school, LeaveType $type, LeaveYear $year, ?string $periodStart): Collection
    {
        return DB::table('leave_accrual_runs')->where('school_id', $school->id)->where('leave_type_id', $type->id)
            ->where('leave_year_id', $year->id)->when($periodStart !== null, fn ($q) => $q->where('period_start', $periodStart))
            ->orderByDesc('started_at')->orderByDesc('id')
            ->get(['id', 'period_start', 'status', 'failure_code', 'started_at', 'completed_at'])
            ->map(function (object $r): object {
                $r->period_start = substr((string) $r->period_start, 0, 10);

                return $r;
            });
    }

    /** Why LeaveAccrualService::execute() would refuse this month now, if it would (review LOW-2). */
    private function blockedBy(School $school, LeaveType $type, LeaveYear $year): ?string
    {
        return match (true) {
            ! $type->tracks_balance => 'type_untracked',
            $type->status !== 'active' => 'type_inactive',
            DB::table('leave_year_closes')->where('school_id', $school->id)->where('leave_year_id', $year->id)->exists() => 'year_closed',
            default => null,
        };
    }

    private static function iso(?string $timestamp): ?string
    {
        return $timestamp === null ? null : CarbonImmutable::parse($timestamp, 'UTC')->toIso8601String();
    }

    private function logFailure(School $school, LeaveType $type, string $periodStart, ?Throwable $failure, string $event): void
    {
        // Diagnostics for the operator, never a value (rule 63): ids and the exception class only.
        Log::warning($event, [
            'school_id' => $school->id, 'leave_type_id' => $type->id, 'period_start' => $periodStart,
            'exception' => $failure === null ? null : $failure::class,
        ]);
    }

    /** @return list<string> the first day of each month of the year, as the grid lists them */
    private function months(LeaveYear $year): array
    {
        $months = [];
        for ($m = CarbonImmutable::parse($year->starts_on->toDateString()); $m->toDateString() <= $year->ends_on->toDateString(); $m = $m->addMonthNoOverflow()) {
            $months[] = $m->toDateString();
        }

        return $months;
    }

    /** @return array{0: LeaveType, 1: LeaveYear} */
    private function typeAndYear(School $school, string $leaveTypeId, string $leaveYearId): array
    {
        return [
            LeaveType::query()->where('school_id', $school->id)->findOrFail($leaveTypeId),
            LeaveYear::query()->where('school_id', $school->id)->findOrFail($leaveYearId),
        ];
    }

    private function authorize(School $school, User $actor): void
    {
        if (! $this->capabilities->canInSchool($actor, LeaveCapabilities::VIEW, $school)
            && ! $this->capabilities->canInSchool($actor, LeaveCapabilities::MANAGE, $school)) {
            throw new AuthorizationException('This action is unauthorized.');
        }
    }
}
