<?php

namespace Tests\Feature\HR\Reports;

use App\Domain\HR\Application\Separation\SeparationCancelReason;
use App\Domain\HR\Application\Separation\SeparationCaseService;
use App\Domain\HR\Application\Separation\SeparationNoticeHistory;
use App\Domain\HR\Application\Separation\SeparationReason;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Leave\Application\LeaveLedgerService;
use App\Domain\Leave\Application\LeavePolicyAssignmentService;
use App\Domain\WorkforceTasks\Application\TaskAssignmentService;
use App\Domain\WorkforceTasks\Application\TaskCatalogueService;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Leave\Concerns\CreatesLeaveFixtures;
use Tests\TestCase;

/**
 * HR.16A (owner decisions 2026-11-05): what each HR report means in time.
 * - Staff details shows the employment status ON the chosen date from HR.12
 *   evidence (approval starts a notice period, cancellation ends it), and
 *   says "notice history not recorded" where that cannot be known; today's
 *   status is a separate, labelled column (R-HR14-2).
 * - Leave balances are AS POSTED: entries recorded by the end of the chosen
 *   School-local day; a backdated adjustment counts from its posting
 *   (R-HR14-1).
 * - The School-local day boundary is exact, ahead of and behind UTC, for the
 *   ledger cutoff and for task overdue.
 *
 * Every name, date and number is a synthetic test input.
 */
class HrReportTemporalSemanticsTest extends TestCase
{
    use CreatesLeaveFixtures;

    private School $school;

    private User $hr;

    private function setUpSchool(string $timezone = 'Asia/Kolkata'): void
    {
        $this->school = $this->createSchool(['timezone' => $timezone]);
        app(TenantContext::class)->set($this->school);
        $this->hr = $this->createUserWithCapabilities($this->school, [
            'hr.employees.view', 'hr.employees.assignments.view', 'hr.employees.assignments.manage',
            'hr.separations.view', 'hr.separations.manage', 'hr.leave.view', 'hr.leave.manage', 'hr.leave.configure',
            'hr.tasks.view', 'hr.tasks.manage', 'hr.tasks.configure',
        ]);
    }

    private function employment(string $name, string $starts, array $attributes = []): EmploymentRecord
    {
        return $this->createEmploymentRecord($this->createEmployee($this->school, ['full_name' => $name, 'employee_number' => 'N-'.substr(md5($name), 0, 6)]),
            [...['starts_on' => $starts, 'ends_on' => null, 'status' => 'active'], ...$attributes]);
    }

    /** @return array<string, array<string, mixed>> staff name => row */
    private function details(string $asOf): array
    {
        $this->actingAs($this->hr)->post("/app/schools/{$this->school->id}/activate");

        return collect($this->get(route('app.hr.reports.show', ['report' => 'hr.staff_details', 'as_of' => $asOf, 'columns' => ['employee', 'status_on_date', 'current_status']], false))
            ->assertOk()->viewData('page')['props']['rows']['data'])->keyBy(fn ($r) => $r['employee']['name'])->all();
    }

