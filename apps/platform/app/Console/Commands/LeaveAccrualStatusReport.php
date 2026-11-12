<?php

namespace App\Console\Commands;

use App\Domain\Leave\Application\Accrual\LeaveAccrualMonthStatusReader;
use App\Domain\Leave\Infrastructure\LeaveType;
use App\Domain\Leave\Infrastructure\LeaveYear;
use App\Models\School;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * HR.R1-C32 (WO-9, D-3 option 3, HR owner 2026-11-12): the read-only operator report of monthly accrual status.
 * For each School (one at a time, in its own context), each leave type with a monthly-accrual policy and every Leave
 * Year that has started and is not closed (the previous year stays until it is closed: its last month in arrears is
 * only due the day the next year starts, review MEDIUM-1), one line per month: the status
 * LeaveAccrualMonthStatusReader answers. A year can be closed the day after it ends, the day its last arrears month
 * becomes due (delta review LOW-2), so the most recently ended CLOSED year is reported too, but only its months that
 * need attention (missed, incomplete, failed, running, unknown), marked `year_closed`. A School whose evidence cannot
 * be read prints one `unknown` line naming what failed.
 *
 * Reads only: it posts nothing, re-runs nothing, sends nothing and writes no audit. It prints ids, codes, statuses
 * and outcome counts, never a name (rule 63). A suspended or otherwise non-active School is included and marked
 * (a missed month there is still a fact; resuming the School is a separate decision).
 */
class LeaveAccrualStatusReport extends Command
{
    protected $signature = 'leave:accrual-status {--school=* : Limit the report to these School ids}';

    protected $description = 'Read-only: the monthly accrual status of each School, leave type and month of every started Leave Year not yet closed (and the attention months of the last closed one).';

    public function handle(TenantContext $context, LeaveAccrualMonthStatusReader $reader): int
    {
        $rows = [];
        $totals = array_fill_keys(LeaveAccrualMonthStatusReader::STATUSES, 0);
        $only = array_values(array_filter((array) $this->option('school')));
        foreach ($only as $id) {
            if (! Str::isUuid((string) $id)) {
                $this->error("Not a School id: {$id}");

                return self::INVALID;
            }
        }

        School::query()->when($only !== [], fn ($q) => $q->whereIn('id', $only))->orderBy('id')
            ->each(function (School $school) use ($context, $reader, &$rows, &$totals): void {
                try {
                    $today = SchoolTimezone::today($school);
                    [$types, $years] = $context->withSchool($school, fn () => [
                        LeaveType::query()->where('school_id', $school->id)->whereIn('id', DB::table('leave_policies')
                            ->where('school_id', $school->id)->where('entitlement_method', 'monthly')->select('leave_type_id'))->orderBy('code')->get(['id', 'code']),
                        LeaveYear::query()->where('school_id', $school->id)->where('starts_on', '<=', $today)
                            ->whereNotIn('id', DB::table('leave_year_closes')->where('school_id', $school->id)->select('leave_year_id'))
                            ->orderBy('starts_on')->get(['id', 'starts_on']),
                    ]);
                    $lastClosed = $context->withSchool($school, fn () => LeaveYear::query()->where('school_id', $school->id)->where('ends_on', '<', $today)
                        ->whereIn('id', DB::table('leave_year_closes')->where('school_id', $school->id)->select('leave_year_id'))
                        ->orderByDesc('ends_on')->first(['id', 'starts_on']));
                    if ($lastClosed !== null) {
                        $years = $years->push($lastClosed);
                    }
                    $current = '-';
                    foreach ($types as $type) {
                        foreach ($years as $year) {
                            $current = "{$type->code} {$year->starts_on->toDateString()}";
                            foreach ($reader->yearFor($school, $type->id, $year->id) as $month) {
                                if ($month['blockedBy'] === 'year_closed' && in_array($month['status'], [LeaveAccrualMonthStatusReader::COMPLETE, LeaveAccrualMonthStatusReader::NOTHING_TO_CREDIT, LeaveAccrualMonthStatusReader::NOT_DUE], true)) {
                                    continue;
                                }
                                $totals[$month['status']]++;
                                $counts = $month['counts'];
                                $rows[] = [
                                    $school->id, $school->status, $type->code, $year->starts_on->toDateString(), $month['periodStart'],
                                    $month['status'], $month['reason'].($month['blockedBy'] ? " ({$month['blockedBy']})" : ''),
                                    $counts === null ? '-' : "{$counts['credited']}/{$counts['already_credited']}/{$counts['not_due']}",
                                ];
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    // One School's unreadable evidence never stops the report (review LOW-1): a line, never a guess.
                    $totals[LeaveAccrualMonthStatusReader::UNKNOWN]++;
                    $rows[] = [$school->id, $school->status, $current ?? '-', '-', '-', LeaveAccrualMonthStatusReader::UNKNOWN, 'school_unreadable: '.$e::class, '-'];
                }
            });

        $this->table(['School', 'School status', 'Leave type', 'Year from', 'Month', 'Status', 'Reason', 'Would credit/credited/not due'], $rows);
        $this->line('Totals: '.implode(', ', array_map(fn (string $s, int $n) => "{$s}={$n}", array_keys($totals), $totals)));
        $this->line('Read-only: nothing was posted, re-run or sent. A missed or incomplete month is run again from the accrual workspace.');

        return self::SUCCESS;
    }
}
