<?php

namespace App\Domain\StaffAttendance\Application\Payroll;

use App\Domain\HR\Application\EmploymentRoster;
use App\Domain\Leave\Application\Calendar\CalendarDay;
use App\Domain\Leave\Application\Calendar\StaffCalendarHistory;
use App\Domain\Leave\Application\LeaveCoverageReader;
use App\Domain\Leave\Application\LeaveLocks;
use App\Domain\Leave\Application\WorkingDayCalculator;
use App\Domain\StaffAttendance\Infrastructure\StaffAttendanceRecord;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * HRX.5 (ADR 0065 §9, §26.3): THE one HRX -> Payroll read contract. Payroll
 * calls it; HRX never depends on Payroll, and Payroll never reads a Leave or
 * Staff Attendance table.
 *
 * For one EmploymentRecord and one payroll period it returns
 * PayrollAbsenceEvidence: every half-day of the period that lies inside the
 * EmploymentRecord's own dates (HR's EmploymentRoster::span()), classified
 * once by HRX.3's precedence --
 *   approved leave (paid / unpaid by the leave type's frozen `is_paid`)
 *   > recorded present / absent
 *   > holiday / off-day (today's staff calendar)
 *   > unrecorded working time
 * -- so a recorded absence under approved leave counts once, as leave, with
 * the underlying record kept in its source facts. Holidays, off-days and
 * unrecorded halves are never absence; unrecorded is never present or absent.
 *
 * It is evidence only. Nothing here says non-payable, deductible or NCP:
 * those need a validated payroll policy and EPFO mapping (HRX-L4, open).
 * Units are integer half-days; nothing is rounded into days.
 *
 * - `read()`: fresh, no locks (comparisons, screens).
 * - `captureForPayroll()`: inside Payroll's transaction; takes every
 *   employment's `hrx.staff_employment` lock EXCLUSIVELY (sorted), then the
 *   calendar lock shared, so each employment's evidence is read wholly
 *   before or wholly after any concurrent HRX write (ADR 0065 §26.9).
 */
class PayrollAbsenceEvidenceReader
{
    /**
     * HR.R1-C22 (WO-5): v2. A half before the School's calendar history start is `calendar_not_recorded` with a
     * null `working`, and each half names the calendar version it was read from. A v1 snapshot is never compared
     * with v2 evidence (`PayrollHrxInputReadService`): its fingerprint is of another contract.
     */
    public const CONTRACT_VERSION = 'hrx_payroll_input.v2';

    public function __construct(
        private readonly TenantContext $context,
        private readonly EmploymentRoster $roster,
        private readonly StaffCalendarHistory $calendar,
        private readonly LeaveCoverageReader $leave,
    ) {}

    public function read(School $school, string $employmentRecordId, string $periodStartsOn, string $periodEndsOn): PayrollAbsenceEvidence
    {
        self::requirePeriod($periodStartsOn, $periodEndsOn);

        return $this->build($school, $employmentRecordId, $periodStartsOn, $periodEndsOn);
    }

    /**
     * @param  list<string>  $employmentRecordIds
     * @return array<string, PayrollAbsenceEvidence> employment record id => evidence
     */
    public function captureForPayroll(School $school, array $employmentRecordIds, string $periodStartsOn, string $periodEndsOn): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('PayrollAbsenceEvidenceReader::captureForPayroll() must run inside the payroll transaction.');
        }
        self::requirePeriod($periodStartsOn, $periodEndsOn);
        $ids = array_values(array_unique($employmentRecordIds));
        sort($ids, SORT_STRING);
        foreach ($ids as $id) {
            LeaveLocks::staffEmployment($school, $id, shared: false);
        }
        LeaveLocks::calendar($school, shared: true);

        $evidence = [];
        foreach ($ids as $id) {
            $evidence[$id] = $this->build($school, $id, $periodStartsOn, $periodEndsOn);
        }

        return $evidence;
    }

    private function build(School $school, string $employmentRecordId, string $from, string $to): PayrollAbsenceEvidence
    {
        $span = $this->roster->span($school, $employmentRecordId) ?? throw new InvalidArgumentException('Unknown EmploymentRecord for this School.');
        $coveredFrom = max($from, $span['startsOn']);
        $coveredTo = $span['endsOn'] === null ? $to : min($to, $span['endsOn']);
        $covered = $coveredFrom <= $coveredTo;

        $halves = [];
        $calendarConfigured = true;
        $calendarNotRecorded = false;
        if ($covered) {
            // HR.R1-C22 (WO-5): each date on the calendar in force on that date; before the School's history start
            // the day is `calendar_not_recorded` (never today's calendar), and the evidence is incomplete.
            $days = $this->calendar->range($school, $coveredFrom, $coveredTo);
            $calendarConfigured = reset($days)->status !== CalendarDay::NOT_CONFIGURED;
            $recordedDays = array_filter($days, fn (CalendarDay $d) => $d->recorded());
            $calculator = $calendarConfigured ? WorkingDayCalculator::forDays(
                array_map(fn (CalendarDay $d) => (string) $d->weekPortion, $recordedDays),
                array_filter(array_map(fn (CalendarDay $d) => $d->holiday['portion'] ?? null, $recordedDays)),
            ) : null;
            $coverage = $this->leave->approvedCoverage($school, [$employmentRecordId], $coveredFrom, $coveredTo)[$employmentRecordId] ?? [];
            $records = $this->context->withSchool($school, fn () => StaffAttendanceRecord::query()->where('school_id', $school->id)
                ->where('employment_record_id', $employmentRecordId)->whereBetween('attendance_date', [$coveredFrom, $coveredTo])->get()
                ->keyBy(fn (StaffAttendanceRecord $r) => $r->attendance_date->toDateString()));

            for ($d = CarbonImmutable::createFromFormat('!Y-m-d', $coveredFrom); $d->toDateString() <= $coveredTo; $d = $d->addDay()) {
                $date = $d->toDateString();
                $recordedDay = $days[$date]->recorded();
                $calendarNotRecorded = $calendarNotRecorded || ($calendarConfigured && ! $recordedDay);
                $today = $recordedDay ? $calculator?->halves($date) : null;
                $record = $records[$date] ?? null;
                foreach ([1, 2] as $half) {
                    $leave = $coverage[$date][$half] ?? null;
                    $recorded = $record?->halves()[$half];
                    $calendar = $today[$half] ?? null;
                    $halves[] = [
                        'date' => $date,
                        'half' => $half,
                        'class' => match (true) {
                            $leave !== null => $leave['isPaid'] ? 'leave_paid' : 'leave_unpaid',
                            $recorded !== null => $recorded,
                            $calendar === null => $calendarConfigured ? 'calendar_not_recorded' : 'calendar_unknown',
                            $calendar === 'holiday' => 'holiday',
                            $calendar === 'off' => 'off_day',
                            default => 'unrecorded',
                        },
                        'working' => $calendar === null ? null : $calendar === 'working',
                        'leaveRequestId' => $leave['leaveRequestId'] ?? null,
                        'isPaid' => $leave['isPaid'] ?? null,
                        'staffAttendanceRecordId' => $recorded !== null ? $record->id : null,
                        'version' => $recorded !== null ? $record->version : null,
                        'recorded' => $recorded,
                        // Provenance only, outside the fingerprint: the class above already changes when the calendar does.
                        'calendarVersionId' => $days[$date]->versionId,
                    ];
                }
            }
        }

        $count = fn (string $class) => count(array_filter($halves, fn (array $h) => $h['class'] === $class));
        $unresolved = $count('unrecorded');
        $reasons = array_values(array_filter([
            $covered && ! $calendarConfigured ? 'calendar_not_configured' : null,
            $calendarNotRecorded ? 'calendar_not_recorded' : null,
            $unresolved > 0 ? 'unrecorded_working_time' : null,
        ]));

        return new PayrollAbsenceEvidence(
            contractVersion: self::CONTRACT_VERSION,
            employmentRecordId: $employmentRecordId,
            periodStartsOn: $from,
            periodEndsOn: $to,
            coveredFrom: $covered ? $coveredFrom : null,
            coveredTo: $covered ? $coveredTo : null,
            calendarConfigured: $calendarConfigured,
            requiredWorkingHalfUnits: $calendarConfigured && ! $calendarNotRecorded ? count(array_filter($halves, fn (array $h) => $h['working'] === true)) : null,
            approvedPaidLeaveHalfUnits: $count('leave_paid'),
            approvedUnpaidLeaveHalfUnits: $count('leave_unpaid'),
            recordedAbsenceHalfUnits: $count('absent'),
            recordedPresenceHalfUnits: $count('present'),
            unresolvedWorkingHalfUnits: $unresolved,
            completeness: $reasons === [] ? PayrollAbsenceEvidence::COMPLETE : PayrollAbsenceEvidence::INPUT_INCOMPLETE,
            incompleteReasons: $reasons,
            halves: $halves,
            fingerprint: self::fingerprint($employmentRecordId, $from, $to, $covered ? $coveredFrom : null, $covered ? $coveredTo : null, $calendarConfigured, $halves),
        );
    }

    /**
     * Canonical, presentation-free input of the fingerprint (ADR 0065 §26.7).
     *
     * @param  list<array<string, mixed>>  $halves
     */
    private static function fingerprint(string $employmentRecordId, string $from, string $to, ?string $coveredFrom, ?string $coveredTo, bool $calendarConfigured, array $halves): string
    {
        $canonical = [
            self::CONTRACT_VERSION, $employmentRecordId, $from, $to, $coveredFrom, $coveredTo, $calendarConfigured,
            array_map(fn (array $h) => [$h['date'], $h['half'], $h['class'], $h['working'], $h['leaveRequestId'], $h['isPaid'], $h['staffAttendanceRecordId'], $h['version'], $h['recorded']], $halves),
        ];

        return hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private static function requirePeriod(string $from, string $to): void
    {
        foreach ([$from, $to] as $date) {
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                throw new InvalidArgumentException('A payroll period is two Y-m-d dates.');
            }
        }
        if ($from > $to) {
            throw new InvalidArgumentException('A payroll period starts on or before it ends.');
        }
    }
}