    #[Test]
    public function staff_details_shows_the_status_on_the_date_from_separation_evidence(): void
    {
        $this->travelTo('2026-08-01 06:00:00');
        $this->setUpSchool();
        $cases = app(SeparationCaseService::class);
        $approver = $this->createUserWithCapabilities($this->school, ['hr.separations.view', 'hr.separations.approve']);
        $leaver = $this->employment('Later Leaver', '2024-04-01');
        $stayer = $this->employment('Cancelled Notice', '2024-04-01');
        $this->employment('Legacy Leaver', '2020-01-01', ['ends_on' => '2026-09-30', 'status' => 'separated']);
        $this->employment('Future Joiner', '2026-11-01', ['status' => 'pre_joining']);
        $rehired = $this->createEmployee($this->school, ['full_name' => 'Rehired Person', 'employee_number' => 'N-rehire']);
        $this->createEmploymentRecord($rehired, ['starts_on' => '2020-01-01', 'ends_on' => '2023-03-31', 'status' => 'separated']);
        $this->createEmploymentRecord($rehired, ['starts_on' => '2025-01-01', 'ends_on' => null, 'status' => 'active']);

        // The leaver's notice starts on approval (2026-09-10) and the employment ends on 2026-09-30.
        $this->travelTo('2026-09-10 06:00:00');
        $case = $cases->record($this->school, $leaver->id, SeparationReason::RESIGNATION, '2026-09-10', '2026-09-30', $this->hr);
        $case = $cases->approve($this->school, $case->id, $case->version, $approver, $this->freshMfaProofFor($approver));
        // The other notice runs from 2026-09-10 and is cancelled on 2026-09-20.
        $other = $cases->record($this->school, $stayer->id, SeparationReason::RESIGNATION, '2026-09-10', '2026-10-31', $this->hr);
        $other = $cases->approve($this->school, $other->id, $other->version, $approver, $this->freshMfaProofFor($approver));
        $this->travelTo('2026-09-20 06:00:00');
        $cases->cancel($this->school, $other->id, $other->version, SeparationCancelReason::RESIGNATION_RETRACTED, $this->hr, $this->freshMfaProofFor($this->hr));
        $this->travelTo('2026-10-01 06:00:00');
        $cases->finalize($this->school, $case->id, $case->fresh()->version, $this->hr, $this->freshMfaProofFor($this->hr));
        $this->travelTo('2026-10-05 06:00:00');

        $august = $this->details('2026-08-15');
        $this->assertSame([SeparationNoticeHistory::EMPLOYED, 'Separated'], [$august['Later Leaver']['status_on_date'], $august['Later Leaver']['current_status']], 'active then, separated today: never "Separated" for August');
        $this->assertSame(SeparationNoticeHistory::EMPLOYED, $august['Cancelled Notice']['status_on_date']);
        $this->assertSame(SeparationNoticeHistory::NOT_RECORDED, $august['Legacy Leaver']['status_on_date'], 'an end older than HR.12 has no notice evidence: never guessed');
        $this->assertArrayNotHasKey('Future Joiner', $august);

        $september = $this->details('2026-09-15');
        $this->assertSame(SeparationNoticeHistory::NOTICE_PERIOD, $september['Later Leaver']['status_on_date']);
        $this->assertSame(SeparationNoticeHistory::NOTICE_PERIOD, $september['Cancelled Notice']['status_on_date']);

        $late = $this->details('2026-09-25');
        $this->assertSame(SeparationNoticeHistory::EMPLOYED, $late['Cancelled Notice']['status_on_date'], 'the cancelled notice ended the day before the cancellation');
        $this->assertSame(SeparationNoticeHistory::NOTICE_PERIOD, $late['Later Leaver']['status_on_date']);

        $now = $this->details('2026-10-05');
        $this->assertArrayNotHasKey('Later Leaver', $now, 'the employment ended on 2026-09-30');
        $this->assertSame([SeparationNoticeHistory::EMPLOYED, 'Active'], [$now['Cancelled Notice']['status_on_date'], $now['Cancelled Notice']['current_status']]);
        $this->assertSame(SeparationNoticeHistory::EMPLOYED, $now['Rehired Person']['status_on_date'], 'the current employment of a rehire');

        $past = $this->details('2022-06-01');
        $this->assertSame(SeparationNoticeHistory::NOT_RECORDED, $past['Rehired Person']['status_on_date'], 'the first, pre-HR.12 employment of the same person');
    }

