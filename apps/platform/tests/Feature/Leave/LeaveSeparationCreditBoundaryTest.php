<?php

namespace Tests\Feature\Leave;

use App\Domain\HR\Application\Separation\SeparationSubject;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Leave\Application\Accrual\LeaveAccrualService;
use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Application\LeaveLedgerService;
use App\Domain\Leave\Application\LeavePolicyAssignmentService;
use App\Domain\Leave\Application\LeavePolicyService;
use App\Domain\Leave\Application\LeaveSeparationObligations;
use App\Domain\Leave\Application\LeaveYearCloseService;
use App\Domain\Leave\Infrastructure\LeaveType;
use App\Domain\Leave\Infrastructure\LeaveYear;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Leave\Concerns\CreatesLeaveFixtures;
use Tests\TestCase;

/**
 * HR.R1-C2 (HR.R1-0 LVA-2): a monthly credit is for a WHOLE month the
 * employment must span (HR.10). The separation obligation must flag every
 * credited month that does not end within the employment -- the month that
 * holds a mid-month last day included -- whatever the posting time; a month
 * ending on or before the last day is earned; a credit already named by a
 * correcting adjustment is no longer outstanding. HR.R1-C9: "corrected" is
 * the net of the adjustment chain naming the credit, through the ledger's
 * one sign convention. Nothing is reversed or prorated (OD-HR-17). Leave
 * year 2026-04-01..2027-03-31.
 */
class LeaveSeparationCreditBoundaryTest extends TestCase
{
    use CreatesLeaveFixtures;

    private School $school;

    private User $admin;

    private LeaveType $type;

    private LeaveYear $year;

