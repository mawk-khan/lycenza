<?php

namespace Tests\Feature\StaffAttendance;

use App\Domain\StaffAttendance\Application\StaffAttendanceReadService;
use App\Domain\StaffAttendance\Infrastructure\StaffAttendanceRecord;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\StaffAttendance\Concerns\CreatesStaffAttendanceFixtures;
use Tests\TestCase;

/**
 * ORG.9C-1 (ADR 0076 §69), HR-003: a month's staff attendance counted per
 * employment in half-days by EXACTLY the state the daily register shows --
 * present, absent, leave, holiday, off day, unrecorded -- never a late,
 * no-punch or loss-of-pay figure (HRX-L2/L4), whole-School, read-only.
 */
class StaffAttendanceMonthlySummaryTest extends TestCase
{
    use CreatesStaffAttendanceFixtures;

    /** @return array<string, mixed> */
    private function world(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-15T06:00:00Z'));
        $w = $this->attendanceWorld();
        $this->recordAttendance($w, '2026-07-01', 'present', 'present');
        $this->recordAttendance($w, '2026-07-02', 'present', 'absent');
        $this->approvedLeave($w, '2026-07-03', '2026-07-03');
        $w['late'] = $this->currentEmployment($w['school'], '2026-07-10');

        return $w;
    }

    /** @return array<string, mixed> */
    private function monthly(array $w, string $query = ''): array
    {
        $this->actingAs($w['clerk'])->post("/app/schools/{$w['school']->id}/activate");

        return $this->get('/app/staff-attendance/monthly'.($query === '' ? '' : '?'.$query))->assertOk()->viewData('page')['props']['summary'];
    }

    #[Test]
    public function the_month_counts_exactly_what_the_daily_register_shows(): void
    {
        $w = $this->world();
        $summary = $this->monthly($w, 'month=2026-07');
        $this->assertSame(['2026-07-01', '2026-07-15'], [$summary['from'], $summary['to']], 'Up to the School\'s today, never the future.');

        $expected = [];
        $reads = app(StaffAttendanceReadService::class);
        for ($d = CarbonImmutable::parse('2026-07-01'); $d->toDateString() <= '2026-07-15'; $d = $d->addDay()) {
            foreach ($reads->register($w['school'], $d->toDateString(), $w['clerk'])['rows'] as $row) {
                if (! $row['employment']['recordable'] && $row['employment']['employmentRecordId'] === $w['late']->id && $d->toDateString() < '2026-07-10') {
                    continue;
                }
                foreach (['firstHalf', 'secondHalf'] as $half) {
                    $expected[$row['employment']['employmentRecordId']][$row['day'][$half]['state']] = ($expected[$row['employment']['employmentRecordId']][$row['day'][$half]['state']] ?? 0) + 1;
                }
            }
        }
        foreach ($summary['rows'] as $row) {
            $id = $row['employment']['employmentRecordId'];
            $this->assertEquals($expected[$id], array_filter($row['halfDays']), "employment {$id}");
        }

        $mine = collect($summary['rows'])->firstWhere('employment.employmentRecordId', $w['employment']->id)['halfDays'];
        $this->assertSame([3, 1, 2], [$mine['present'], $mine['absent'], $mine['leave']]);
        $late = collect($summary['rows'])->firstWhere('employment.employmentRecordId', $w['late']->id)['halfDays'];
        $this->assertSame(12, array_sum($late), 'An employment starting 07-10 is counted from 07-10 only (6 days).');
    }

    #[Test]
    public function no_clock_time_or_pay_derived_state_is_ever_produced(): void
    {
        $w = $this->world();
        $summary = $this->monthly($w);

        $this->assertSame(['present', 'absent', 'leave', 'holiday', 'off_day', 'unrecorded'], array_keys($summary['totals']));
        foreach ($summary['rows'] as $row) {
            $this->assertSame(['present', 'absent', 'leave', 'holiday', 'off_day', 'unrecorded'], array_keys($row['halfDays']));
        }
        $json = json_encode($summary);
        foreach (['late', 'early', 'punch', 'lop', 'loss', 'unpaid', 'paid', 'deduct', 'reason'] as $forbidden) {
            $this->assertDoesNotMatchRegularExpression('/"[a-zA-Z]*'.$forbidden.'[a-zA-Z]*"\s*:/i', $json, "no '{$forbidden}' field (HRX-L2/L4, HRX-L1)");
        }
    }

    #[Test]
    public function a_future_month_is_refused_and_the_report_writes_nothing(): void
    {
        $w = $this->world();
        $before = $this->inSchool($w['school'], fn () => StaffAttendanceRecord::query()->count());
        $this->actingAs($w['clerk'])->post("/app/schools/{$w['school']->id}/activate");
        $this->get('/app/staff-attendance/monthly?month=2026-08')->assertSessionHasErrors('month');
        $this->get('/app/staff-attendance/monthly?month=2026-13')->assertSessionHasErrors('month');
        $june = $this->monthly($w, 'month=2026-06');
        $this->assertSame('2026-06-30', $june['to']);
        $this->assertSame($before, $this->inSchool($w['school'], fn () => StaffAttendanceRecord::query()->count()));
    }

    #[Test]
    public function it_needs_staff_attendance_view_and_never_reads_another_school(): void
    {
        $w = $this->world();
        $other = $this->world();
        $ids = collect($this->monthly($w)['rows'])->pluck('employment.employmentRecordId')->all();
        $this->assertNotContains($other['employment']->id, $ids);

        $outsider = $this->createUserWithCapabilities($w['school'], ['hr.leave.view', 'hr.employees.view']);
        $this->actingAs($outsider)->post("/app/schools/{$w['school']->id}/activate");
        $this->get('/app/staff-attendance/monthly')->assertForbidden();
    }
}
