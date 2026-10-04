<?php

namespace Tests\Feature\Retention\Concerns;

use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Leave\Application\LeavePolicyAssignmentService;
use App\Domain\Leave\Application\LeaveRequestService;
use App\Domain\Leave\Application\LeaveYearCloseService;
use App\Domain\Leave\Application\LeaveYearService;
use App\Models\School;
use App\Models\User;
use App\Support\Retention\RetentionExpiry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\StaffAttendance\Concerns\CreatesStaffAttendanceFixtures;

/**
 * HRX.6: Leave and Staff Attendance evidence written in 2015-16 -- more than
 * 8 calendar years before the real today, because the database floor
 * (`retention_assert_payroll_employee_floor`) measures from the real
 * `now()`, never from PHP's travelled clock.
 *
 * One School with leave configuration (April leave year 2015-16, closed in
 * April 2016 into an opened 2016-17), the staff working week and an
 * attendance clerk; each leaver has an EmploymentRecord from 2014 and an
 * assignment reporting to a manager.
 */
trait CreatesHrxRetentionFixtures
{
    use CreatesStaffAttendanceFixtures;

    /** Every per-Employee HRX evidence table, by its Employee/employment column. */
    protected const HRX_EVIDENCE = [
        'leave_policy_assignments', 'leave_ledger_entries', 'leave_requests', 'leave_request_days', 'leave_decisions',
        'leave_year_close_items', 'leave_year_close_reconciliations', 'staff_attendance_records', 'staff_attendance_corrections',
    ];

    /** School-lifetime configuration the purge must never touch. */
    protected const HRX_CONFIGURATION = [
        'leave_settings', 'leave_years', 'leave_year_start_changes', 'leave_types', 'leave_policies',
        'staff_working_weekdays', 'staff_holidays', 'leave_allocation_runs', 'leave_year_closes',
    ];

    protected function at(string $moment): void
    {
        $this->travelTo(Carbon::parse($moment, 'UTC'));
    }

    /** @return array<string, mixed> leave configuration, admin, clerk and one manager of 2015 */
    protected function pastWorld(?School $school = null): array
    {
        $this->at('2015-04-06 10:00:00');
        $school ??= $this->createSchool();
        // Committed-fixture tests (the retention identity's connection cannot see an open test transaction) track it for cleanup.
        if (property_exists($this, 'schools')) {
            $this->schools[] = $school;
        }
        $admin = $this->createUserWithCapabilities($school, ['hr.leave.configure', 'hr.leave.view', 'hr.leave.manage']);
        $type = $this->leaveType($school, $admin);
        $policy = $this->leavePolicy($school, $type, $admin);
        $year = app(LeaveYearService::class)->open($school, '2015-06-01', $admin);
        $this->workingWeek($school, $admin);
        $clerk = $this->attendanceAdmin($school);
        $w = compact('school', 'admin', 'type', 'policy', 'year', 'clerk');
        $w['manager'] = $this->pastStaff($w, ['hr.leave.approve']);
        $this->travelBack();

        return $w;
    }

    /**
     * A 2015 staff member: an Employee (linked to a User only with
     * capabilities), an EmploymentRecord from 2014 with the policy
     * assigned, and a primary assignment.
     *
     * @param  list<string>|null  $capabilities  null = no User
     * @return array{user: ?User, employment: EmploymentRecord, assignment: EmployeeAssignment}
     */
    protected function pastStaff(array $w, ?array $capabilities = null): array
    {
        $user = $capabilities === null ? null : $this->createUserWithCapabilities($w['school'], $capabilities);
        $employee = $this->createEmployee($w['school'], $user === null ? [] : ['user_id' => $user->id]);
        $employment = $this->createEmploymentRecord($employee, ['status' => 'active', 'starts_on' => '2014-01-01', 'ends_on' => null]);
        $assignment = $this->createEmployeeAssignment($employment, $this->createPosition($w['school']), ['starts_on' => '2014-01-01', 'ends_on' => null, 'is_primary' => true]);
        app(LeavePolicyAssignmentService::class)->assign($w['school'], $employment->id, $w['policy']->id, '2015-04-01', null, $w['admin']);

        return compact('user', 'employment', 'assignment');
    }

