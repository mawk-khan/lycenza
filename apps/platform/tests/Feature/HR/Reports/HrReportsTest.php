<?php

namespace Tests\Feature\HR\Reports;

use App\Domain\HR\Application\Reporting\ReportingChangeReason;
use App\Domain\HR\Application\Reporting\ReportingRelationshipService;
use App\Domain\HR\Application\Workforce\PlacementChangeKind;
use App\Domain\HR\Application\Workforce\PlacementChangeReason;
use App\Domain\HR\Application\Workforce\PlacementChangeService;
use App\Domain\HR\Infrastructure\Department;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\Position;
use App\Domain\Leave\Application\Accrual\LeaveStatementReader;
use App\Domain\Leave\Application\LeaveLedgerService;
use App\Domain\Leave\Application\LeavePolicyAssignmentService;
use App\Domain\Leave\Application\LeaveRequestService;
use App\Domain\StaffAttendance\Application\StaffAttendanceService;
use App\Domain\WorkforceTasks\Application\TaskAssignmentService;
use App\Domain\WorkforceTasks\Application\TaskCatalogueService;
use App\Domain\WorkforceTasks\Application\TaskWorkflowService;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\HrReports\HrReportColumn;
use App\Support\HrReports\HrReportRegistry;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Leave\Concerns\CreatesLeaveFixtures;
use Tests\TestCase;

/**
 * HR.14 (owner decisions 2026-11-05): the HR report catalogue end to end --
 * discovery and execution authority (allow AND deny), declared filters and
 * server-decided columns (forgery refused), effective-dated history, the
 * one-ledger leave identity, attendance absence under approved leave, the
 * correction log, task occurrence counts that reconcile, generation
 * coverage, School isolation, and no write on any read.
 *
 * Today is Monday 2026-10-05 (School-local, Asia/Kolkata). Every name and
 * number is a synthetic test input.
 */
class HrReportsTest extends TestCase
{
    use CreatesLeaveFixtures;

    private School $school;

    private Campus $north;

    private Campus $south;

    private Department $teaching;

    private Department $office;

    private Position $teacher;

