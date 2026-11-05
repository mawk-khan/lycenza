<?php

namespace Tests\Feature\StaffAttendance;

use App\Domain\StaffAttendance\Application\StaffAttendanceReadService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\StaffAttendance\Concerns\CreatesStaffAttendanceFixtures;
use Tests\TestCase;

/**
 * HR.16C: the daily register and the month view are SERVER-PAGED with no
 * population cap (the old 2,000-row roster limit is gone). Every employment
 * is reachable, none twice, the page carries the whole total, the month's
 * totals count everyone, and the register's semantics are unchanged
 * (unrecorded is never absent; a correction stays on its own row; viewing
 * every page never grants a write). Synthetic data only.
 */
class StaffAttendanceRegisterPagingTest extends TestCase
{
    use CreatesStaffAttendanceFixtures;

    private const int STAFF = 2100;

    #[Test]
    public function the_register_pages_by_name_and_keeps_its_states_and_corrections(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-15T06:00:00Z'));
        $w = $this->attendanceWorld();
        $absent = $this->currentEmployment($w['school']);
        $unrecorded = $this->currentEmployment($w['school']);
        $record = $this->recordAttendance($w, '2026-07-14', 'present', 'present');
        $corrected = $this->correctAttendance($w, $record, 'absent', 'present', 'late_information');
        $this->recordAttendance($w, '2026-07-14', 'absent', 'absent', $absent);
        // An employment that ended before the date but holds a record on it stays on the register.
        $orphan = $this->currentEmployment($w['school'], '2024-01-01');
        $this->recordAttendance($w, '2026-07-14', 'present', 'present', $orphan);
        app(TenantContext::class)->withSchool($w['school'], fn () => DB::table('employment_records')->where('id', $orphan->id)->update(['ends_on' => '2026-07-10', 'status' => 'separated']));

        $reads = app(StaffAttendanceReadService::class);
        $page1 = $reads->register($w['school'], '2026-07-14', $w['clerk'], 1, 2);
        $page2 = $reads->register($w['school'], '2026-07-14', $w['clerk'], 2, 2);
        $this->assertSame(['page' => 1, 'perPage' => 2, 'total' => 4, 'lastPage' => 2], $page1['page']);
        $rows = collect([...$page1['rows'], ...$page2['rows']])->keyBy('employment.employmentRecordId');
        $this->assertCount(4, $rows, 'every employment is on exactly one page, the ended one holding a record included');
        $this->assertSame([], array_intersect(array_column(array_column($page1['rows'], 'employment'), 'employmentRecordId'), array_column(array_column($page2['rows'], 'employment'), 'employmentRecordId')), 'pages are disjoint');

        $this->assertSame('unrecorded', $rows[$unrecorded->id]['day']['firstHalf']['state'], 'nothing recorded is never absent');
        $this->assertSame('absent', $rows[$absent->id]['day']['firstHalf']['state']);
        $this->assertSame(['absent', $corrected->version], [$rows[$w['employment']->id]['day']['firstHalf']['state'], $rows[$w['employment']->id]['day']['record']['version']], 'the correction stays on its own row');
        $this->assertArrayHasKey($orphan->id, $rows->all());

        // Paging through everything never grants a write.
        $viewer = $this->createUserWithCapabilities($w['school'], ['hr.staff_attendance.view']);
        $this->assertSame(4, $reads->register($w['school'], '2026-07-14', $viewer, 1, 200)['page']['total']);
        $this->actingAs($viewer)->post("/app/schools/{$w['school']->id}/activate");
        $this->post("/app/staff-attendance/records/{$record->id}/corrections", ['expected_version' => $corrected->version, 'first_half' => 'present', 'second_half' => 'present', 'reason_code' => 'other'])->assertForbidden();
        $this->expectException(AuthorizationException::class);
        $reads->register($w['school'], '2026-07-14', $this->createUserWithCapabilities($w['school'], ['hr.staff_attendance.self']));
    }

    #[Test]
    public function more_than_two_thousand_employments_are_all_reachable_and_counted(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-03T06:00:00Z'));
        $w = $this->attendanceWorld();
        $other = $this->createSchool();
        $this->createEmploymentRecord($this->createEmployee($other, ['full_name' => 'Elsewhere Person']), ['starts_on' => '2020-01-01', 'status' => 'active']);
        $now = now();
        $rows = ['employees' => [], 'employment_records' => [], 'staff_attendance_records' => []];
        foreach (range(1, self::STAFF - 1) as $i) {
            $employee = (string) Str::uuid7();
            $employment = (string) Str::uuid7();
            $rows['employees'][] = ['id' => $employee, 'school_id' => $w['school']->id, 'employee_number' => sprintf('BULK-%05d', $i), 'full_name' => sprintf('Bulk %05d', $i), 'record_status' => 'active', 'created_at' => $now, 'updated_at' => $now];
            $rows['employment_records'][] = ['id' => $employment, 'school_id' => $w['school']->id, 'employee_id' => $employee, 'employment_type' => 'permanent', 'staff_type' => 'teaching', 'starts_on' => '2024-01-01', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now];
            if ($i % 3 === 0) {
                $rows['staff_attendance_records'][] = ['id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, 'employment_record_id' => $employment, 'employee_id' => $employee, 'attendance_date' => '2026-10-02', 'first_half_status' => 'absent', 'second_half_status' => 'present', 'version' => 1, 'recorded_by_user_id' => $w['clerk']->id, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        app(TenantContext::class)->withSchool($w['school'], function () use ($rows): void {
            foreach ($rows as $table => $data) {
                foreach (array_chunk($data, 500) as $chunk) {
                    DB::table($table)->insert($chunk);
                }
            }
        });
        DB::statement('SET LOCAL enable_nestloop = off'); // no planner statistics for uncommitted bulk rows (see HrReportsLargePopulationTest)

        $reads = app(StaffAttendanceReadService::class);
        $seen = [];
        $absentHalves = 0;
        $first = $reads->register($w['school'], '2026-10-02', $w['clerk'], 1, 200);
        $this->assertSame(self::STAFF, $first['page']['total'], 'the whole population, never 2,000');
        for ($page = 1; $page <= $first['page']['lastPage']; $page++) {
            foreach ($reads->register($w['school'], '2026-10-02', $w['clerk'], $page, 200)['rows'] as $row) {
                $id = $row['employment']['employmentRecordId'];
                $this->assertArrayNotHasKey($id, $seen, 'no employment on two pages');
                $this->assertNotSame('Elsewhere Person', $row['employment']['fullName']);
                $seen[$id] = true;
                $absentHalves += (int) ($row['day']['firstHalf']['state'] === 'absent') + (int) ($row['day']['secondHalf']['state'] === 'absent');
            }
        }
        $this->assertCount(self::STAFF, $seen);
        $this->assertSame(intdiv(self::STAFF - 1, 3), $absentHalves, 'each record sits on its own employment');

        // The month view: one page of rows, totals over EVERYONE (3 days x 2 halves each).
        $summary = $reads->monthSummary($w['school'], '2026-10', $w['clerk'], 1, 100);
        $this->assertCount(100, $summary['rows']);
        $this->assertSame(self::STAFF, $summary['page']['total']);
        $this->assertSame(self::STAFF * 3 * 2, array_sum($summary['totals']));
        $this->assertSame(intdiv(self::STAFF - 1, 3), $summary['totals']['absent']);
    }
}
