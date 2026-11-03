<?php

namespace App\Domain\Leave\Application\Accrual;

use App\Domain\HR\Application\EmploymentRoster;
use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Application\LeaveCapabilities;
use App\Domain\Leave\Application\LeaveLedgerService;
use App\Domain\Leave\Application\LeaveLocks;
use App\Domain\Leave\Application\LeaveYearCloseService;
use App\Domain\Leave\Infrastructure\LeaveAccrualRun;
use App\Domain\Leave\Infrastructure\LeaveAccrualRunItem;
use App\Domain\Leave\Infrastructure\LeaveType;
use App\Domain\Leave\Infrastructure\LeaveYear;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * HR.10 (ADR 0065 §29.5-§29.6): monthly accrual -- preview, then a
 * recoverable, batched, idempotent run. `hr.leave.manage` (the existing
 * entitlement capability); whole-School; never Campus-scoped.
 *
 * - **Population:** EVERY employment (any status but draft) whose dates
 *   overlap the month, by a complete id-ordered traversal
 *   (`EmploymentRoster::pageOverlapping()`): no population cap, a technical
 *   batch size only.
 * - **Run:** start() opens a run for the month or RESUMES the open one (at
 *   most one open run per month, database-unique); each batch is its own
 *   transaction: the month lock, the run row, then the calculator under the
 *   HR, assignment, year and balance locks; one item per examined employment;
 *   the cursor and batch count move forward. A batch that throws rolls back
 *   alone; the run is marked `failed` with a closed code and resumes from its
 *   last committed batch. A month already credited is never credited again
 *   (`leave_ledger_entries_one_accrual`).
 * - **Policy-driven:** the units are the assigned version's; like the annual
 *   run (ORG.8D G-4 note), the run covers every eligible employment, the
 *   operator's own included -- nobody chooses an amount.
 * - **Reconciliation:** examined = the sum of the disjoint outcome counts
 *   (items, one per employment per run).
 */
class LeaveAccrualService
{
    use AuthorizesCapability;

