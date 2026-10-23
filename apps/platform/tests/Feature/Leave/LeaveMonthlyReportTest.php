<?php

namespace Tests\Feature\Leave;

use App\Domain\Leave\Application\LeaveRequestService;
use App\Domain\Leave\Infrastructure\LeaveRequest;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Leave\Concerns\CreatesLeaveFixtures;
use Tests\TestCase;

/**
 * ORG.9C-1 (ADR 0076 §69), PV1-06: HR-042 approved leave taken per month per
 * employment and type (half-day units, a cross-month request split by its own
 * days), and HR-043 request-list filters (employee, type, overlapping dates).
 * Whole-School `hr.leave.view`, never a reason, never paid/unpaid, read-only.
 */
class LeaveMonthlyReportTest extends TestCase
{
    use CreatesLeaveFixtures;

    /** @return array<string, mixed> */
    private function world(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-20T06:00:00Z'));
        $w = $this->leaveWorld();
        $this->workingWeek($w['school'], $w['admin']);
        $this->allocate($w);
        $w['other'] = $this->leaveType($w['school'], $w['admin'], 'EL');

        $approve = fn (LeaveRequest $r) => app(LeaveRequestService::class)->approve($w['school'], $r->id, $w['admin']);
        $w['across'] = $approve($this->submitLeave($w, '2026-06-30', '2026-07-01')); // Tue+Wed: one day each month
        $w['half'] = $approve($this->submitLeave($w, '2026-07-06', '2026-07-06', 'first_half'));
        $w['pending'] = $this->submitLeave($w, '2026-07-08', '2026-07-08'); // submitted: never counted

        return $w;
    }

    /** @return array<string, mixed> */
    private function monthly(array $w, string $month): array
    {
        $this->actingAs($w['admin'])->post("/app/schools/{$w['school']->id}/activate");

        return $this->get('/app/leave/monthly?month='.$month)->assertOk()->viewData('page')['props']['summary'];
    }

    #[Test]
    public function approved_days_are_counted_in_the_month_they_fall_in(): void
    {
        $w = $this->world();
        $july = $this->monthly($w, '2026-07');
        $june = $this->monthly($w, '2026-06');

        $type = $w['type']->id;
        $this->assertSame([['employmentRecordId' => $w['employment']->id, 'units' => [$type => 3], 'total' => 3]], $july['rows'], 'July: 07-01 (2 units) + the half day (1); the pending 07-08 is not counted.');
        $this->assertSame([$type => 3], $july['totals']);
        $this->assertSame([$type => 2], $june['totals'], 'June: 06-30 only.');
        $this->assertSame([], $this->monthly($w, '2026-05')['rows']);
    }

    #[Test]
    public function the_month_report_shows_no_reason_and_no_paid_split(): void
    {
        $w = $this->world();
        $json = json_encode($this->monthly($w, '2026-07'));

        foreach (['reason', 'paid', 'unpaid', 'lop'] as $forbidden) {
            $this->assertDoesNotMatchRegularExpression('/"[a-zA-Z]*'.$forbidden.'[a-zA-Z]*"\s*:/i', $json);
        }
        $this->actingAs($w['admin'])->post("/app/schools/{$w['school']->id}/activate");
        $this->get('/app/leave/monthly?month=2026/07')->assertSessionHasErrors('month');
    }

    #[Test]
    public function request_filters_narrow_by_employee_type_and_overlapping_dates(): void
    {
        $w = $this->world();
        $this->actingAs($w['admin'])->post("/app/schools/{$w['school']->id}/activate");
        $ids = fn (string $q) => collect($this->get('/app/leave/requests?'.$q)->assertOk()->viewData('page')['props']['requests'])->pluck('id')->sort()->values()->all();

        $this->assertSame(collect([$w['across']->id, $w['half']->id, $w['pending']->id])->sort()->values()->all(), $ids('employment_record_id='.$w['employment']->id));
        $this->assertSame([], $ids('leave_type_id='.$w['other']->id));
        $this->assertSame([$w['across']->id], $ids('from=2026-06-01&to=2026-06-30'), 'A request overlapping the range is included.');
        $this->assertSame(collect([$w['half']->id, $w['pending']->id])->sort()->values()->all(), $ids('from=2026-07-02'));
        $this->assertSame([$w['pending']->id], $ids('status=submitted&from=2026-07-02'));
        $this->get('/app/leave/requests?from=2026-07-10&to=2026-07-01')->assertSessionHasErrors('to');
        $this->get('/app/leave/requests?leave_type_id=nope')->assertSessionHasErrors('leave_type_id');
    }

    #[Test]
    public function it_needs_leave_view_and_never_reads_another_school(): void
    {
        $w = $this->world();
        $other = $this->world();
        $this->assertNotContains($other['employment']->id, array_column($this->monthly($w, '2026-07')['rows'], 'employmentRecordId'));

        $outsider = $this->createUserWithCapabilities($w['school'], ['hr.staff_attendance.view']);
        $this->actingAs($outsider)->post("/app/schools/{$w['school']->id}/activate");
        $this->get('/app/leave/monthly')->assertForbidden();
        $this->get('/app/leave/requests?from=2026-07-01')->assertForbidden();
    }
}