    /**
     * Leavers with the full HRX causal chain, all written in 2015-16:
     * allocation, two approved requests (one by the manager), a withdrawn
     * and a rejected one, an attendance record with a correction, the
     * 2015-16 close (carry + lapse), and a cancellation after the close
     * (reversal + reconciliation). Each is then separated on its date
     * (null: still current).
     *
     * @param  array<string, ?string>  $separations  key => separation date
     * @return array<string, array{employment: EmploymentRecord, employeeId: string, assignment: EmployeeAssignment}>
     */
    protected function pastLeavers(array &$w, array $separations): array
    {
        $requests = app(LeaveRequestService::class);
        $leavers = [];
        $cancel = [];
        foreach (array_keys($separations) as $key) {
            $this->at('2015-04-06 10:00:00');
            $leaver = $this->pastStaff($w);
            $this->reportTo($leaver['assignment'], $w['manager']['assignment']);
            $ctx = ['school' => $w['school'], 'admin' => $w['admin'], 'type' => $w['type'], 'year' => $w['year'], 'employment' => $leaver['employment'], 'clerk' => $w['clerk']];
            $this->allocate($ctx, 16);

            $this->at('2015-06-20 10:00:00');
            $record = $this->recordAttendance($ctx, '2015-06-15', 'present', 'present');
            $this->correctAttendance($ctx, $record, 'absent', 'present');

            $this->at('2015-07-01 10:00:00');
            $cancel[] = $requests->approve($w['school'], $this->submitLeave($ctx, '2015-07-06', '2015-07-07')->id, $w['admin'])->id;
            $requests->approveAsManager($w['school'], $this->submitLeave($ctx, '2015-07-13', '2015-07-14')->id, $w['manager']['user']);
            $requests->withdraw($w['school'], $this->submitLeave($ctx, '2015-07-20', '2015-07-20')->id, 'plans_changed', $w['admin']);
            $requests->reject($w['school'], $this->submitLeave($ctx, '2015-07-27', '2015-07-27')->id, 'staffing_need', $w['admin']);

            $leavers[$key] = ['employment' => $leaver['employment'], 'employeeId' => $leaver['employment']->employee_id, 'assignment' => $leaver['assignment']];
        }

        $this->at('2016-04-10 10:00:00');
        app(LeaveYearService::class)->open($w['school'], '2016-04-01', $w['admin']);
        app(LeaveYearCloseService::class)->execute($w['school'], $w['year']->id, $w['admin']);
        foreach ($cancel as $id) {
            $requests->cancel($w['school'], $id, 'plans_changed', $w['admin']);
        }

        foreach ($separations as $key => $separatedOn) {
            if ($separatedOn !== null) {
                $this->inSchool($w['school'], fn () => DB::table('employment_records')->where('id', $leavers[$key]['employment']->id)->update(['status' => 'separated', 'ends_on' => $separatedOn]));
            }
        }
        $this->travelBack();

        return $leavers;
    }

    /** @return array{employment: EmploymentRecord, employeeId: string, assignment: EmployeeAssignment} */
    protected function pastLeaver(array &$w, ?string $separatedOn = '2016-04-30'): array
    {
        return $this->pastLeavers($w, ['leaver' => $separatedOn])['leaver'];
    }

    /**
     * Runs $statement as the authorized retention identity (the migration /
     * owner connection) inside $school's tenant context, in its own
     * transaction: its result, or the database's refusal message.
     */
    protected function asRetention(School $school, callable $statement): mixed
    {
        try {
            return DB::usingConnection(RetentionExpiry::PRIVILEGED_CONNECTION, fn () => $this->inSchool($school, fn () => DB::transaction($statement)));
        } catch (QueryException $e) {
            return $e->getMessage();
        }
    }

    /** @return array<string, int> table => the leaver's rows */
    protected function hrxRows(School $school, string $employeeId): array
    {
        return $this->inSchool($school, function () use ($employeeId): array {
            $employments = DB::table('employment_records')->where('employee_id', $employeeId)->pluck('id')->all();
            $requests = DB::table('leave_requests')->where('employee_id', $employeeId)->pluck('id')->all();
            $items = DB::table('leave_year_close_items')->whereIn('employment_record_id', $employments)->pluck('id')->all();
            $records = DB::table('staff_attendance_records')->where('employee_id', $employeeId)->pluck('id')->all();

            return [
                'leave_policy_assignments' => DB::table('leave_policy_assignments')->whereIn('employment_record_id', $employments)->count(),
                'leave_ledger_entries' => DB::table('leave_ledger_entries')->whereIn('employment_record_id', $employments)->count(),
                'leave_requests' => count($requests),
                'leave_request_days' => DB::table('leave_request_days')->whereIn('leave_request_id', $requests)->count(),
                'leave_decisions' => DB::table('leave_decisions')->whereIn('leave_request_id', $requests)->count(),
                'leave_year_close_items' => count($items),
                'leave_year_close_reconciliations' => DB::table('leave_year_close_reconciliations')->whereIn('year_close_item_id', $items)->count(),
                'staff_attendance_records' => count($records),
                'staff_attendance_corrections' => DB::table('staff_attendance_corrections')->whereIn('staff_attendance_record_id', $records)->count(),
            ];
        });
    }

    /** @return array<string, int> table => the School's rows */
    protected function configurationRows(School $school): array
    {
        return $this->inSchool($school, fn (): array => collect(self::HRX_CONFIGURATION)->mapWithKeys(fn (string $t) => [$t => DB::table($t)->where('school_id', $school->id)->count()])->all());
    }
}
