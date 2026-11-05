<?php

namespace Tests\Feature\HR\Reports;

use App\Support\HrReports\HrReportAccess;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.14: the reports traverse a School far above the old 2,000-row roster
 * cap completely -- every page, no duplicates, a total equal to the source
 * rows -- and another School's population never leaks in. Synthetic data.
 */
class HrReportsLargePopulationTest extends TestCase
{
    use CreatesTenancyFixtures;

    private const int STAFF = 2100;

    #[Test]
    public function staff_details_absentees_and_task_occurrences_page_through_every_row_without_truncation(): void
    {
        $this->travelTo('2026-10-05 06:00:00');
        $school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $other = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        app(TenantContext::class)->set($school);
        $position = $this->createPosition($school);
        $department = $this->createDepartment($school);
        $hr = $this->createUserWithCapabilities($school, ['hr.employees.view', 'hr.employees.assignments.view', 'hr.staff_attendance.view', 'hr.tasks.view']);
        $category = (string) Str::uuid7();
        $now = now();
        DB::table('workforce_task_categories')->insert(['id' => $category, 'school_id' => $school->id, 'code' => 'BULK', 'label' => 'Bulk', 'status' => 'active', 'created_by_user_id' => $hr->id, 'created_at' => $now, 'updated_at' => $now]);

        $rows = ['employees' => [], 'employment_records' => [], 'employee_assignments' => [], 'staff_attendance_records' => [], 'workforce_tasks' => []];
        foreach (range(1, self::STAFF) as $i) {
            $employee = (string) Str::uuid7();
            $employment = (string) Str::uuid7();
            $rows['employees'][] = ['id' => $employee, 'school_id' => $school->id, 'employee_number' => sprintf('BULK-%05d', $i), 'full_name' => sprintf('Bulk %05d', $i), 'record_status' => 'active', 'created_at' => $now, 'updated_at' => $now];
            $rows['employment_records'][] = ['id' => $employment, 'school_id' => $school->id, 'employee_id' => $employee, 'employment_type' => 'permanent', 'staff_type' => 'teaching', 'starts_on' => '2024-01-01', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now];
            $rows['employee_assignments'][] = ['id' => (string) Str::uuid7(), 'school_id' => $school->id, 'employment_record_id' => $employment, 'position_id' => $position->id, 'department_id' => $department->id, 'starts_on' => '2024-01-01', 'is_primary' => true, 'created_at' => $now, 'updated_at' => $now];
            $rows['staff_attendance_records'][] = ['id' => (string) Str::uuid7(), 'school_id' => $school->id, 'employment_record_id' => $employment, 'employee_id' => $employee, 'attendance_date' => '2026-10-01', 'first_half_status' => 'absent', 'second_half_status' => 'absent', 'version' => 1, 'recorded_by_user_id' => $hr->id, 'created_at' => $now, 'updated_at' => $now];
            $rows['workforce_tasks'][] = ['id' => (string) Str::uuid7(), 'school_id' => $school->id, 'employment_record_id' => $employment, 'title' => 'Bulk task', 'category_id' => $category, 'completion_mode' => 'self', 'assignment_path' => 'administrative', 'assigned_by_user_id' => $hr->id, 'available_on' => '2026-10-01', 'due_on' => '2026-10-02', 'status' => 'assigned', 'staff_type' => 'teaching', 'department_id' => $department->id, 'position_id' => $position->id, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach ($rows as $table => $data) {
            foreach (array_chunk($data, 500) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }
        // One person in another School, who must never appear.
        $this->createEmploymentRecord($this->createEmployee($other, ['full_name' => 'Elsewhere Person']), ['starts_on' => '2020-01-01', 'status' => 'active']);

        // The bulk rows are uncommitted inside the test transaction and the runtime
        // role cannot ANALYZE tables it does not own, so the planner sees no
        // statistics and picks nested loops a real (autovacuumed) database never
        // would. This is a test-only planner hint for speed; results are identical.
        DB::statement('SET LOCAL enable_nestloop = off');
        $this->actingAs($hr)->post("/app/schools/{$school->id}/activate");
        $access = app(HrReportAccess::class);
        $viewer = $access->viewer($hr, $school);
        foreach ([
            'hr.staff_details' => ['as_of' => '2026-10-05', 'q' => '', 'staff_type' => '', 'category_id' => '', 'campus_id' => '', 'department_id' => '', 'position_id' => ''],
            'hr.attendance_absentees' => ['from' => '2026-10-01', 'to' => '2026-10-01', 'department_id' => '', 'q' => ''],
            'hr.task_occurrences' => ['from' => '2026-10-01', 'to' => '2026-10-31', 'status' => 'overdue', 'task_category_id' => '', 'frequency' => '', 'department_id' => '', 'position_id' => '', 'staff_type' => '', 'q' => ''],
        ] as $key => $filters) {
            // Through HTTP: the first and the last page answer the full total.
            $first = $this->get(route('app.hr.reports.show', ['report' => $key, ...array_filter($filters)], false))->assertOk()->viewData('page')['props']['rows'];
            $this->assertSame(self::STAFF, $first['total'], "{$key}: the total is every source row");
            $last = $this->get(route('app.hr.reports.show', ['report' => $key, ...array_filter($filters), 'page' => $first['last_page']], false))->assertOk()->viewData('page')['props']['rows'];
            $this->assertSame(self::STAFF - ($first['last_page'] - 1) * $first['per_page'], count($last['data']));

            // Through the read model: every page, nothing repeated, nothing missed.
            $report = $access->authorizeReport($viewer, $key);
            $sort = $report->definition()->defaultSort();
            $columns = array_map(fn ($c) => $c->key, $access->visibleColumns($viewer, $report->definition()));
            $seen = [];
            for ($page = 1; $page <= $first['last_page'] + 1; $page++) {
                foreach ($report->page($school, $viewer, $filters, $sort, $page, $columns)->items() as $row) {
                    $this->assertArrayNotHasKey($row['key'], $seen, "{$key}: no row repeats across pages");
                    $seen[$row['key']] = true;
                    $this->assertNotSame('Elsewhere Person', $row['employee']['name'] ?? null);
                }
            }
            $this->assertCount(self::STAFF, $seen, "{$key}: every row was reachable");
        }
    }
}