    /** Technical batch size: one transaction per batch, never a population cap. */
    public const BATCH_SIZE = 250;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
        private readonly SchoolOperationalGuard $guard,
        private readonly EmploymentRoster $roster,
        private readonly LeaveAccrualCalculator $calculator,
        private readonly LeaveLedgerService $ledger,
        private readonly LeaveYearCloseService $closes,
    ) {}

    /**
     * Read-only: one page of the month's evaluation, and the COMPLETE outcome
     * counts (the whole population is evaluated page by page; nothing is
     * written or locked beyond HR's momentary share).
     *
     * @return array{period: array<string, string>, counts: array<string, int>, examined: int, rows: list<array<string, mixed>>, nextAfter: ?string}
     */
    public function preview(School $school, string $leaveTypeId, string $leaveYearId, string $periodStart, User $actor, ?string $after = null, int $perPage = 50): array
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::MANAGE, $school);
        $perPage = max(1, min($perPage, 200));
        $after = $after === null ? null : strtolower($after);

        return $this->context->withSchool($school, function () use ($school, $leaveTypeId, $leaveYearId, $periodStart, $after, $perPage) {
            [$type, $year] = $this->typeAndYear($school, $leaveTypeId, $leaveYearId);
            $period = $this->period($year, $periodStart);
            $today = SchoolTimezone::today($school);
            $counts = self::emptyCounts();
            $rows = [];
            $cursor = null;
            $nextAfter = null;
            do {
                $page = $this->roster->pageOverlapping($school, $period['start'], $period['end'], $cursor, self::BATCH_SIZE);
                foreach ($this->calculator->evaluate($school, $type->id, $year->id, $period['start'], $page, $today, lock: false) as $evaluation) {
                    $counts[$evaluation->outcome->value]++;
                    if (($after === null || strcmp($evaluation->employmentRecordId, $after) > 0) && count($rows) < $perPage) {
                        $rows[] = $evaluation->toArray();
                        $nextAfter = $evaluation->employmentRecordId;
                    }
                }
                $cursor = $page === [] ? null : $page[array_key_last($page)]['employmentRecordId'];
            } while (count($page) === self::BATCH_SIZE);

            return ['period' => $period, 'counts' => $counts, 'examined' => array_sum($counts), 'rows' => $rows,
                'nextAfter' => count($rows) === $perPage ? $nextAfter : null];
        });
    }

    /**
     * Opens (or resumes) the month's run and processes up to $maxBatches
     * batches. Returns the run as it stands: `completed`, or `processing`
     * (call again to continue), or `failed` (call again to resume).
     */
    public function execute(School $school, string $leaveTypeId, string $leaveYearId, string $periodStart, User $actor, int $maxBatches = 1000): LeaveAccrualRun
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::MANAGE, $school);
        $run = $this->start($school, $leaveTypeId, $leaveYearId, $periodStart, $actor);

        for ($i = 0; $i < $maxBatches && $run->status === 'processing'; $i++) {
            try {
                $run = $this->processBatch($school, $run->id, $actor);
            } catch (Throwable $e) {
                $this->markFailed($school, $run->id, $e instanceof LeaveException ? $e->errorCode() : 'LEAVE_ACCRUAL_BATCH_FAILED', $actor);
                throw $e;
            }
        }

        return $run;
    }

    /** Opens the month's run, or resumes its open (processing / failed) one. */
    public function start(School $school, string $leaveTypeId, string $leaveYearId, string $periodStart, User $actor): LeaveAccrualRun
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::MANAGE, $school);
        if (! Str::isUuid($leaveTypeId) || ! Str::isUuid($leaveYearId)) {
            throw new ModelNotFoundException('No query results for the leave type or year.');
        }
        $leaveTypeId = strtolower($leaveTypeId);
        $leaveYearId = strtolower($leaveYearId);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $leaveTypeId, $leaveYearId, $periodStart, $actor) {
            $this->guard->requireOperational($school->id);
            [$type, $year] = $this->typeAndYear($school, $leaveTypeId, $leaveYearId);
            $period = $this->period($year, $periodStart);
            LeaveLocks::accrual($school, $type->id, $year->id, $period['start']);
            if ($this->closes->closeOf($school, $year->id) !== null) {
                throw LeaveException::conflict('LEAVE_YEAR_CLOSED', 'That leave year is closed; nothing is credited into it.');
            }
            if ($period['start'] > SchoolTimezone::today($school)) {
                throw LeaveException::conflict('LEAVE_ACCRUAL_PERIOD_NOT_DUE', 'A month is credited from its first day at the earliest.');
            }

            $open = LeaveAccrualRun::query()->where('school_id', $school->id)->where('leave_type_id', $type->id)->where('leave_year_id', $year->id)
                ->where('period_start', $period['start'])->whereIn('status', ['processing', 'failed'])->lockForUpdate()->first();
            if ($open !== null) {
                if ($open->status === 'failed') {
                    $open->forceFill(['status' => 'processing', 'failure_code' => null])->save();
                    $this->audit->school($school, 'leave.accrual_run.resumed', actor: $actor, subject: $open, metadata: ['batchCount' => $open->batch_count]);
                }

                return $open;
            }

            $run = LeaveAccrualRun::query()->create([
                'school_id' => $school->id, 'leave_type_id' => $type->id, 'leave_year_id' => $year->id, 'period_start' => $period['start'],
                'status' => 'processing', 'started_by_user_id' => $actor->id, 'started_at' => now(),
            ]);
            $this->audit->school($school, 'leave.accrual_run.started', actor: $actor, subject: $run, metadata: [
                'leaveTypeId' => $type->id, 'leaveYearId' => $year->id, 'periodStart' => $period['start'],
            ]);

            return $run;
        }));
    }

    /** One batch, one transaction: evaluate under the locks, credit, record every outcome, move the cursor. */
    public function processBatch(School $school, string $runId, User $actor): LeaveAccrualRun
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::MANAGE, $school);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $runId, $actor) {
            $this->guard->requireOperational($school->id);
            $peek = LeaveAccrualRun::query()->where('school_id', $school->id)->findOrFail($runId);
            $periodStart = $peek->period_start->toDateString();
            LeaveLocks::accrual($school, $peek->leave_type_id, $peek->leave_year_id, $periodStart);
            $run = LeaveAccrualRun::query()->where('school_id', $school->id)->lockForUpdate()->findOrFail($runId);
            if ($run->status !== 'processing') {
                return $run;
            }
            $periodEnd = LeaveAccrualCalculator::periodEnd($periodStart);
            $page = $this->roster->pageOverlapping($school, $periodStart, $periodEnd, $run->cursor_employment_id, self::BATCH_SIZE);
            // HR rows, then assignment rows (both FOR SHARE, inside the calculator), then the year, then balance keys (ADR 0065 §23.10).
            $evaluations = $this->calculator->evaluate($school, $run->leave_type_id, $run->leave_year_id, $periodStart, $page, SchoolTimezone::today($school), lock: true);
            LeaveLocks::year($school, $run->leave_year_id);
            if ($this->closes->closeOf($school, $run->leave_year_id) !== null) {
                throw LeaveException::conflict('LEAVE_YEAR_CLOSED', 'The leave year was closed during the run; nothing more is credited into it.');
            }

            $credited = array_values(array_filter($evaluations, fn (LeaveAccrualEvaluation $e) => $e->outcome === LeaveAccrualOutcome::CREDITED));
            usort($credited, fn (LeaveAccrualEvaluation $a, LeaveAccrualEvaluation $b) => strcmp($a->employmentRecordId, $b->employmentRecordId));
            foreach ($credited as $evaluation) {
                LeaveLocks::balance($school, $evaluation->employmentRecordId, $run->leave_type_id, $run->leave_year_id);
                $this->ledger->recordAccrual($school, $evaluation->employmentRecordId, $run->leave_type_id, $run->leave_year_id,
                    (string) $evaluation->assignmentId, $periodStart, (int) $evaluation->units, $run->id, $actor);
            }
            foreach ($evaluations as $evaluation) {
                LeaveAccrualRunItem::query()->create([
                    'school_id' => $school->id, 'accrual_run_id' => $run->id, 'employment_record_id' => $evaluation->employmentRecordId,
                    'outcome' => $evaluation->outcome->value, 'units' => $evaluation->units, 'eligibility_drift' => $evaluation->eligibilityDrift,
                ]);
            }

            $done = count($page) < self::BATCH_SIZE;
            $run->forceFill([
                'cursor_employment_id' => $page === [] ? $run->cursor_employment_id : $page[array_key_last($page)]['employmentRecordId'],
                'batch_count' => $run->batch_count + 1,
                'status' => $done ? 'completed' : 'processing',
                'completed_at' => $done ? now() : null,
            ])->save();
            if ($done) {
                $this->audit->school($school, 'leave.accrual_run.completed', actor: $actor, subject: $run, metadata: [
                    'leaveTypeId' => $run->leave_type_id, 'leaveYearId' => $run->leave_year_id, 'periodStart' => $periodStart,
                    'batchCount' => $run->batch_count, 'outcomes' => $this->outcomeCounts($school, $run->id),
                ]);
            }

            return $run;
        }));
    }

    /**
     * The run with its reconciled counts and one page of its items (filterable by outcome).
     *
     * @return array<string, mixed>
     */
    public function show(School $school, string $runId, User $actor, ?string $outcome = null, ?string $after = null, int $perPage = 50): array
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::VIEW, $school);
        $perPage = max(1, min($perPage, 200));

        return $this->context->withSchool($school, function () use ($school, $runId, $outcome, $after, $perPage) {
            $run = LeaveAccrualRun::query()->where('school_id', $school->id)->findOrFail($runId);
            $items = LeaveAccrualRunItem::query()->where('school_id', $school->id)->where('accrual_run_id', $run->id)
                ->when($outcome !== null, fn ($q) => $q->where('outcome', $outcome))
                ->when($after !== null, fn ($q) => $q->where('employment_record_id', '>', $after))
                ->orderBy('employment_record_id')->limit($perPage)->get();
            $labels = collect($this->roster->labels($school, $items->pluck('employment_record_id')->all()))->keyBy('employmentRecordId');
            $counts = $this->outcomeCounts($school, $run->id);

            return self::runSummary($run) + [
                'counts' => $counts,
                'examined' => array_sum($counts),
                'items' => $items->map(fn (LeaveAccrualRunItem $i) => [
                    'employmentRecordId' => $i->employment_record_id, 'employeeNumber' => $labels->get($i->employment_record_id)['employeeNumber'] ?? null,
                    'fullName' => $labels->get($i->employment_record_id)['fullName'] ?? null, 'outcome' => $i->outcome, 'units' => $i->units,
                    'eligibilityDrift' => $i->eligibility_drift,
                ])->values()->all(),
                'nextAfter' => $items->count() === $perPage ? $items->last()?->employment_record_id : null,
            ];
        });
    }

    /** @return list<array<string, mixed>> the runs of one leave type and year, newest month first */
    public function runs(School $school, string $leaveTypeId, string $leaveYearId, User $actor): array
    {
        $this->authorizeCapabilityFor($actor, LeaveCapabilities::VIEW, $school);

        return $this->context->withSchool($school, fn () => LeaveAccrualRun::query()->where('school_id', $school->id)
            ->where('leave_type_id', $leaveTypeId)->where('leave_year_id', $leaveYearId)
            ->orderByDesc('period_start')->orderByDesc('started_at')->limit(200)->get()
            ->map(fn (LeaveAccrualRun $r) => self::runSummary($r))->values()->all());
    }

    /** @return array<string, mixed> */
    public static function runSummary(LeaveAccrualRun $r): array
    {
        return [
            'id' => $r->id, 'leaveTypeId' => $r->leave_type_id, 'leaveYearId' => $r->leave_year_id, 'periodStart' => $r->period_start->toDateString(),
            'status' => $r->status, 'batchCount' => $r->batch_count, 'failureCode' => $r->failure_code,
            'startedAt' => $r->started_at->toIso8601String(), 'completedAt' => $r->completed_at?->toIso8601String(),
        ];
    }

    /** @return array<string, int> every outcome (zero included), from the run's items */
    private function outcomeCounts(School $school, string $runId): array
    {
        $counts = self::emptyCounts();
        foreach (LeaveAccrualRunItem::query()->where('school_id', $school->id)->where('accrual_run_id', $runId)
            ->groupBy('outcome')->selectRaw('outcome, count(*) as c')->get() as $row) {
            $counts[(string) $row->getAttribute('outcome')] = (int) $row->getAttribute('c');
        }

        return $counts;
    }

    /** @return array<string, int> */
    private static function emptyCounts(): array
    {
        return array_fill_keys(array_map(fn (LeaveAccrualOutcome $o) => $o->value, LeaveAccrualOutcome::cases()), 0);
    }

    private function markFailed(School $school, string $runId, string $code, User $actor): void
    {
        $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $runId, $code, $actor) {
            $run = LeaveAccrualRun::query()->where('school_id', $school->id)->lockForUpdate()->find($runId);
            if ($run !== null && $run->status === 'processing') {
                $run->forceFill(['status' => 'failed', 'failure_code' => substr($code, 0, 64)])->save();
                $this->audit->school($school, 'leave.accrual_run.failed', actor: $actor, subject: $run, metadata: ['failureCode' => $run->failure_code, 'batchCount' => $run->batch_count]);
            }
        }));
    }

    /** @return array{0: LeaveType, 1: LeaveYear} */
    private function typeAndYear(School $school, string $leaveTypeId, string $leaveYearId): array
    {
        $type = LeaveType::query()->where('school_id', $school->id)->findOrFail($leaveTypeId);
        if (! $type->tracks_balance) {
            throw LeaveException::invalid('LEAVE_TYPE_UNTRACKED', 'This leave type does not track a balance.');
        }
        if ($type->status !== 'active') {
            throw LeaveException::conflict('LEAVE_TYPE_INACTIVE', 'This leave type is inactive.');
        }

        return [$type, LeaveYear::query()->where('school_id', $school->id)->findOrFail($leaveYearId)];
    }

    /**
     * The month starting $periodStart, which must be a first day inside the
     * leave year (every leave year is whole months: ADR 0065 §22.1a).
     *
     * @return array{start: string, end: string}
     */
    private function period(LeaveYear $year, string $periodStart): array
    {
        try {
            $start = CarbonImmutable::createFromFormat('!Y-m-d', $periodStart);
        } catch (Throwable) {
            $start = null;
        }
        if (! $start instanceof CarbonImmutable || $start->format('Y-m-d') !== $periodStart || $start->day !== 1
            || $periodStart < $year->starts_on->toDateString() || $start->endOfMonth()->toDateString() > $year->ends_on->toDateString()) {
            throw LeaveException::invalid('LEAVE_ACCRUAL_PERIOD_INVALID', 'Choose the first day of a month inside the leave year.');
        }

        return ['start' => $periodStart, 'end' => $start->endOfMonth()->toDateString()];
    }
}