    private Position $coordinator;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-05 06:00:00');
        $this->school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        app(TenantContext::class)->set($this->school);
        $this->north = $this->createCampus($this->school, ['name' => 'North Campus']);
        $this->south = $this->createCampus($this->school, ['name' => 'South Campus']);
        $this->teaching = $this->createDepartment($this->school, ['name' => 'Teaching Dept', 'status' => 'active']);
        $this->office = $this->createDepartment($this->school, ['name' => 'Office Dept', 'status' => 'active']);
        $this->teacher = $this->createPosition($this->school, ['name' => 'Teacher Role', 'status' => 'active']);
        $this->coordinator = $this->createPosition($this->school, ['name' => 'Coordinator Role', 'status' => 'active']);
        $this->hr = $this->createUserWithCapabilities($this->school, [
            'hr.employees.view', 'hr.employees.manage', 'hr.employees.assignments.view', 'hr.employees.assignments.manage',
            'hr.leave.view', 'hr.leave.manage', 'hr.leave.configure', 'hr.staff_attendance.view', 'hr.staff_attendance.manage',
            'hr.tasks.view', 'hr.tasks.manage', 'hr.tasks.configure', 'hr.separations.view',
        ]);
    }

    /** @return array{0: EmploymentRecord, 1: EmployeeAssignment} */
    private function staff(string $name, string $number, string $starts = '2024-04-01', ?Campus $campus = null, ?Department $department = null, ?Position $position = null, array $employment = []): array
    {
        $employee = $this->createEmployee($this->school, ['full_name' => $name, 'employee_number' => $number]);
        $record = $this->createEmploymentRecord($employee, [...['starts_on' => $starts, 'ends_on' => null, 'status' => 'active', 'staff_type' => 'teaching'], ...$employment]);
        $assignment = $this->createEmployeeAssignment($record, $position ?? $this->teacher, [
            'campus_id' => ($campus ?? $this->north)->id, 'department_id' => ($department ?? $this->teaching)->id,
            'is_primary' => true, 'starts_on' => $starts, 'ends_on' => $record->ends_on?->toDateString(),
        ]);

        return [$record, $assignment];
    }

    /** Signs in and activates this School (the session-stored School selection, rule 20). */
    private function as(User $user, ?School $school = null): static
    {
        $this->actingAs($user)->post('/app/schools/'.($school ?? $this->school)->id.'/activate');

        return $this;
    }

    /** HR.16F: a refused parameter is shown ON the report page (200, `errors`), with the report rendered from its defaults. */
    private function assertReportError(string $key, string $url, User $as): void
    {
        $props = $this->as($as)->get($url)->assertOk()->viewData('page')['props'];
        $this->assertArrayHasKey($key, $props['errors'], "{$url}: expected an error for {$key}");
        $this->assertArrayHasKey('data', $props['rows'], 'the report still renders');
    }

    /** @return array<string, mixed> the Inertia props of a report page */
    private function report(string $key, array $query = [], ?User $as = null, ?School $in = null): array
    {
        return $this->as($as ?? $this->hr, $in)->get(route('app.hr.reports.show', ['report' => $key, ...$query], false))
            ->assertOk()->viewData('page')['props'];
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $key, array $query = [], ?User $as = null): array
    {
        return $this->report($key, $query, $as)['rows']['data'];
    }

    private function names(array $rows, string $column = 'employee'): array
    {
        return array_map(fn (array $r) => $r[$column]['name'] ?? null, $rows);
    }

    // ----------------------------------------------------------------- registry

    #[Test]
    public function the_catalogue_is_closed_and_every_report_names_its_owner_authority_and_reference(): void
    {
        $definitions = app(HrReportRegistry::class)->definitions();
        $this->assertCount(18, $definitions);
        foreach ($definitions as $key => $d) {
            $this->assertSame($key, $d->key);
            $this->assertNotSame([], $d->authority);
            $this->assertNotSame([], $d->reference);
            $this->assertNotNull(app('router')->getRoutes()->getByName($d->route), "{$key}: route {$d->route}");
            foreach ($d->authority as $capability) {
                $this->assertDoesNotMatchRegularExpression('/^(platform|group|analytics)\./', $capability, "{$key} never opens on platform, Group or Analytics authority");
            }
            foreach ($d->columns as $column) {
                $this->assertNotContains($column->key, HrReportColumn::PROHIBITED);
            }
        }
        // Gated families stay out of the catalogue.
        foreach (['headcount', 'birthday', 'biometric', 'late', 'gps', 'salary', 'kids', 'inventory'] as $word) {
            $this->assertSame([], array_filter(array_keys($definitions), fn (string $k) => str_contains($k, $word)), $word);
        }
        $this->expectException(\InvalidArgumentException::class);
        new HrReportColumn('date_of_birth', 'DOB');
    }

    #[Test]
    public function discovery_lists_only_reports_the_viewer_may_open_and_refuses_everyone_else(): void
    {
        $categories = $this->as($this->hr)->get('/app/hr/reports')->assertOk()->viewData('page')['props']['categories'];
        $this->assertCount(18, array_merge(...array_column($categories, 'reports')));

        $leaveOnly = $this->createUserWithCapabilities($this->school, ['hr.leave.view']);
        $listed = array_column(array_merge(...array_column($this->as($leaveOnly)->get('/app/hr/reports')->assertOk()->viewData('page')['props']['categories'], 'reports')), 'key');
        $this->assertSame(['hr.leave_requests', 'hr.leave_monthly', 'hr.leave_accruals', 'hr.leave_balances'], $listed);

        // Leave approval, team tasks and self service open nothing (HR.11, HR.13).
        foreach ([['hr.leave.approve'], ['hr.tasks.team'], ['hr.tasks.self', 'hr.leave.self', 'hr.staff_attendance.self'], []] as $capabilities) {
            $user = $this->createUserWithCapabilities($this->school, $capabilities);
            $this->as($user)->get('/app/hr/reports')->assertForbidden();
            $this->as($user)->get('/app/hr/reports/hr.staff_details')->assertForbidden();
        }
        // Direct URL without the report's own authority is refused.
        $this->as($leaveOnly)->get('/app/hr/reports/hr.staff_details')->assertForbidden();
        $this->as($leaveOnly)->get('/app/hr/reports/hr.task_occurrences')->assertForbidden();
        // Unknown keys and governed pages are not rendered here.
        $this->as($this->hr)->get('/app/hr/reports/hr.headcount')->assertNotFound();
        $this->as($this->hr)->get('/app/hr/reports/hr.staff_directory')->assertNotFound();
    }

    #[Test]
    public function a_campus_grant_of_an_adopted_key_is_never_whole_school_report_authority(): void
    {
        $user = $this->createUser();
        $membership = $this->createMembership($user, $this->school);
        LocalCatalogueFixtures::grantRole($membership, $this->createFixtureRole(['hr.employees.view', 'hr.employees.assignments.view']), $this->north->id);

        $this->as($user)->get('/app/hr/reports')->assertForbidden();
        $this->as($user)->get('/app/hr/reports/hr.staff_details')->assertForbidden();
    }

    #[Test]
    public function filters_sorts_and_columns_are_closed_and_validated_on_the_server(): void
    {
        $this->staff('Asha Rao', 'S-001');
        $this->assertReportError('sort', '/app/hr/reports/hr.staff_details?sort=e.full_name;drop', $this->hr);
        $this->assertReportError('staff_type', '/app/hr/reports/hr.staff_details?staff_type=admin', $this->hr);
        $this->assertReportError('department_id', '/app/hr/reports/hr.staff_details?department_id=not-a-uuid', $this->hr);
        $this->assertReportError('columns', '/app/hr/reports/hr.staff_details?columns[]=date_of_birth', $this->hr);
        $this->assertReportError('columns', '/app/hr/reports/hr.staff_details?columns[]=salary', $this->hr);
        $this->assertReportError('to', '/app/hr/reports/hr.staff_movements?from=2025-01-01&to=2026-10-05', $this->hr);
        $this->assertReportError('to', '/app/hr/reports/hr.staff_movements?from=2026-10-05&to=2026-10-01', $this->hr);

        // Undeclared parameters are ignored (they never change the query).
        $rows = $this->rows('hr.staff_details', ['school_id' => (string) $this->createSchool()->id, 'raw' => '1=1']);
        $this->assertSame(['Asha Rao'], $this->names($rows));

        // Search is literal: a wildcard matches nothing.
        $this->assertSame([], $this->rows('hr.staff_details', ['q' => '%']));
        $this->assertSame(['Asha Rao'], $this->names($this->rows('hr.staff_details', ['q' => 's-001'])));
    }

    // -------------------------------------------------------------- staff details

    #[Test]
    public function staff_details_answers_the_placement_that_applied_on_the_chosen_date(): void
    {
        [, $asha] = $this->staff('Asha Rao', 'S-001');
        $this->staff('Ben Das', 'S-002', campus: $this->south, department: $this->office);
        // Transfer Asha to South / Office, promoted to Coordinator, from 2026-09-01.
        app(PlacementChangeService::class)->change($asha, PlacementChangeKind::TRANSFER, '2026-09-01', PlacementChangeReason::STAFFING_REQUIREMENT, $this->coordinator, $this->fullHrActor($this->school), campus: $this->south, department: $this->office);

        $columns = ['employee', 'campus', 'department', 'position'];
        $before = collect($this->rows('hr.staff_details', ['as_of' => '2026-08-15', 'columns' => $columns]))->keyBy(fn ($r) => $r['employee']['name']);
        $after = collect($this->rows('hr.staff_details', ['as_of' => '2026-10-05', 'columns' => $columns]))->keyBy(fn ($r) => $r['employee']['name']);
        $this->assertSame(['North Campus', 'Teaching Dept', 'Teacher Role'], [$before['Asha Rao']['campus'], $before['Asha Rao']['department'], $before['Asha Rao']['position']]);
        $this->assertSame(['South Campus', 'Office Dept', 'Coordinator Role'], [$after['Asha Rao']['campus'], $after['Asha Rao']['department'], $after['Asha Rao']['position']]);
        // The historical Department filter is applied in SQL on that date.
        $this->assertSame(['Asha Rao'], $this->names($this->rows('hr.staff_details', ['as_of' => '2026-08-15', 'department_id' => $this->teaching->id])));
        $this->assertSame([], $this->rows('hr.staff_details', ['as_of' => '2026-10-05', 'department_id' => $this->teaching->id]));
        // Only the chosen columns leave the server.
        $this->assertSame(['key', 'employee', 'campus', 'department', 'position'], array_keys($after['Asha Rao']));
        // A date before anyone was employed lists nobody; another School's Department matches nothing.
        $this->assertSame([], $this->rows('hr.staff_details', ['as_of' => '2020-01-01']));
        $this->assertSame([], $this->rows('hr.staff_details', ['department_id' => $this->createDepartment($this->createSchool())->id]));
    }

    #[Test]
    public function placement_columns_and_filters_need_whole_school_assignment_authority(): void
    {
        $this->staff('Asha Rao', 'S-001');
        $viewer = $this->createUserWithCapabilities($this->school, ['hr.employees.view']);
        $props = $this->report('hr.staff_details', [], $viewer);
        $keys = array_column($props['selectable'], 'key');
        foreach (['campus', 'department', 'position', 'manager'] as $restricted) {
            $this->assertNotContains($restricted, $keys);
            $this->assertArrayNotHasKey($restricted, $props['rows']['data'][0]);
        }
        $this->assertNotContains('department_id', array_column(array_filter($props['filters'], fn ($f) => $f['options'] !== []), 'key'));
        // Forging the column is refused, not silently honoured.
        $this->assertReportError('columns', '/app/hr/reports/hr.staff_details?columns[]=department', $viewer);
        // Forging the filter cannot narrow by placement either.
        $this->assertCount(1, $this->rows('hr.staff_details', ['department_id' => $this->office->id], $viewer));
    }

    // ---------------------------------------------------------------- movements

    #[Test]
    public function the_movement_register_shows_each_canonical_fact_once(): void
    {
        [, $asha] = $this->staff('Asha Rao', 'S-001', '2026-10-01');
        app(PlacementChangeService::class)->change($asha, PlacementChangeKind::TRANSFER, '2026-10-03', PlacementChangeReason::STAFFING_REQUIREMENT, $this->teacher, $this->fullHrActor($this->school), campus: $this->south);
        // Ben left in September 2025 and rejoined on 2026-10-02 (a new employment).
        $ben = $this->createEmployee($this->school, ['full_name' => 'Ben Das', 'employee_number' => 'S-002']);
        $this->createEmploymentRecord($ben, ['starts_on' => '2023-01-01', 'ends_on' => '2025-09-30', 'status' => 'separated']);
        $this->createEmploymentRecord($ben, ['starts_on' => '2026-10-02', 'ends_on' => null, 'status' => 'active']);
        // Carla's employment ended on 2026-10-04.
        $this->staff('Carla Pinto', 'S-003', '2025-01-01', employment: ['ends_on' => '2026-10-04', 'status' => 'retired']);

        $rows = $this->rows('hr.staff_movements', ['from' => '2026-10-01', 'to' => '2026-10-05', 'sort' => 'date_asc']);
        $this->assertSame([
            ['2026-10-01', 'Asha Rao', 'Joined'],
            ['2026-10-02', 'Ben Das', 'Rejoined (new employment)'],
            ['2026-10-03', 'Asha Rao', 'Transfer'],
            ['2026-10-04', 'Carla Pinto', 'Employment ended'],
        ], array_map(fn ($r) => [$r['date'], $r['employee']['name'], $r['movement']], $rows));
        $this->assertSame('North Campus · Teaching Dept · Teacher Role', $rows[2]['from']);
        // The transfer named no Department, so the new primary assignment has none: the row shows the canonical assignment, never a guess.
        $this->assertSame('South Campus · Teacher Role', $rows[2]['to']);
        $this->assertSame('Retired', $rows[3]['detail']);
        $this->assertSame(['Asha Rao'], $this->names($this->rows('hr.staff_movements', ['from' => '2026-10-01', 'to' => '2026-10-05', 'movement' => 'transfer'])));
        // History only: a planned future start is not a movement yet.
        $this->staff('Dev Iyer', 'S-004', '2026-10-20', employment: ['status' => 'pre_joining']);
        $this->assertCount(4, $this->rows('hr.staff_movements', ['from' => '2026-10-01', 'to' => '2026-10-31']));
        // Needs assignment authority as well.
        $this->as($this->createUserWithCapabilities($this->school, ['hr.employees.view']))->get('/app/hr/reports/hr.staff_movements')->assertForbidden();
    }

    // ------------------------------------------------------------- anniversaries

    #[Test]
    public function anniversaries_count_from_the_current_employment_start_only(): void
    {
        $this->staff('Asha Rao', 'S-001', '2020-10-12');
        $this->staff('Joined This Year', 'S-005', '2026-10-01');
        $this->staff('Leap Start', 'S-006', '2024-02-29');
        // Ben's first employment started in October 2010; his current one (a rehire) in October 2024.
        $ben = $this->createEmployee($this->school, ['full_name' => 'Ben Das', 'employee_number' => 'S-002']);
        $this->createEmploymentRecord($ben, ['starts_on' => '2010-10-01', 'ends_on' => '2015-03-31', 'status' => 'separated']);
        $this->createEmploymentRecord($ben, ['starts_on' => '2024-10-20', 'ends_on' => null, 'status' => 'active']);
        // Ended employments never appear.
        $this->staff('Gone', 'S-007', '2019-10-05', employment: ['ends_on' => '2026-09-30', 'status' => 'separated']);

        $rows = $this->rows('hr.work_anniversaries', ['month' => '2026-10']);
        $this->assertSame([['Asha Rao', '2026-10-12', 6], ['Ben Das', '2026-10-20', 2]], array_map(fn ($r) => [$r['employee']['name'], $r['anniversary'], $r['years']], $rows));
        $leap = $this->rows('hr.work_anniversaries', ['month' => '2027-02']);
        $this->assertSame([['Leap Start', '2027-02-28', 3]], array_map(fn ($r) => [$r['employee']['name'], $r['anniversary'], $r['years']], $leap));
        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('date_of_birth', $row);
        }
    }

    // ----------------------------------------------------------- reporting lines

    #[Test]
    public function reporting_lines_answer_the_manager_and_level_on_the_date(): void
    {
        [$head] = $this->staff('Head Person', 'S-100', '2020-01-01');
        [$lead] = $this->staff('Lead Person', 'S-101', '2020-01-01');
        [$member] = $this->staff('Member Person', 'S-102', '2020-01-01');
        $actor = $this->fullHrActor($this->school);
        $lines = app(ReportingRelationshipService::class);
        $lines->assign($lead, $head, '2026-01-01', ReportingChangeReason::ORGANISATIONAL_CHANGE, $actor);
        $lines->assign($member, $head, '2026-01-01', ReportingChangeReason::ORGANISATIONAL_CHANGE, $actor);
        $lines->assign($member, $lead, '2026-09-01', ReportingChangeReason::RESTRUCTURING, $actor);

        $july = collect($this->rows('hr.reporting_lines', ['as_of' => '2026-07-01']))->keyBy(fn ($r) => $r['employee']['name']);
        $now = collect($this->rows('hr.reporting_lines', ['as_of' => '2026-10-05']))->keyBy(fn ($r) => $r['employee']['name']);
        $this->assertSame(['Head Person', 1], [$july['Member Person']['manager']['name'], $july['Member Person']['level']]);
        $this->assertSame(['Lead Person', 2], [$now['Member Person']['manager']['name'], $now['Member Person']['level']]);
        $this->assertSame([null, 0], [$now['Head Person']['manager'], $now['Head Person']['level']]);
        $this->assertSame(['Head Person'], $this->names($this->rows('hr.reporting_lines', ['line' => 'without_manager'])));
        $this->assertSame(['Head Person', 'Lead Person', 'Member Person'], $this->names($this->rows('hr.reporting_lines', ['as_of' => '2025-06-01', 'line' => 'without_manager'])));
    }

    // -------------------------------------------------------------------- leave

    #[Test]
    public function leave_balances_reconcile_with_the_ledger_and_honour_the_posting_date(): void
    {
        $admin = $this->leaveAdmin($this->school);
        $this->workingWeek($this->school, $admin);
        $type = $this->leaveType($this->school, $admin);
        $policy = $this->leavePolicy($this->school, $type, $admin);
        $year = $this->leaveYear($this->school, $admin, '2026-06-01');
        [$employment] = $this->staff('Asha Rao', 'S-001');
        app(LeavePolicyAssignmentService::class)->assign($this->school, $employment->id, $policy->id, '2026-04-01', null, $admin);

        $this->travelTo('2026-10-01 06:00:00');
        app(LeaveLedgerService::class)->allocate($this->school, $employment->id, $type->id, $year->id, 24, $admin);
        $this->travelTo('2026-10-05 06:00:00');
        $request = app(LeaveRequestService::class)->submitOnBehalf($this->school, $employment->id, $type->id, '2026-10-07', 'full', '2026-10-08', 'full', null, $this->leaveClerk($this->school));
        app(LeaveRequestService::class)->approve($this->school, $request->id, $admin);
        app(LeaveLedgerService::class)->adjust($this->school, $employment->id, $type->id, $year->id, 'credit', 1, 'administrative_correction', $admin);

        $row = $this->rows('hr.leave_balances', ['leave_year_id' => $year->id, 'posted_by' => '2026-10-05'])[0];
        $statement = app(LeaveStatementReader::class)->statement($this->school, $employment->id, $type->id, $year->id, $this->hr);
        $this->assertSame(['0', '12.5', '2', '10.5'], [$row['opening'], $row['credits'], $row['debits'], $row['balance']]);
        $this->assertSame($statement['closing'] / 2, (float) $row['balance']);
        $this->assertSame((float) $row['opening'] + (float) $row['credits'] - (float) $row['debits'], (float) $row['balance']);
        // As at 2026-10-01 only the allocation had been recorded.
        $this->assertSame('12', $this->rows('hr.leave_balances', ['leave_year_id' => $year->id, 'posted_by' => '2026-10-01'])[0]['balance']);
        $this->assertSame([], $this->rows('hr.leave_balances', ['leave_year_id' => $year->id, 'posted_by' => '2026-09-30']));
        // A date outside the Leave Year is refused, never guessed.
        $this->assertReportError('posted_by', "/app/hr/reports/hr.leave_balances?leave_year_id={$year->id}&posted_by=2027-04-01", $this->hr);
        // Reading changed nothing.
        $this->assertSame($statement['lines'], app(LeaveStatementReader::class)->statement($this->school, $employment->id, $type->id, $year->id, $this->hr)['lines']);
    }

    // --------------------------------------------------------------- attendance

    #[Test]
    public function absentees_are_recorded_absences_not_covered_by_approved_leave(): void
    {
        $admin = $this->leaveAdmin($this->school);
        $this->workingWeek($this->school, $admin);
        $type = $this->leaveType($this->school, $admin);
        $policy = $this->leavePolicy($this->school, $type, $admin);
        $year = $this->leaveYear($this->school, $admin, '2026-06-01');
        [$asha, $ashaPrimary] = $this->staff('Asha Rao', 'S-001');
        [$ben] = $this->staff('Ben Das', 'S-002', department: $this->office);
        [$carla] = $this->staff('Carla Pinto', 'S-003');
        app(LeavePolicyAssignmentService::class)->assign($this->school, $ben->id, $policy->id, '2026-04-01', null, $admin);
        app(LeaveLedgerService::class)->allocate($this->school, $ben->id, $type->id, $year->id, 24, $admin);

        $attendance = app(StaffAttendanceService::class);
        $attendance->record($this->school, $asha->id, '2026-10-01', 'absent', 'present', $this->hr);
        $attendance->record($this->school, $ben->id, '2026-10-01', 'absent', 'absent', $this->hr);
        $attendance->record($this->school, $carla->id, '2026-10-01', 'present', 'present', $this->hr);
        // Ben's absence is then covered by approved leave: leave outranks the recorded evidence.
        $request = app(LeaveRequestService::class)->submitOnBehalf($this->school, $ben->id, $type->id, '2026-10-01', 'full', '2026-10-01', 'full', null, $this->leaveClerk($this->school));
        app(LeaveRequestService::class)->approve($this->school, $request->id, $admin);
        // Asha moves to the Office Department from 2026-10-02; the row keeps the Department of its own date.
        app(PlacementChangeService::class)->change($ashaPrimary, PlacementChangeKind::DEPARTMENT_CHANGE, '2026-10-02', PlacementChangeReason::RESTRUCTURING, $this->teacher, $this->fullHrActor($this->school), campus: $this->north, department: $this->office);
        $snapshot = fn () => $this->inSchool($this->school, fn () => DB::table('staff_attendance_records')->orderBy('id')->get()->toJson());
        $before = $snapshot();

        $rows = $this->rows('hr.attendance_absentees', ['from' => '2026-10-01', 'to' => '2026-10-05']);
        $this->assertSame([['Asha Rao', 'Teaching Dept', 'Absent', 'Present']], array_map(fn ($r) => [$r['employee']['name'], $r['department'], $r['first_half'], $r['second_half']], $rows));
        $this->assertSame(['Asha Rao'], $this->names($this->rows('hr.attendance_absentees', ['from' => '2026-10-01', 'to' => '2026-10-01', 'department_id' => $this->teaching->id])));
        $this->assertSame([], $this->rows('hr.attendance_absentees', ['from' => '2026-10-01', 'to' => '2026-10-01', 'department_id' => $this->office->id]));
        // No clock, device or location fact exists to report.
        foreach (array_keys($rows[0]) as $key) {
            $this->assertDoesNotMatchRegularExpression('/punch|clock|late|gps|device|biometric/', $key);
        }
        $this->assertSame($before, $snapshot());
        $this->as($this->createUserWithCapabilities($this->school, ['hr.staff_attendance.self']))->get('/app/hr/reports/hr.attendance_absentees')->assertForbidden();
        // Without whole-School assignment authority the Department is absent and its filter is ignored.
        $attendanceOnly = $this->createUserWithCapabilities($this->school, ['hr.staff_attendance.view']);
        $limited = $this->rows('hr.attendance_absentees', ['from' => '2026-10-01', 'to' => '2026-10-01', 'department_id' => $this->office->id], $attendanceOnly);
        $this->assertSame(['Asha Rao'], $this->names($limited));
        $this->assertArrayNotHasKey('department', $limited[0]);
    }

    #[Test]
    public function the_correction_log_names_the_corrector_by_display_name_only(): void
    {
        $this->workingWeek($this->school, $this->leaveAdmin($this->school));
        [$asha] = $this->staff('Asha Rao', 'S-001');
        $clerk = $this->createUserWithCapabilities($this->school, ['hr.staff_attendance.manage', 'hr.staff_attendance.view']);
        $clerk->forceFill(['name' => 'Clerk Name'])->save();
        $record = app(StaffAttendanceService::class)->record($this->school, $asha->id, '2026-10-02', 'absent', 'absent', $this->hr);
        app(StaffAttendanceService::class)->correct($this->school, $record->id, $record->version, 'present', 'absent', 'late_information', $clerk);

        $props = $this->report('hr.attendance_corrections', ['from' => '2026-10-01', 'to' => '2026-10-05']);
        $row = $props['rows']['data'][0];
        $this->assertSame(['Absent / Absent', 'Present / Absent', 'Information received later', 'Clerk Name'], [$row['before'], $row['after'], $row['reason'], $row['corrected_by']]);
        $this->assertStringNotContainsString($clerk->id, json_encode($props['rows']));
        $this->assertStringNotContainsString((string) $clerk->email, json_encode($props));
        $this->assertStringNotContainsString($clerk->id, json_encode($props['filters']), 'the corrector filter carries an opaque token, never a User id');
        $token = collect($props['filters'])->firstWhere('key', 'corrected_by')['options'][0]['value'];
        $this->assertCount(1, $this->rows('hr.attendance_corrections', ['from' => '2026-10-01', 'to' => '2026-10-05', 'corrected_by' => $token]));
        $this->assertReportError('corrected_by', '/app/hr/reports/hr.attendance_corrections?corrected_by='.$clerk->id, $this->hr);
        $this->assertSame([], $this->rows('hr.attendance_corrections', ['from' => '2026-10-01', 'to' => '2026-10-05', 'reason' => 'entered_in_error']));
        $this->assertReportError('corrected_by', '/app/hr/reports/hr.attendance_corrections?corrected_by='.$this->createUser()->id, $this->hr);
    }

    // -------------------------------------------------------------------- tasks

    #[Test]
    public function task_reports_count_occurrences_by_status_and_reconcile_without_rates_or_remarks(): void
    {
        $category = app(TaskCatalogueService::class)->createCategory($this->school, 'ops', 'Operations', $this->hr);
        [$asha, $ashaPrimary] = $this->staff('Asha Rao', 'S-001');
        [$ben] = $this->staff('Ben Das', 'S-002', department: $this->office);
        $assign = fn (EmploymentRecord $e, string $title, string $due, string $mode = 'self') => app(TaskAssignmentService::class)->assign($this->school, $e->id, [
            'title' => $title, 'description' => null, 'category_id' => (string) $category->id, 'completion_mode' => $mode,
            'available_on' => '2026-10-05', 'due_on' => $due, 'section_id' => null, 'subject_offering_id' => null,
        ], $this->hr);
        // Overdue is evaluated against the School-local today: create, then move past the due date.
        $late = $assign($asha, 'Late task', '2026-10-06');
        $submitted = $assign($asha, 'Submitted task', '2026-10-06', 'reviewed');
        $future = $assign($ben, 'Future task', '2026-10-30');
        $done = $assign($ben, 'Done task', '2026-10-06');
        $ashaUser = $this->linkUser($asha, ['hr.tasks.self']);
        $benUser = $this->linkUser($ben, ['hr.tasks.self']);
        app(TaskWorkflowService::class)->submit($this->school, $submitted->id, $submitted->version, 'secret remark text', $ashaUser);
        app(TaskWorkflowService::class)->submit($this->school, $done->id, $done->version, 'another private note', $benUser);
        // Asha moves to Office later: her old tasks stay attributed to Teaching.
        app(PlacementChangeService::class)->change($ashaPrimary, PlacementChangeKind::DEPARTMENT_CHANGE, '2026-10-05', PlacementChangeReason::RESTRUCTURING, $this->teacher, $this->fullHrActor($this->school), campus: $this->north, department: $this->office);
        $this->travelTo('2026-10-08 06:00:00');

        $period = ['from' => '2026-10-01', 'to' => '2026-10-31'];
        $props = $this->report('hr.task_occurrences', $period);
        $byTask = collect($props['rows']['data'])->keyBy('task');
        $this->assertSame('Assigned · overdue', $byTask['Late task']['status']);
        $this->assertSame('Submitted for review', $byTask['Submitted task']['status']);
        $this->assertSame('Assigned', $byTask['Future task']['status']);
        $this->assertSame('Completed', $byTask['Done task']['status']);
        $this->assertSame('Teaching Dept', $byTask['Late task']['department']);
        $this->assertStringNotContainsString('secret remark', json_encode($props));
        $this->assertStringNotContainsString('private note', json_encode($props));
        $this->assertSame(['Late task'], array_column($this->rows('hr.task_occurrences', [...$period, 'status' => 'overdue']), 'task'));
        $this->assertSame(['Done task', 'Future task'], array_column($this->rows('hr.task_occurrences', [...$period, 'department_id' => $this->office->id, 'sort' => 'due_asc']), 'task'));

        $summary = $this->rows('hr.task_summary', $period)[0];
        $this->assertSame(['One-off tasks · Operations', 4, 2, 1, 0, 1, 0, 1], [
            $summary['task'], $summary['occurrences'], $summary['assigned'], $summary['submitted'], $summary['returned'], $summary['completed'], $summary['cancelled'], $summary['overdue'],
        ]);
        $this->assertSame($summary['occurrences'], $summary['assigned'] + $summary['submitted'] + $summary['returned'] + $summary['completed'] + $summary['cancelled']);
        $this->assertSame(count($this->rows('hr.task_occurrences', $period)), $summary['occurrences']);
        foreach (array_keys($summary) as $key) {
            $this->assertDoesNotMatchRegularExpression('/rate|percent|score/', $key);
        }
        // The team role and the line-manager role never open School-wide task reports.
        $this->as($this->createUserWithCapabilities($this->school, ['hr.tasks.team', 'hr.leave.approve']))->get('/app/hr/reports/hr.task_occurrences')->assertForbidden();
    }

    #[Test]
    public function generation_coverage_shows_days_without_a_run_as_no_run(): void
    {
        $this->inSchool($this->school, fn () => DB::table('workforce_task_generation_runs')->insert([
            'id' => (string) Str::uuid(), 'school_id' => $this->school->id, 'occurrence_on' => '2026-10-04', 'status' => 'completed',
            'examined' => 3, 'created' => 2, 'existing' => 0, 'not_matched' => 1, 'started_at' => now(), 'finished_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]));
        $rows = collect($this->rows('hr.task_generation_runs', ['from' => '2026-10-03', 'to' => '2026-10-05']))->keyBy('date');
        $this->assertSame(['No run', 'Completed', 'No run'], [$rows['2026-10-03']['status'], $rows['2026-10-04']['status'], $rows['2026-10-05']['status']]);
        $this->assertNull($rows['2026-10-03']['created']);
        $this->assertSame([3, 2, 0, 1], [$rows['2026-10-04']['examined'], $rows['2026-10-04']['created'], $rows['2026-10-04']['existing'], $rows['2026-10-04']['not_matched']]);
        // Never a future day.
        $this->assertCount(3, $this->rows('hr.task_generation_runs', ['from' => '2026-10-03', 'to' => '2026-10-20']));
    }

    // ---------------------------------------------------------------- isolation

    #[Test]
    public function another_schools_records_never_appear_and_its_ids_match_nothing(): void
    {
        $this->staff('Asha Rao', 'S-001');
        $other = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $otherEmployee = $this->createEmployee($other, ['full_name' => 'Other School Person', 'employee_number' => 'O-001']);
        $this->createEmploymentRecord($otherEmployee, ['starts_on' => '2020-01-01', 'status' => 'active']);

        foreach (['hr.staff_details', 'hr.reporting_lines', 'hr.work_anniversaries'] as $key) {
            $this->assertStringNotContainsString('Other School Person', json_encode($this->report($key, $key === 'hr.work_anniversaries' ? ['month' => '2027-01'] : [])), $key);
        }
        $this->assertStringNotContainsString('Other School Person', json_encode($this->report('hr.staff_movements', ['from' => '2020-01-01', 'to' => '2020-12-31'])));
        $this->assertSame([], $this->rows('hr.staff_details', ['category_id' => $this->createEmployeeCategory($other)->id]));
        // A member of the other School reads only its own School: ours never enters its context.
        $outsider = $this->createUserWithCapabilities($other, ['hr.employees.view', 'hr.employees.assignments.view']);
        $this->actingAs($outsider)->post("/app/schools/{$this->school->id}/activate")->assertSessionHasErrors();
        $theirs = $this->report('hr.staff_details', [], $outsider, $other);
        $this->assertSame(['Other School Person'], $this->names($theirs['rows']['data']));
    }

    #[Test]
    public function every_page_reads_a_bounded_number_of_queries(): void
    {
        $counts = [];
        $columns = ['employee', 'campus', 'department', 'position', 'manager', 'category', 'staff_type'];
        $this->rows('hr.staff_details', ['columns' => $columns]); // warm the per-request capability and session caches
        foreach ([3, 30] as $n) {
            for ($i = count($counts) * 100; $i < count($counts) * 100 + $n; $i++) {
                $this->staff("Person {$i}", sprintf('Q-%04d', $i));
            }
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->rows('hr.staff_details', ['columns' => $columns]);
            $counts[] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }
        $this->assertSame($counts[0], $counts[1], 'queries per page do not grow with the School');
    }

    private function linkUser(EmploymentRecord $employment, array $capabilities): User
    {
        $user = $this->createUserWithCapabilities($this->school, $capabilities);
        $this->inSchool($this->school, fn () => $employment->employee->forceFill(['user_id' => $user->id])->save());

        return $user;
    }
}