    private EmploymentRecord $employment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-12-02 06:00:00');
        $this->school = $this->createSchool();
        $this->admin = $this->leaveAdmin($this->school);
        $this->type = $this->leaveType($this->school, $this->admin);
        $this->year = $this->leaveYear($this->school, $this->admin);
        $policy = app(LeavePolicyService::class)->create($this->school, $this->type->id, [
            'name' => 'Monthly', 'annual_allocation_units' => 0, 'carry_forward_allowed' => false, 'carry_forward_cap_units' => null,
            'carry_forward_expiry_days' => null, 'entitlement_method' => 'monthly', 'monthly_accrual_units' => 3, 'accrual_timing' => 'period_start',
        ], null, $this->admin, []);
        $this->employment = $this->createEmploymentRecord($this->createEmployee($this->school), ['status' => 'active', 'starts_on' => '2024-01-01', 'ends_on' => null, 'staff_type' => 'teaching']);
        app(LeavePolicyAssignmentService::class)->assign($this->school, $this->employment->id, $policy->id, '2026-04-01', null, $this->admin);
        foreach (['2026-10-01', '2026-11-01', '2026-12-01'] as $month) {
            app(LeaveAccrualService::class)->execute($this->school, $this->type->id, $this->year->id, $month, $this->admin, 1000);
        }
    }

    private function accrualFlag(string $lastDay): array
    {
        $obligations = app(TenantContext::class)->withSchool($this->school, fn () => app(LeaveSeparationObligations::class)->obligations(
            $this->school, new SeparationSubject($this->employment->id, $this->employment->employee_id, null, $lastDay, false),
        ));
        $o = collect($obligations)->firstWhere('code', 'leave_accrual_after_last_day');

        return [$o->state, $o->count];
    }

    #[Test]
    public function lva_2_the_month_holding_a_mid_month_last_day_is_flagged_and_earlier_months_are_not(): void
    {
        $this->assertSame(3, app(TenantContext::class)->withSchool($this->school, fn () => DB::table('leave_ledger_entries')->where('employment_record_id', $this->employment->id)->where('kind', 'accrual')->count()));

        // Last day 15 November: November (ends 30 Nov) and December were not wholly worked. Before HR.R1-C2 only
        // December (starting after the last day) was counted.
        $this->assertSame(['attention', 2], $this->accrualFlag('2026-11-15'));
        // Last day = the end of November: only December.
        $this->assertSame(['attention', 1], $this->accrualFlag('2026-11-30'));
        // Last day = the end of December: every credited month was wholly worked.
        $this->assertSame(['clear', 0], $this->accrualFlag('2026-12-31'));
        // The posting clock never decides. Every credit here was posted on 2 December: a last day of that same day
        // leaves only December unearned, and a backdated last day flags the credits posted after it.
        $this->assertSame(['attention', 1], $this->accrualFlag('2026-12-02'));
        $this->assertSame(['attention', 2], $this->accrualFlag('2026-10-31'));
    }

    #[Test]
    public function lva_2_a_credit_already_named_by_a_correcting_adjustment_is_no_longer_outstanding(): void
    {
        $december = (string) app(TenantContext::class)->withSchool($this->school, fn () => DB::table('leave_ledger_entries')
            ->where('employment_record_id', $this->employment->id)->where('kind', 'accrual')->where('accrual_period', '2026-12-01')->value('id'));
        app(LeaveLedgerService::class)->adjust($this->school, $this->employment->id, $this->type->id, $this->year->id, 'debit', 3, 'administrative_correction', $this->admin, $december);

        $this->assertSame(['attention', 1], $this->accrualFlag('2026-11-15'), 'November is still outstanding; December was corrected');
        $this->assertSame(3, app(TenantContext::class)->withSchool($this->school, fn () => DB::table('leave_ledger_entries')->where('employment_record_id', $this->employment->id)->where('kind', 'accrual')->count()), 'nothing reversed or deleted');
    }

    private function accrual(string $month): string
    {
        return (string) app(TenantContext::class)->withSchool($this->school, fn () => DB::table('leave_ledger_entries')
            ->where('employment_record_id', $this->employment->id)->where('kind', 'accrual')->where('accrual_period', $month)->value('id'));
    }

    private function adjust(string $direction, int $units, ?string $corrects): string
    {
        return (string) app(LeaveLedgerService::class)->adjust($this->school, $this->employment->id, $this->type->id, $this->year->id, $direction, $units, 'administrative_correction', $this->admin, $corrects)->id;
    }

    #[Test]
    public function lva_2_only_a_net_debit_covering_the_credit_clears_it(): void
    {
        // HR.R1-C9: before netting this third step read clear. A credit of 2 and debits of 3 leave a net debit of only
        // 1 against 3 units, so the expectation is corrected deliberately: the credit is still outstanding by 2.
        $december = $this->accrual('2026-12-01');

        $this->adjust('credit', 2, $december);
        $this->assertSame(['attention', 1], $this->accrualFlag('2026-11-30'), 'a credit naming it corrects nothing');
        $this->adjust('debit', 2, $december);
        $this->assertSame(['attention', 1], $this->accrualFlag('2026-11-30'), 'a partial debit leaves it outstanding');
        $this->adjust('debit', 1, $december);
        $this->assertSame(['attention', 1], $this->accrualFlag('2026-11-30'), 'the credit naming it still counts');
        $this->adjust('debit', 2, $december);
        $this->assertSame(['clear', 0], $this->accrualFlag('2026-11-30'), 'covered in full, net, across the adjustments');
    }

    #[Test]
    public function a_later_credit_naming_the_same_accrual_reopens_it(): void
    {
        $december = $this->accrual('2026-12-01');

        $this->adjust('debit', 3, $december);
        $this->assertSame(['clear', 0], $this->accrualFlag('2026-11-30'));

        $this->adjust('credit', 3, $december);
        $this->assertSame(['attention', 1], $this->accrualFlag('2026-11-30'), 'a debit then a credit naming the same accrual: the reproduction of the registered finding');
    }

    #[Test]
    public function a_correction_of_the_correction_counts_through_the_chain(): void
    {
        $december = $this->accrual('2026-12-01');
        $debit = $this->adjust('debit', 3, $december);
        $this->assertSame(['clear', 0], $this->accrualFlag('2026-11-30'));

        $credit = $this->adjust('credit', 3, $debit);
        $this->assertSame(['attention', 1], $this->accrualFlag('2026-11-30'), 'undoing the debit names the debit, never the accrual');

        $this->adjust('debit', 3, $credit);
        $this->assertSame(['clear', 0], $this->accrualFlag('2026-11-30'));
    }

    #[Test]
    public function an_unrelated_debit_clears_nothing_however_equal(): void
    {
        $this->adjust('debit', 3, null);
        $this->assertSame(['attention', 1], $this->accrualFlag('2026-11-30'), 'no provenance, no correction');

        $this->adjust('debit', 3, $this->accrual('2026-10-01'));
        $this->assertSame(['attention', 1], $this->accrualFlag('2026-11-30'), 'naming another month corrects that month only');
    }

    #[Test]
    public function an_adjustment_posted_after_the_separation_counts_and_posting_time_never_decides(): void
    {
        $this->assertSame(['attention', 2], $this->accrualFlag('2026-10-31'), 'a backdated last day');

        $this->travelTo('2026-12-20 06:00:00');
        $this->adjust('debit', 3, $this->accrual('2026-11-01'));

        $this->assertSame(['attention', 1], $this->accrualFlag('2026-10-31'), 'December stays outstanding');
    }

    #[Test]
    public function reading_it_again_changes_nothing_and_writes_nothing(): void
    {
        $count = fn (): int => app(TenantContext::class)->withSchool($this->school, fn () => DB::table('leave_ledger_entries')->where('employment_record_id', $this->employment->id)->count());
        $before = $count();

        $first = $this->accrualFlag('2026-10-31');

        $this->assertSame($first, $this->accrualFlag('2026-10-31'));
        $this->assertSame($before, $count());
    }

    #[Test]
    public function a_closed_year_keeps_its_unearned_credit_visible_and_refuses_the_correction(): void
    {
        // R-HR10-1 stays open: a closed year cannot be corrected, and the flag must not pretend otherwise.
        $december = $this->accrual('2026-12-01');
        $this->travelTo('2027-04-10 06:00:00');
        $this->leaveYear($this->school, $this->admin, '2027-05-01');
        app(LeaveYearCloseService::class)->execute($this->school, $this->year->id, $this->admin);

        try {
            $this->adjust('debit', 3, $december);
            $this->fail('A closed year is never corrected in place.');
        } catch (LeaveException $e) {
            $this->assertSame('LEAVE_YEAR_CLOSED', $e->errorCode());
        }
        $this->assertSame(['attention', 1], $this->accrualFlag('2026-11-30'));
    }

    #[Test]
    public function a_correction_never_names_an_entry_of_another_account(): void
    {
        $other = $this->createEmploymentRecord($this->createEmployee($this->school), ['status' => 'active', 'starts_on' => '2024-01-01', 'ends_on' => null, 'staff_type' => 'teaching']);
        $policy = app(TenantContext::class)->withSchool($this->school, fn () => DB::table('leave_policy_assignments')->where('employment_record_id', $this->employment->id)->value('leave_policy_id'));
        app(LeavePolicyAssignmentService::class)->assign($this->school, $other->id, $policy, '2026-04-01', null, $this->admin);
        app(LeaveAccrualService::class)->execute($this->school, $this->type->id, $this->year->id, '2026-12-01', $this->admin, 1000);

        try {
            app(LeaveLedgerService::class)->adjust($this->school, $other->id, $this->type->id, $this->year->id, 'debit', 3, 'administrative_correction', $this->admin, $this->accrual('2026-12-01'));
            $this->fail('A correction stays inside its account.');
        } catch (LeaveException $e) {
            $this->assertSame('LEAVE_ADJUSTMENT_SOURCE_INVALID', $e->errorCode());
        }
    }
}
