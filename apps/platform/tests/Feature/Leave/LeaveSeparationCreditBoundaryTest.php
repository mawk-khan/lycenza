<?php

namespace Tests\Feature\Leave;

use App\Domain\HR\Application\Separation\SeparationSubject;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Leave\Application\Accrual\LeaveAccrualService;
use App\Domain\Leave\Application\LeaveLedgerService;
use App\Domain\Leave\Application\LeavePolicyAssignmentService;
use App\Domain\Leave\Application\LeavePolicyService;
use App\Domain\Leave\Application\LeaveSeparationObligations;
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
 * correcting adjustment is no longer outstanding. Nothing is reversed or
 * prorated (OD-HR-17). Leave year 2026-04-01..2027-03-31.
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

    #[Test]
    public function lva_2_only_debits_covering_the_credit_clear_it(): void
    {
        $december = (string) app(TenantContext::class)->withSchool($this->school, fn () => DB::table('leave_ledger_entries')
            ->where('employment_record_id', $this->employment->id)->where('kind', 'accrual')->where('accrual_period', '2026-12-01')->value('id'));
        $adjust = fn (string $direction, int $units) => app(LeaveLedgerService::class)->adjust($this->school, $this->employment->id, $this->type->id, $this->year->id, $direction, $units, 'administrative_correction', $this->admin, $december);

        $adjust('credit', 2);
        $this->assertSame(['attention', 1], $this->accrualFlag('2026-11-30'), 'a credit naming it corrects nothing');
        $adjust('debit', 2);
        $this->assertSame(['attention', 1], $this->accrualFlag('2026-11-30'), 'a partial debit leaves it outstanding');
        $adjust('debit', 1);
        $this->assertSame(['clear', 0], $this->accrualFlag('2026-11-30'), 'covered in full, across two adjustments');
    }
}