    #[Test]
    public function leave_balances_are_as_posted_and_cut_at_the_school_local_day_end(): void
    {
        foreach (['Asia/Kolkata' => ['2026-10-01 18:29:59', '2026-10-01 18:30:00'], 'America/New_York' => ['2026-10-02 03:59:59', '2026-10-02 04:00:00']] as $zone => [$lastSecond, $nextDay]) {
            $this->travelTo('2026-09-01 06:00:00');
            $this->setUpSchool($zone);
            $type = $this->leaveType($this->school, $this->hr);
            $policy = $this->leavePolicy($this->school, $type, $this->hr);
            $year = $this->leaveYear($this->school, $this->hr, '2026-06-01');
            $e = $this->employment("Ledger Person {$zone}", '2024-04-01');
            app(LeavePolicyAssignmentService::class)->assign($this->school, $e->id, $policy->id, '2026-04-01', null, $this->hr);
            $ledger = app(LeaveLedgerService::class);

            $this->travelTo($lastSecond); // 23:59:59 on 2026-10-01, School-local
            $ledger->allocate($this->school, $e->id, $type->id, $year->id, 24, $this->hr, $this->freshMfaProofFor($this->hr));
            $this->travelTo($nextDay); // 00:00:00 on 2026-10-02, School-local
            $ledger->adjust($this->school, $e->id, $type->id, $year->id, 'credit', 2, 'administrative_correction', $this->hr, $this->freshMfaProofFor($this->hr));
            // A correction meant for October, posted in November: as posted, it counts from November.
            $this->travelTo('2026-11-05 06:00:00');
            $ledger->adjust($this->school, $e->id, $type->id, $year->id, 'credit', 4, 'entitlement_change', $this->hr, $this->freshMfaProofFor($this->hr));

            $balance = function (string $postedBy) use ($year): ?string {
                $this->actingAs($this->hr)->post("/app/schools/{$this->school->id}/activate");
                $rows = $this->get(route('app.hr.reports.show', ['report' => 'hr.leave_balances', 'leave_year_id' => $year->id, 'posted_by' => $postedBy], false))
                    ->assertOk()->viewData('page')['props']['rows']['data'];

                return $rows[0]['balance'] ?? null;
            };
            $this->assertSame('12', $balance('2026-10-01'), "{$zone}: the last second of the day counts, the first of the next does not");
            $this->assertSame('13', $balance('2026-10-02'), $zone);
            $this->assertSame('13', $balance('2026-10-31'), "{$zone}: the November posting is not in October, as posted");
            $this->assertSame('15', $balance('2026-11-05'), $zone);
        }
    }

    #[Test]
    public function task_overdue_turns_at_the_school_local_midnight(): void
    {
        $this->travelTo('2026-10-05 06:00:00');
        $this->setUpSchool();
        $category = app(TaskCatalogueService::class)->createCategory($this->school, 'ops', 'Operations', $this->hr);
        $e = $this->employment('Task Person', '2024-04-01');
        $this->createEmployeeAssignment($e, $this->createPosition($this->school), ['starts_on' => '2024-04-01', 'is_primary' => true]);
        app(TaskAssignmentService::class)->assign($this->school, $e->id, [
            'title' => 'Due today', 'description' => null, 'category_id' => (string) $category->id, 'completion_mode' => 'self',
            'available_on' => '2026-10-05', 'due_on' => '2026-10-05', 'section_id' => null, 'subject_offering_id' => null,
        ], $this->hr);

        $status = function (): string {
            $this->actingAs($this->hr)->post("/app/schools/{$this->school->id}/activate");

            return $this->get(route('app.hr.reports.show', ['report' => 'hr.task_occurrences', 'from' => '2026-10-01', 'to' => '2026-10-31'], false))
                ->assertOk()->viewData('page')['props']['rows']['data'][0]['status'];
        };
        $this->travelTo('2026-10-05 18:29:59'); // 23:59:59 IST, still the due date
        $this->assertSame('2026-10-05', SchoolTimezone::today($this->school));
        $this->assertSame('Assigned', $status());
        $this->travelTo('2026-10-05 18:30:00'); // 00:00:00 IST on the next day
        $this->assertSame('2026-10-06', SchoolTimezone::today($this->school));
        $this->assertSame('Assigned · overdue', $status());
    }
}
