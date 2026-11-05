<?php

namespace App\Support\HrReports;

use App\Domain\HR\Application\Reports\ReportingLinesReport;
use App\Domain\HR\Application\Reports\StaffDetailsReport;
use App\Domain\HR\Application\Reports\StaffMovementReport;
use App\Domain\HR\Application\Reports\WorkAnniversaryReport;
use App\Domain\Leave\Application\Reports\LeaveBalanceReport;
use App\Domain\StaffAttendance\Application\Reports\StaffAbsenteeReport;
use App\Domain\StaffAttendance\Application\Reports\StaffAttendanceCorrectionReport;
use App\Domain\WorkforceTasks\Application\Reports\TaskGenerationRunReport;
use App\Domain\WorkforceTasks\Application\Reports\TaskOccurrenceReport;
use App\Domain\WorkforceTasks\Application\Reports\TaskStatusSummaryReport;
use App\Support\HrReports\HrReportDefinition as D;
use Illuminate\Contracts\Container\Container;

/**
 * HR.14 (owner decisions 2026-11-05): the ONE closed catalogue of HR
 * operational reports, mirroring StudentReportRegistry. A report exists only
 * if it is listed here; keys are unique and never reused.
 *
 * - RENDERED reports are HrReport read models in their owning module, served
 *   by the shared HR report page.
 * - Existing dedicated HR pages are GOVERNED entries: the hub lists them for
 *   a viewer holding their authority; they keep their own filters, columns
 *   and checks.
 *
 * Operational listing and counts of recorded facts only: never a person
 * count, headcount, rate, percentage, trend or demographic breakdown
 * (Analytics, ADR 0040: D20-07/08/09/25/35 stay GATE), never a birthday
 * (D20-05 not adopted), never a clock, device or location fact (HRX-L2 /
 * OD-HR-14), never another domain's data the owner has not exposed (staff
 * children, Inventory custody, fees, pay -- XDOM). Browser-only: no export,
 * print or saved view (owner decision 2026-11-05).
 */
final class HrReportRegistry
{
    /** @var list<class-string<HrReport>> */
    public const array RENDERED = [
        StaffDetailsReport::class,
        StaffMovementReport::class,
        WorkAnniversaryReport::class,
        ReportingLinesReport::class,
        LeaveBalanceReport::class,
        StaffAbsenteeReport::class,
        StaffAttendanceCorrectionReport::class,
        TaskOccurrenceReport::class,
        TaskStatusSummaryReport::class,
        TaskGenerationRunReport::class,
    ];

    /** @var array<string, D>|null */
    private ?array $definitions = null;

    /** @var array<string, HrReport> */
    private array $reports = [];

    public function __construct(private readonly Container $container) {}

    /** @return array<string, D> every report, in hub order */
    public function definitions(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }
        $definitions = [];
        foreach ([...self::pages(), ...array_map(fn (string $class) => $this->report($class)->definition(), self::RENDERED)] as $definition) {
            if (isset($definitions[$definition->key])) {
                throw new \LogicException("HR report {$definition->key} is registered twice.");
            }
            $definitions[$definition->key] = $definition;
        }
        $order = array_flip(array_keys(D::CATEGORIES));
        uasort($definitions, fn (D $a, D $b) => $order[$a->category] <=> $order[$b->category]);

        return $this->definitions = $definitions;
    }

    public function find(string $key): ?D
    {
        return $this->definitions()[$key] ?? null;
    }

    public function rendered(string $key): ?HrReport
    {
        foreach (self::RENDERED as $class) {
            $report = $this->report($class);
            if ($report->definition()->key === $key) {
                return $report;
            }
        }

        return null;
    }

    /** @param  class-string<HrReport>  $class */
    private function report(string $class): HrReport
    {
        return $this->reports[$class] ??= $this->container->make($class);
    }

    /**
     * The existing dedicated HR pages this catalogue governs.
     *
     * @return list<D>
     */
    public static function pages(): array
    {
        return [
            new D('hr.staff_directory', 'Staff directory', 'Current staff with today\'s placement and reporting line; filter by employment state, joining period, Campus, Department and Designation.',
                'workforce', 'HR', ['hr.employees.view'], 'app.hr.employees.index', D::CURRENT, D::INTERNAL_DIRECTORY, ['D20-01', 'D20-03', 'D20-04']),
            new D('hr.separation_cases', 'Separation cases', 'Resignation, retirement and other separation cases with their status, last working day and clearance.',
                'lifecycle', 'HR', ['hr.separations.view'], 'app.hr.separations.index', D::CURRENT, D::HR_CONFIDENTIAL, ['D18-10', 'D18-13', 'D18-15', 'D18-17']),
            new D('hr.leave_requests', 'Leave requests', 'Leave requests with their status and decisions.',
                'leave', 'Leave', ['hr.leave.view'], 'app.leave.requests', D::PERIOD, D::EMPLOYMENT_OPERATIONS, ['D20-21']),
            new D('hr.leave_monthly', 'Month-wise leave', 'Approved leave taken in a month, per employment and leave type.',
                'leave', 'Leave', ['hr.leave.view'], 'app.leave.monthly', D::PERIOD, D::EMPLOYMENT_OPERATIONS, ['D20-22']),
            new D('hr.leave_accruals', 'Leave accruals', 'The monthly accrual grid of one leave type and year, with each account\'s balance.',
                'leave', 'Leave', ['hr.leave.view'], 'app.leave.accruals', D::PERIOD, D::EMPLOYMENT_OPERATIONS, ['D11-16', 'D20-24']),
            new D('hr.attendance_register', 'Daily staff attendance', 'The daily register: each employment\'s recorded halves, leave, holidays and off days.',
                'attendance', 'Staff Attendance', ['hr.staff_attendance.view'], 'app.staff-attendance.register', D::CURRENT, D::EMPLOYMENT_OPERATIONS, ['D20-12', 'D20-14', 'D20-15', 'D20-16', 'D20-17']),
            new D('hr.attendance_monthly', 'Monthly staff attendance', 'A month\'s half-day counts per employment, by the register\'s states.',
                'attendance', 'Staff Attendance', ['hr.staff_attendance.view'], 'app.staff-attendance.monthly', D::PERIOD, D::EMPLOYMENT_OPERATIONS, ['D20-13']),
            new D('hr.task_monitor', 'Staff task monitor', 'Open and overdue staff tasks, server-filtered, with counts by status.',
                'tasks', 'WorkforceTasks', ['hr.tasks.view'], 'app.hr.tasks.index', D::CURRENT, D::EMPLOYMENT_OPERATIONS, ['D19-26']),
        ];
    }
}
